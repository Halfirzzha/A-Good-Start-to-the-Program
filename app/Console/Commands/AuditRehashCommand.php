<?php

namespace App\Console\Commands;

use App\Support\AuditHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditRehashCommand extends Command
{
    protected $signature = 'audit:rehash {--from-id= : Start rehashing from an audit log ID} {--chunk= : Chunk size for rehashing} {--dry-run : Show changes without writing}';

    protected $description = 'Rebuild audit log hash chain values.';

    public function handle(): int
    {
        if (! Schema::hasColumns('audit_logs', ['hash', 'previous_hash'])) {
            $this->error('audit_logs is missing hash columns. Run the audit hash migration first.');

            return self::FAILURE;
        }

        $signatureEnabled = (bool) config('audit.signature_enabled', false);
        $signatureSecret = (string) config('audit.signature_secret', '');
        $signatureEnabled = $signatureEnabled && $signatureSecret !== '' && Schema::hasColumn('audit_logs', 'signature');

        $fromId = $this->option('from-id');
        $fromId = is_numeric($fromId) ? (int) $fromId : null;
        $chunk = (int) ($this->option('chunk') ?: config('audit.rehash_chunk', 500));
        if ($chunk <= 0) {
            $chunk = 500;
        }

        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('audit_logs')->orderBy('id');
        if ($fromId) {
            $query->where('id', '>=', $fromId);
        }

        $previousHash = null;
        if ($fromId) {
            $previousHash = DB::table('audit_logs')
                ->where('id', '<', $fromId)
                ->orderByDesc('id')
                ->value('hash');
        }

        $total = 0;
        $updated = 0;

        $query->chunkById($chunk, function ($rows) use (&$previousHash, &$total, &$updated, $dryRun, $signatureEnabled): void {
            foreach ($rows as $row) {
                $total++;

                $data = (array) $row;
                $normalized = AuditHasher::normalize($data);
                $expected = AuditHasher::hash($normalized, $previousHash);
                $expectedSignature = $signatureEnabled ? AuditHasher::signature($expected) : null;

                $needsUpdate = ($row->hash ?? null) !== $expected
                    || ($row->previous_hash ?? null) !== $previousHash
                    || ($signatureEnabled && ($row->signature ?? null) !== $expectedSignature);

                if ($needsUpdate) {
                    $updated++;

                    if (! $dryRun) {
                        DB::table('audit_logs')
                            ->where('id', $row->id)
                            ->update([
                                'hash' => $expected,
                                'previous_hash' => $previousHash,
                                'signature' => $expectedSignature,
                            ]);
                    }
                }

                $previousHash = $expected;
            }
        });

        if ($dryRun) {
            $this->warn('Dry run enabled. No records were updated.');
        }

        $this->info("Processed {$total} audit log entries.");
        $this->info("Updated {$updated} audit log entries.");

        return self::SUCCESS;
    }
}
