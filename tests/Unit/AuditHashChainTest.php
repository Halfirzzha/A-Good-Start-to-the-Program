<?php

namespace Tests\Unit;

use App\Support\AuditHasher;
use App\Support\AuditLogWriter;
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
}
