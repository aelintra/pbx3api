<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;

/**
 * Local backup FIFO retention (DESIGN_RULES option C — local leg only).
 * Does not delete S3 objects when evicting old zips.
 */
class LocalBackupRetention
{
    private const BKUP_DIR = '/opt/pbx3/bkup';

    private const BACKUP_PATTERN = '/^pbx3bak\.(\d+)\.zip$/';

    /**
     * @return list<string> basenames removed
     */
    public function pruneExcess(?int $maxCount = null): array
    {
        $maxCount ??= (int) config('pbx3_directory.local_max_count', 9);
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
            Log::info('local backup retention pruned old zips', [
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
        if (! is_dir(self::BKUP_DIR)) {
            return [];
        }

        $files = [];
        $handle = opendir(self::BKUP_DIR);
        if ($handle === false) {
            return [];
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match(self::BACKUP_PATTERN, $entry) === 1) {
                $files[] = $entry;
            }
        }
        closedir($handle);

        usort($files, static function (string $a, string $b): int {
            preg_match(self::BACKUP_PATTERN, $a, $ma);
            preg_match(self::BACKUP_PATTERN, $b, $mb);

            return (int) ($mb[1] ?? 0) <=> (int) ($ma[1] ?? 0);
        });

        return $files;
    }

    private function deleteLocalFile(string $basename): bool
    {
        $path = self::BKUP_DIR.'/'.$basename;
        if (! is_file($path)) {
            return false;
        }

        [$response, $error] = pbx3_request_syscmd('/bin/rm -f '.escapeshellarg($path));
        if ($error !== null) {
            Log::warning('local backup retention: failed to delete', [
                'file' => $basename,
                'error' => $error,
            ]);

            return false;
        }

        return ! is_file($path);
    }
}
