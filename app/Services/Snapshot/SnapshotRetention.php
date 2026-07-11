<?php

namespace App\Services\Snapshot;

use Illuminate\Support\Facades\Log;

/**
 * Local snapshot FIFO retention under /opt/pbx3/snap/.
 * Mirrors LocalBackupRetention (keep newest N).
 */
class SnapshotRetention
{
    private const SNAP_DIR = '/opt/pbx3/snap';

    /** Matches SnapShotController::index / create_new_snapshot naming. */
    private const SNAP_PATTERN = '/^(?:pbx3|sqlite)\.db\.(\d+)$/';

    /**
     * @return list<string> basenames removed
     */
    public function pruneExcess(?int $maxCount = null): array
    {
        $maxCount ??= (int) config('pbx3_directory.snapshot_max_count', 9);
        if ($maxCount < 1) {
            return [];
        }

        $files = $this->listNewestFirst();
        if (count($files) <= $maxCount) {
            return [];
        }

        $toDelete = array_slice($files, $maxCount);
        $removed = [];

        foreach ($toDelete as $basename) {
            if ($this->deleteLocalFile($basename)) {
                $removed[] = $basename;
            }
        }

        if ($removed !== []) {
            Log::info('snapshot retention pruned old snaps', [
                'keep' => $maxCount,
                'removed' => $removed,
            ]);
        }

        return $removed;
    }

    /**
     * @return list<string> basenames newest first
     */
    public function listNewestFirst(): array
    {
        if (! is_dir(self::SNAP_DIR)) {
            return [];
        }

        $files = [];
        $handle = opendir(self::SNAP_DIR);
        if ($handle === false) {
            return [];
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match(self::SNAP_PATTERN, $entry) === 1) {
                $files[] = $entry;
            }
        }
        closedir($handle);

        usort($files, static function (string $a, string $b): int {
            preg_match(self::SNAP_PATTERN, $a, $ma);
            preg_match(self::SNAP_PATTERN, $b, $mb);

            return (int) ($mb[1] ?? 0) <=> (int) ($ma[1] ?? 0);
        });

        return $files;
    }

    private function deleteLocalFile(string $basename): bool
    {
        $path = self::SNAP_DIR.'/'.$basename;
        if (! is_file($path)) {
            return false;
        }

        [$response, $error] = pbx3_request_syscmd('/bin/rm -f '.escapeshellarg($path));
        if ($error !== null) {
            Log::warning('snapshot retention: failed to delete', [
                'file' => $basename,
                'error' => $error,
            ]);

            return false;
        }

        return ! is_file($path);
    }
}
