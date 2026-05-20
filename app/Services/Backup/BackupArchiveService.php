<?php

namespace App\Services\Backup;

use App\Models\Sysglobal;
use App\Services\Directory\InstanceBackupDirectoryUpload;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * S3 archive access for instance backups (presigned download, rehydrate to bkup/).
 */
class BackupArchiveService
{
    private const STAMP_PATTERN = '/^\d{8}T\d{6}Z$/';

    private const BKUP_DIR = '/opt/pbx3/bkup';

    public function __construct(
        private InstanceBackupDirectoryUpload $directoryUpload,
    ) {}

    public function isAvailable(): bool
    {
        return $this->directoryUpload->isConfigured();
    }

    /**
     * @return array{url: string, expires_at: string, filename: string, backup_stamp: string}
     */
    public function presignedDownloadUrl(string $backupStamp): array
    {
        $zipKey = $this->resolveZipKey($backupStamp);
        $disk = Storage::disk('pbx3_org');

        if (! $disk->exists($zipKey)) {
            throw new \RuntimeException("Archive backup.zip not found for stamp {$backupStamp}");
        }

        $ttlMinutes = max(1, (int) config('pbx3_directory.backup_presigned_ttl_minutes', 15));
        $expires = now()->addMinutes($ttlMinutes);
        $url = $disk->temporaryUrl($zipKey, $expires);
        $filename = $this->localFilenameForStamp($backupStamp);

        $user = auth()->user();
        Log::info('backup archive presigned download issued', [
            'backup_stamp' => $backupStamp,
            'key' => $zipKey,
            'expires_at' => $expires->toIso8601String(),
            'user_id' => $user?->id,
            'user_email' => $user?->email,
        ]);

        return [
            'url' => $url,
            'expires_at' => $expires->toIso8601String(),
            'filename' => $filename,
            'backup_stamp' => $backupStamp,
        ];
    }

    /**
     * Download backup.zip from S3 to /opt/pbx3/bkup/pbx3bak.{epoch}.zip.
     */
    public function rehydrateToLocal(string $backupStamp): string
    {
        $zipKey = $this->resolveZipKey($backupStamp);
        $disk = Storage::disk('pbx3_org');

        if (! $disk->exists($zipKey)) {
            throw new \RuntimeException("Archive backup.zip not found for stamp {$backupStamp}");
        }

        if (! is_dir(self::BKUP_DIR) && ! mkdir(self::BKUP_DIR, 0755, true) && ! is_dir(self::BKUP_DIR)) {
            throw new \RuntimeException('Cannot create backup directory');
        }

        $localFilename = $this->localFilenameForStamp($backupStamp);
        $localPath = self::BKUP_DIR.'/'.$localFilename;
        $tmpPath = $localPath.'.rehydrate.tmp';

        $stream = $disk->readStream($zipKey);
        if ($stream === false) {
            throw new \RuntimeException('Failed to read archive from S3');
        }

        $out = fopen($tmpPath, 'wb');
        if ($out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException('Cannot write local backup file');
        }

        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            fclose($out);
        }

        if (! rename($tmpPath, $localPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Failed to finalize rehydrated backup');
        }

        $user = auth()->user();
        Log::info('backup archive rehydrated to local', [
            'backup_stamp' => $backupStamp,
            'local_file' => $localFilename,
            'bytes' => filesize($localPath) ?: 0,
            'user_id' => $user?->id,
            'user_email' => $user?->email,
        ]);

        return $localFilename;
    }

    public function assertValidStamp(string $backupStamp): void
    {
        if (preg_match(self::STAMP_PATTERN, $backupStamp) !== 1) {
            throw new \InvalidArgumentException('Invalid backup_stamp format');
        }
    }

    private function resolveZipKey(string $backupStamp): string
    {
        $this->assertValidStamp($backupStamp);

        $instanceId = Sysglobal::query()->where('pkey', 'global')->value('id');
        if (! is_string($instanceId) || $instanceId === '') {
            throw new \RuntimeException('Instance KSUID not configured on this node');
        }

        return "instances/{$instanceId}/backups/{$backupStamp}/backup.zip";
    }

    private function localFilenameForStamp(string $backupStamp): string
    {
        $epoch = $this->epochFromStamp($backupStamp);

        return 'pbx3bak.'.$epoch.'.zip';
    }

    private function epochFromStamp(string $stamp): int
    {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $stamp, new DateTimeZone('UTC'));

        if (! $dt instanceof DateTimeImmutable) {
            throw new \InvalidArgumentException('Invalid backup_stamp');
        }

        return $dt->getTimestamp();
    }
}
