<?php

namespace App\Services\Cdr;

use App\Services\Directory\LogRetentionService;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6: prune Asterisk master.db rows older than effective local_days.cdr.
 * Cold history remains on S3 CSV.
 */
class CdrRetentionService
{
    public function __construct(
        private CdrIndexService $index,
        private LogRetentionService $retention,
    ) {}

    /**
     * @return array{available: bool, path: string, local_days: int, deleted: int, cutoff: string|null}
     */
    public function prune(?int $localDays = null): array
    {
        $path = $this->index->path();
        $days = $localDays ?? (int) ($this->retention->effectiveLocalDays()['cdr'] ?? 7);
        $days = max(1, min(365, $days));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        if (! $this->index->isAvailable()) {
            return [
                'available' => false,
                'path' => $path,
                'local_days' => $days,
                'deleted' => 0,
                'cutoff' => $cutoff,
            ];
        }

        try {
            $this->index->ensureIndexes();
            $pdo = $this->index->openReadWrite();
            $stmt = $pdo->prepare('DELETE FROM cdr WHERE calldate < :cutoff');
            $stmt->execute(['cutoff' => $cutoff]);
            $deleted = $stmt->rowCount();
            try {
                $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (\Throwable) {
            }

            return [
                'available' => true,
                'path' => $path,
                'local_days' => $days,
                'deleted' => $deleted,
                'cutoff' => $cutoff,
            ];
        } catch (\Throwable $e) {
            Log::warning('cdr prune failed', ['error' => $e->getMessage(), 'path' => $path]);
            throw $e;
        }
    }
}
