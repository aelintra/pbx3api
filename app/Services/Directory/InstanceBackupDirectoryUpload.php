<?php

namespace App\Services\Directory;

use App\Models\Sysglobal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Phase 4: copy a local /opt/pbx3/bkup zip to org S3 (instances/{ksuid}/backups/…).
 */
class InstanceBackupDirectoryUpload
{
    /** S3 object tag for lifecycle rules (expire tagged backup packages only). */
    private const S3_TAGGING = 'class=backup';

    public function isConfigured(): bool
    {
        if (! config('pbx3_directory.backup_upload_enabled')) {
            return false;
        }
        $bucket = config('pbx3_directory.org_bucket');

        return is_string($bucket) && trim($bucket) !== '';
    }

    /**
     * @param  string  $backupFilename  e.g. pbx3bak.1716123456.zip (basename only)
     * @param  'manual'|'scheduled'|'pre-upgrade'  $trigger
     */
    public function upload(string $backupFilename, string $trigger = 'manual'): bool
    {
        if (! $this->isConfigured()) {
            Log::debug('directory backup upload skipped: PBX3_ORG_BUCKET not configured');

            return false;
        }

        if (! preg_match('/^pbx3bak\.(\d+)\.zip$/', $backupFilename, $m)) {
            Log::warning('directory backup upload: unexpected backup filename', ['file' => $backupFilename]);

            return false;
        }

        $localPath = '/opt/pbx3/bkup/'.$backupFilename;
        if (! is_file($localPath)) {
            Log::warning('directory backup upload: file missing', ['path' => $localPath]);

            return false;
        }

        $globals = Sysglobal::query()->where('pkey', 'global')->first(['id', 'pkey', 'fqdn', 'shortuid']);
        $instanceId = $globals?->id;
        if (! $instanceId) {
            Log::warning('directory backup upload: globals.id (KSUID) not set on this node');

            return false;
        }

        $epoch = (int) $m[1];
        $stamp = gmdate('Ymd\THis\Z', $epoch);
        $fqdn = trim((string) ($globals->fqdn ?? ''));

        try {
            $disk = Storage::disk('pbx3_org');
        } catch (\Throwable $e) {
            Log::error('directory backup upload: S3 disk unavailable (install league/flysystem-aws-s3-v3?)', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $prefix = "instances/{$instanceId}/backups";
        $stampPrefix = "{$prefix}/{$stamp}";
        $zipKey = "{$stampPrefix}/backup.zip";
        $manifestKey = "{$stampPrefix}/manifest.json";
        $policyKey = "{$prefix}/policy.json";
        $metaKey = "instances/{$instanceId}/meta.json";

        try {
            $this->putObject($disk, $zipKey, $localPath, self::S3_TAGGING);
            $this->assertObjectExists($disk, $zipKey, 'backup.zip');

            $bytes = filesize($localPath) ?: 0;
            $sha256 = hash_file('sha256', $localPath);
            $manifest = [
                'schema_version' => 1,
                'created_at' => gmdate('c', $epoch),
                'scope' => 'instance',
                'trigger' => $trigger,
                'instance_id' => $instanceId,
                'tenant_shortuid' => null,
                'node_fqdn' => $fqdn,
                'pbx3_version' => $this->detectPbx3Version(),
                'contents_summary' => $this->summarizeZip($localPath),
                'artifacts' => [
                    [
                        'name' => 'backup.zip',
                        'sha256' => $sha256,
                        'bytes' => $bytes,
                    ],
                ],
            ];
            $disk->put(
                $manifestKey,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ['Tagging' => self::S3_TAGGING]
            );
            $this->assertObjectExists($disk, $manifestKey, 'manifest.json');

            if (! $disk->exists($policyKey)) {
                $disk->put(
                    $policyKey,
                    json_encode(config('pbx3_directory.default_backup_policy'), JSON_PRETTY_PRINT)
                );
            }

            $this->touchInstanceMeta($disk, $metaKey, $instanceId, $stamp, $globals);

            Log::info('directory backup upload complete', [
                'bucket' => config('pbx3_directory.org_bucket'),
                'key' => $zipKey,
                'stamp' => $stamp,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('directory backup upload failed', [
                'file' => $backupFilename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function detectPbx3Version(): string
    {
        $raw = @shell_exec('dpkg-query -W -f=${Version} pbx3 2>/dev/null');
        $v = is_string($raw) ? trim($raw) : '';
        if ($v !== '') {
            return 'pbx3 '.$v;
        }

        return 'unknown';
    }

    /**
     * @return array<string, int>
     */
    private function summarizeZip(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            return ['zip_entries' => 0];
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['zip_entries' => 0];
        }
        $count = $zip->numFiles;
        $zip->close();

        return ['zip_entries' => $count];
    }

    private function touchInstanceMeta($disk, string $metaKey, string $instanceId, string $stamp, Sysglobal $globals): void
    {
        $now = gmdate('c');
        $meta = [];
        if ($disk->exists($metaKey)) {
            $raw = $disk->get($metaKey);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } else {
            $meta = [
                'id' => $instanceId,
                'fqdn' => trim((string) $globals->fqdn),
                'api_base_url' => $this->guessApiBaseUrl($globals),
                'label' => $globals->shortuid ?? explode('.', (string) $globals->fqdn)[0] ?? $instanceId,
                'status' => 'active',
                'created_at' => $now,
            ];
        }
        $meta['backup_latest_stamp'] = $stamp;
        $meta['updated_at'] = $now;
        $disk->put($metaKey, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function guessApiBaseUrl(Sysglobal $globals): string
    {
        $fqdn = trim((string) $globals->fqdn);
        if ($fqdn === '') {
            return '';
        }

        return "https://{$fqdn}:44300/api";
    }

    /**
     * PutObject via Laravel put() — writeStream()+Tagging can fail silently when disk throw=false.
     */
    private function putObject($disk, string $key, string $localPath, ?string $tagging = null): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open local backup for read');
        }

        $options = $tagging !== null && $tagging !== '' ? ['Tagging' => $tagging] : [];

        try {
            $ok = $disk->put($key, $stream, $options);
            if ($ok === false) {
                throw new \RuntimeException("S3 put returned false for {$key}");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function assertObjectExists($disk, string $key, string $label): void
    {
        if (! $disk->exists($key)) {
            throw new \RuntimeException(
                "S3 upload verification failed: {$label} missing at s3://".
                config('pbx3_directory.org_bucket')."/{$key}"
            );
        }
    }
}
