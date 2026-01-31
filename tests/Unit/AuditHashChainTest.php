<?php

namespace Tests\Unit;

use App\Support\AuditHasher;
use App\Support\AuditLogWriter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditHashChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AuditLogWriter::resetSchemaCache();
        DB::table('audit_logs')->truncate();
        config([
            'audit.signature_enabled' => false,
            'audit.signature_secret' => '',
            'audit.signature_strict' => false,
        ]);
    }

    public function test_audit_hash_chain_is_consistent(): void
    {
        AuditLogWriter::writeAudit([
            'action' => 'test_event_one',
            'context' => ['source' => 'unit_test'],
            'created_at' => now()->subMinute(),
        ]);

        AuditLogWriter::writeAudit([
            'action' => 'test_event_two',
            'context' => ['source' => 'unit_test'],
            'created_at' => now(),
        ]);

        $rows = DB::table('audit_logs')->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $previousHash = null;
        foreach ($rows as $row) {
            $data = (array) $row;
            $expected = AuditHasher::hash(AuditHasher::normalize($data), $previousHash);

            $this->assertSame($expected, $row->hash);
            $this->assertSame($previousHash, $row->previous_hash);

            $previousHash = $row->hash;
        }
    }

    public function test_audit_signature_is_recorded_when_enabled(): void
    {
        config([
            'audit.signature_enabled' => true,
            'audit.signature_secret' => 'unit-test-secret',
        ]);

        // Reset schema cache to pick up new config
        AuditLogWriter::resetSchemaCache();

        AuditLogWriter::writeAudit([
            'action' => 'test_signature',
            'context' => ['source' => 'unit_test'],
            'created_at' => now(),
        ]);

        $row = DB::table('audit_logs')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        $expectedSignature = AuditHasher::signature((string) $row->hash);
        $this->assertSame($expectedSignature, $row->signature);
    }

    public function test_signature_failure_is_flagged_when_enabled_without_secret(): void
    {
        config([
            'audit.signature_enabled' => true,
            'audit.signature_secret' => '',
            'audit.signature_strict' => false,
        ]);

        AuditLogWriter::resetSchemaCache();

        $this->assertTrue((bool) config('audit.signature_enabled', false));
        $this->assertFalse((bool) config('audit.signature_strict', true));

        AuditLogWriter::writeAudit([
            'action' => 'test_signature_failure',
            'context' => ['source' => 'unit_test'],
            'created_at' => now(),
        ]);

        $row = DB::table('audit_logs')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->signature);

        $context = $this->decodeJson($row->context);
        $this->assertIsArray($context);
        $this->assertTrue($context['signature_failed'] ?? false);
    }

    public function test_signature_strict_throws_when_signature_fails(): void
    {
        config([
            'audit.signature_enabled' => true,
            'audit.signature_secret' => '',
            'audit.signature_strict' => true,
        ]);

        AuditLogWriter::resetSchemaCache();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audit signature failed');

        AuditLogWriter::writeAudit([
            'action' => 'test_signature_strict',
            'context' => ['source' => 'unit_test'],
            'created_at' => now(),
        ]);
    }

    public function test_sensitive_values_are_redacted_and_masked(): void
    {
        AuditLogWriter::writeAudit([
            'action' => 'test_redaction',
            'old_values' => [
                'password' => 'plain-secret',
                'current_password' => 'another-secret',
                'email' => 'john.doe@example.com',
                'phone' => '+62 812-3456-7890',
                'address' => '123 Main Street, Jakarta',
            ],
            'new_values' => [
                'email' => 'jane.doe@example.com',
                'phone_number' => '08123456789',
                'address' => '456 Secondary Road, Bandung',
            ],
            'context' => ['source' => 'unit_test'],
            'created_at' => now(),
        ]);

        $row = DB::table('audit_logs')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        $oldValues = $this->decodeJson($row->old_values);
        $newValues = $this->decodeJson($row->new_values);

        $this->assertSame('[REDACTED]', $oldValues['password'] ?? null);
        $this->assertSame('[REDACTED]', $oldValues['current_password'] ?? null);
        $this->assertTrue($this->looksMaskedEmail($oldValues['email'] ?? null));
        $this->assertTrue($this->looksMaskedPhone($oldValues['phone'] ?? null));
        $this->assertNotSame('123 Main Street, Jakarta', $oldValues['address'] ?? null);

        $this->assertTrue($this->looksMaskedEmail($newValues['email'] ?? null));
        $this->assertTrue($this->looksMaskedPhone($newValues['phone_number'] ?? null));
        $this->assertNotSame('456 Secondary Road, Bandung', $newValues['address'] ?? null);
    }

    public function test_session_revoke_audit_includes_reason_and_count(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($actor);

        $target->rotateSecurityStamp('admin_action', 3);

        $row = DB::table('audit_logs')
            ->where('action', 'session_all_revoked')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($actor->id, $row->user_id);
        $this->assertSame($target->id, $row->auditable_id);

        $newValues = $this->decodeJson($row->new_values);
        $context = $this->decodeJson($row->context);

        $this->assertSame(3, $newValues['revoked_count'] ?? null);
        $this->assertSame('admin_action', $context['reason'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function looksMaskedEmail(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        if (! str_contains($value, '@')) {
            return false;
        }

        return str_contains($value, '*');
    }

    private function looksMaskedPhone(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return str_contains($value, '*');
    }
}
