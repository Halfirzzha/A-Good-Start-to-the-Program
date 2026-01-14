<?php

namespace App\Console\Commands;

use App\Support\AuditHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditVerifyCommand extends Command
{
    protected $signature = 'audit:verify {--from-id= : Start verification from an audit log ID} {--chunk= : Chunk size for verification}';

    protected $description = 'Verify the audit log hash chain integrity.';

    public function handle(): int
    {
        if (! Schema::hasColumns('audit_logs', ['hash', 'previous_hash'])) {
            $this->error('audit_logs is missing hash columns. Run the audit hash migration first.');

            return self::FAILURE;
        }

        $signatureEnabled = (bool) config('audit.signature_enabled', false);
        $signatureSecret = (string) config('audit.signature_secret', '');
        $signatureEnabled = $signatureEnabled && $signatureSecret !== '';
        if ($signatureEnabled && ! Schema::hasColumn('audit_logs', 'signature')) {
            $this->error('audit_logs is missing signature column. Run the audit signature migration first.');

            return self::FAILURE;
        }

        $fromId = $this->option('from-id');
        $fromId = is_numeric($fromId) ? (int) $fromId : null;
        $chunk = (int) ($this->option('chunk') ?: config('audit.verify_chunk', 500));
        if ($chunk <= 0) {
            $chunk = 500;
        }

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
        $mismatches = 0;
        $missingHashes = 0;

        $query->chunkById($chunk, function ($rows) use (&$previousHash, &$total, &$mismatches, &$missingHashes, $signatureEnabled): void {
            foreach ($rows as $row) {
                $total++;

                if ($row->hash === null) {
                    $missingHashes++;
                }

                $data = (array) $row;
                $normalized = AuditHasher::normalize($data);
                $expected = AuditHasher::hash($normalized, $previousHash);

                if (($row->previous_hash ?? null) !== $previousHash) {
                    $mismatches++;
                    $this->warn("Previous hash mismatch at ID {$row->id}.");
                }

                if (($row->hash ?? null) !== $expected) {
                    $mismatches++;
                    $this->warn("Hash mismatch at ID {$row->id}.");
                }

                if ($signatureEnabled) {
                    $expectedSignature = AuditHasher::signature($expected);
                    if (($row->signature ?? null) !== $expectedSignature) {
                        $mismatches++;
                        $this->warn("Signature mismatch at ID {$row->id}.");
                    }
                }

                $previousHash = $row->hash;
            }
        });

        $this->info("Verified {$total} audit log entries.");

        if ($missingHashes > 0) {
            $this->warn("{$missingHashes} entries are missing hashes. Run audit:rehash to seal them.");
        }

        if ($mismatches > 0 || $missingHashes > 0) {
            $this->error('Audit hash verification failed.');

            return self::FAILURE;
        }

        $this->info('Audit hash chain is valid.');

        return self::SUCCESS;
    }
}
