<?php

namespace App\Services\Backup;

use App\Models\Sysglobal;
use App\Services\Directory\InstanceBackupDirectoryUpload;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Merged backup index: local /opt/pbx3/bkup zips + S3 instances/{ksuid}/backups/{stamp}/ (S5.1).
 */
class BackupIndexService
{
    private const BKUP_DIR = '/opt/pbx3/bkup';

    private const LOCAL_PATTERN = '/^pbx3bak\.(\d+)\.zip$/';

    private const STAMP_PATTERN = '/^\d{8}T\d{6}Z$/';

    /**
     * @return array<string, array<string, mixed>> keyed by canonical filename (pbx3bak.{epoch}.zip) or archive:{stamp}
     */
    public function mergedIndex(): array
    {
        $rows = [];

        foreach ($this->listLocalEntries() as $filename => $entry) {
            $epoch = (int) $entry['epoch'];
            $rows[$this->canonicalFilename($epoch)] = array_merge($entry, [
                'source' => 'local',
                'local_file' => $filename,
                'has_local' => true,
                'has_s3' => false,
            ]);
        }

        foreach ($this->listS3Entries() as $stamp => $entry) {
            $epoch = (int) $entry['epoch'];
            $key = $epoch > 0 ? $this->canonicalFilename($epoch) : "archive:{$stamp}";

            if (isset($rows[$key])) {
                $rows[$key]['source'] = 'both';
                $rows[$key]['has_s3'] = true;
                $rows[$key]['backup_stamp'] = $stamp;
                if (empty($rows[$key]['filesize']) && ! empty($entry['filesize'])) {
                    $rows[$key]['filesize'] = $entry['filesize'];
                }
                if (empty($rows[$key]['created_at']) && ! empty($entry['created_at'])) {
                    $rows[$key]['created_at'] = $entry['created_at'];
                }

                continue;
            }

            $filename = $epoch > 0 ? $this->canonicalFilename($epoch) : $key;
            $rows[$key] = array_merge($entry, [
                'source' => 's3',
                'local_file' => null,
                'has_local' => false,
                'has_s3' => true,
                'backup_stamp' => $stamp,
                '_display_filename' => $filename,
            ]);
        }

        uasort($rows, static fn (array $a, array $b): int => ((int) ($b['epoch'] ?? 0)) <=> ((int) ($a['epoch'] ?? 0)));

        $keyed = [];
        foreach ($rows as $key => $row) {
            $responseKey = $row['_display_filename'] ?? $key;
            unset($row['_display_filename']);
            $keyed[$responseKey] = $row;
        }

        return $keyed;
    }

    /**
     * @return array<string, array{epoch: int, filesize: int, date: string, created_at: string, backup_stamp: string}>
     */
    private function listLocalEntries(): array
    {
        $entries = [];
        if (! is_dir(self::BKUP_DIR)) {
            return $entries;
        }

        $handle = opendir(self::BKUP_DIR);
        if ($handle === false) {
            return $entries;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match(self::LOCAL_PATTERN, $entry, $m) !== 1) {
                continue;
            }
            $epoch = (int) $m[1];
            $path = self::BKUP_DIR.'/'.$entry;
            $entries[$entry] = $this->rowFromEpoch($epoch, is_file($path) ? (int) filesize($path) : 0);
        }
        closedir($handle);

        return $entries;
    }

    /**
     * @return array<string, array{epoch: int, filesize: int, date: string, created_at: string, backup_stamp: string}>
     */
    private function listS3Entries(): array
    {
        if (! app(InstanceBackupDirectoryUpload::class)->isConfigured()) {
            return [];
        }

        $instanceId = Sysglobal::query()->where('pkey', 'global')->value('id');
        if (! is_string($instanceId) || $instanceId === '') {
            return [];
        }

        try {
            $disk = Storage::disk('pbx3_org');
        } catch (\Throwable $e) {
            Log::warning('backup index: S3 disk unavailable', ['error' => $e->getMessage()]);

            return [];
        }

        $prefix = "instances/{$instanceId}/backups";
        $entries = [];

        try {
            $dirs = $disk->directories($prefix);
        } catch (\Throwable $e) {
            Log::warning('backup index: failed to list S3 backup prefixes', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        foreach ($dirs as $dir) {
            $stamp = basename($dir);
            if (preg_match(self::STAMP_PATTERN, $stamp) !== 1) {
                continue;
            }

            $manifestKey = "{$dir}/manifest.json";
            $zipKey = "{$dir}/backup.zip";
            if (! $disk->exists($manifestKey) && ! $disk->exists($zipKey)) {
                continue;
            }

            $epoch = $this->epochFromStamp($stamp);
            $filesize = 0;
            $createdAt = gmdate('Y-m-d\TH:i:s\Z', $epoch > 0 ? $epoch : time());

            if ($disk->exists($manifestKey)) {
                $decoded = json_decode((string) $disk->get($manifestKey), true);
                if (is_array($decoded)) {
                    if (! empty($decoded['created_at']) && is_string($decoded['created_at'])) {
                        $createdAt = $decoded['created_at'];
                        $parsed = strtotime($decoded['created_at']);
                        if ($parsed !== false && $epoch <= 0) {
                            $epoch = $parsed;
                        }
                    }
                    $artifacts = $decoded['artifacts'][0] ?? null;
                    if (is_array($artifacts) && isset($artifacts['bytes'])) {
                        $filesize = (int) $artifacts['bytes'];
                    }
                }
            }

            if ($filesize === 0 && $disk->exists($zipKey)) {
                try {
                    $filesize = (int) $disk->size($zipKey);
                } catch (\Throwable) {
                    $filesize = 0;
                }
            }

            if ($epoch <= 0) {
                $epoch = $this->epochFromStamp($stamp);
            }

            $entries[$stamp] = array_merge($this->rowFromEpoch($epoch > 0 ? $epoch : 0, $filesize), [
                'created_at' => $createdAt,
                'backup_stamp' => $stamp,
            ]);
        }

        return $entries;
    }

    /**
     * @return array{epoch: int, filesize: int, date: string, created_at: string, backup_stamp: string}
     */
    private function rowFromEpoch(int $epoch, int $filesize): array
    {
        $effectiveEpoch = $epoch > 0 ? $epoch : 0;

        return [
            'epoch' => $effectiveEpoch,
            'filesize' => $filesize,
            'date' => $effectiveEpoch > 0 ? date('D d M H:i:s Y', $effectiveEpoch) : '',
            'created_at' => $effectiveEpoch > 0 ? gmdate('Y-m-d\TH:i:s\Z', $effectiveEpoch) : '',
            'backup_stamp' => $effectiveEpoch > 0 ? gmdate('Ymd\THis\Z', $effectiveEpoch) : '',
        ];
    }

    private function canonicalFilename(int $epoch): string
    {
        return 'pbx3bak.'.$epoch.'.zip';
    }

    private function epochFromStamp(string $stamp): int
    {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $stamp, new DateTimeZone('UTC'));

        return $dt instanceof DateTimeImmutable ? $dt->getTimestamp() : 0;
    }
}
