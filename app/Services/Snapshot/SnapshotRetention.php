<?php

namespace App\Services\Snapshot;

use Illuminate\Support\Facades\Log;

/**
 * Local snapshot FIFO retention under /opt/pbx3/snap/.
 * Mirrors LocalBackupRetention (keep newest N).
 */
class SnapshotRetention
{
    private const DEFAULT_SNAP_DIR = '/opt/pbx3/snap';

    /** Matches SnapShotController::index / create_new_snapshot naming. */
    private const SNAP_PATTERN = '/^(?:pbx3|sqlite)\.db\.(\d+)$/';

    private string $snapDir;

    /** @var null|callable(string): bool */
    private $deleteCallback;

    /**
     * @param  null|callable(string): bool  $deleteCallback  receives basename; return true if removed
     */
    public function __construct(?string $snapDir = null, ?callable $deleteCallback = null)
    {
        $this->snapDir = $snapDir ?? self::DEFAULT_SNAP_DIR;
        $this->deleteCallback = $deleteCallback;
    }

    /**
     * Pure sort+slice of snapshot basenames (newest first naming: …db.N).
     *
     * @param  list<string>  $basenames
     * @return array{keep: list<string>, remove: list<string>}
     */
    public static function planPrune(array $basenames, int $maxCount): array
    {
        if ($maxCount < 1) {
            return ['keep' => [], 'remove' => []];
        }

        $files = array_values(array_filter(
            $basenames,
            static fn (string $entry): bool => preg_match(self::SNAP_PATTERN, $entry) === 1
        ));

        usort($files, static function (string $a, string $b): int {
            preg_match(self::SNAP_PATTERN, $a, $ma);
            preg_match(self::SNAP_PATTERN, $b, $mb);

            return (int) ($mb[1] ?? 0) <=> (int) ($ma[1] ?? 0);
        });

        if (count($files) <= $maxCount) {
            return ['keep' => $files, 'remove' => []];
        }

        return [
            'keep' => array_slice($files, 0, $maxCount),
            'remove' => array_slice($files, $maxCount),
        ];
    }

    /**
     * @return list<string> basenames removed
     */
    public function pruneExcess(?int $maxCount = null): array
    {
        $maxCount ??= (int) config('pbx3_directory.snapshot_max_count', 9);
        if ($maxCount < 1) {
            return [];
        }

        $plan = self::planPrune($this->listNewestFirst(), $maxCount);
        if ($plan['remove'] === []) {
            return [];
        }

        $removed = [];

        foreach ($plan['remove'] as $basename) {
            if ($this->deleteLocalFile($basename)) {
                $removed[] = $basename;
            }
        }

        if ($removed !== []) {
            $this->logInfo('snapshot retention pruned old snaps', [
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
        if (! is_dir($this->snapDir)) {
            return [];
        }

        $files = [];
        $handle = opendir($this->snapDir);
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

        return self::planPrune($files, PHP_INT_MAX)['keep'];
    }

    private function deleteLocalFile(string $basename): bool
    {
        if ($this->deleteCallback !== null) {
            return (bool) ($this->deleteCallback)($basename);
        }

        $path = $this->snapDir.'/'.$basename;
        if (! is_file($path)) {
            return false;
        }

        [$response, $error] = pbx3_request_syscmd('/bin/rm -f '.escapeshellarg($path));
        if ($error !== null) {
            $this->logWarning('snapshot retention: failed to delete', [
                'file' => $basename,
                'error' => $error,
            ]);

            return false;
        }

        return ! is_file($path);
    }

    private function logInfo(string $message, array $context = []): void
    {
        try {
            Log::info($message, $context);
        } catch (\Throwable) {
            // Offline unit tests may run without a Laravel container.
        }
    }

    private function logWarning(string $message, array $context = []): void
    {
        try {
            Log::warning($message, $context);
        } catch (\Throwable) {
            // Offline unit tests may run without a Laravel container.
        }
    }
}
