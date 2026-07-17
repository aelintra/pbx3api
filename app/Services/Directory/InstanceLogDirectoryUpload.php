<?php

namespace App\Services\Directory;

use App\Models\Sysglobal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 1: ship rotated instance logs to org S3 (instances/{ksuid}/logs/{class}/…).
 *
 * Local rotation is logrotate (pbx3-asterisk-logs + system rsyslog). This service
 * only uploads completed segments; telephony does not depend on S3.
 */
class InstanceLogDirectoryUpload
{
    /**
     * @return array{uploaded: int, skipped: int, errors: int, details: list<string>}
     */
    public function run(?int $limit = null): array
    {
        $stats = ['uploaded' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

        if (! $this->isConfigured()) {
            Log::debug('log ship skipped: not configured (PBX3_ORG_BUCKET / PBX3_LOG_UPLOAD_ENABLED)');

            return $stats;
        }

        $globals = Sysglobal::query()->where('pkey', 'global')->first(['id', 'pkey', 'fqdn', 'shortuid']);
        $instanceId = $globals?->id;
        if (! is_string($instanceId) || $instanceId === '') {
            Log::warning('log ship: globals.id (KSUID) not set on this node');
            $stats['errors']++;
            $stats['details'][] = 'missing globals.id';

            return $stats;
        }

        try {
            $disk = Storage::disk('pbx3_org');
        } catch (\Throwable $e) {
            Log::error('log ship: S3 disk unavailable', ['error' => $e->getMessage()]);
            $stats['errors']++;
            $stats['details'][] = 's3 disk unavailable';

            return $stats;
        }

        $this->writePolicy($disk, $instanceId, false);

        $state = $this->loadState();
        $candidates = $this->discoverCandidates();
        if ($limit !== null && $limit > 0) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        foreach ($candidates as $item) {
            $fp = $this->fingerprint($item['path']);
            if ($fp !== null && isset($state[$fp])) {
                $stats['skipped']++;

                continue;
            }

            try {
                $ok = $this->uploadOne($disk, $instanceId, $item['class'], $item['path']);
                if ($ok) {
                    if ($fp !== null) {
                        $state[$fp] = [
                            'path' => $item['path'],
                            'class' => $item['class'],
                            'shipped_at' => gmdate('c'),
                        ];
                        $this->saveState($state);
                    }
                    $stats['uploaded']++;
                    $stats['details'][] = $item['path'];
                } else {
                    $stats['errors']++;
                }
            } catch (\Throwable $e) {
                Log::error('log ship failed', [
                    'path' => $item['path'],
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
                $stats['details'][] = $item['path'].': '.$e->getMessage();
            }
        }

        return $stats;
    }

    public function isConfigured(): bool
    {
        if (! config('pbx3_logs.upload_enabled')) {
            return false;
        }
        $bucket = config('pbx3_directory.org_bucket');

        return is_string($bucket) && trim($bucket) !== '';
    }

    /**
     * @return list<array{class: string, path: string}>
     */
    public function discoverCandidates(): array
    {
        $out = [];
        $sources = config('pbx3_logs.sources', []);
        if (! is_array($sources)) {
            return [];
        }

        foreach ($sources as $class => $patterns) {
            if (! is_string($class) || ! is_array($patterns)) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (! is_string($pattern) || $pattern === '') {
                    continue;
                }
                foreach (glob($pattern) ?: [] as $path) {
                    if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                        continue;
                    }
                    if (! $this->isRotatedSegment(basename($path), $class)) {
                        continue;
                    }
                    $out[] = ['class' => $class, 'path' => $path];
                }
            }
        }

        usort($out, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $out;
    }

    /**
     * Rotated segments only — never live Master.csv / messages / syslog.
     */
    public function isRotatedSegment(string $basename, string $class): bool
    {
        return match ($class) {
            'syslog' => (bool) preg_match('/^syslog\.\d+/', $basename),
            'asterisk-messages' => (bool) preg_match('/^messages\.\d+/', $basename),
            'cdr' => (bool) preg_match('/^Master\.csv\.\d+/', $basename),
            default => false,
        };
    }

    public function buildObjectKey(string $instanceId, string $class, string $localPath): string
    {
        $mtime = @filemtime($localPath) ?: time();
        $stamp = gmdate('Ymd\THis\Z', $mtime);
        $base = basename($localPath);

        return "instances/{$instanceId}/logs/{$class}/{$stamp}/{$base}";
    }

    private function uploadOne($disk, string $instanceId, string $class, string $localPath): bool
    {
        $key = $this->buildObjectKey($instanceId, $class, $localPath);
        $tagging = $this->taggingForClass($class);

        $this->putObject($disk, $key, $localPath, $tagging);
        if (! $disk->exists($key)) {
            throw new \RuntimeException("S3 upload verification failed for {$key}");
        }

        Log::info('log ship complete', [
            'bucket' => config('pbx3_directory.org_bucket'),
            'key' => $key,
            'class' => $class,
        ]);

        return true;
    }

    /**
     * Write or refresh instances/{id}/logs/policy.json from effective retention knobs.
     *
     * @param  mixed  $disk  Laravel filesystem disk
     */
    public function writePolicy($disk, string $instanceId, bool $force = false): void
    {
        $policyKey = "instances/{$instanceId}/logs/policy.json";
        if (! $force && $disk->exists($policyKey)) {
            return;
        }
        $maxage = app(LogRetentionService::class)->effectiveS3MaxageDays();
        $payload = [
            'schema_version' => 1,
            'classes' => $maxage,
            'updated_at' => gmdate('c'),
        ];
        $disk->put($policyKey, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @deprecated use writePolicy */
    private function ensurePolicy($disk, string $instanceId): void
    {
        $this->writePolicy($disk, $instanceId, false);
    }

    private function taggingForClass(string $class): ?string
    {
        if (! config('pbx3_logs.s3_tagging')) {
            return null;
        }

        return 'class='.$class;
    }

    private function putObject($disk, string $key, string $localPath, ?string $tagging): void
    {
        try {
            $this->putFileStream($disk, $key, $localPath, $tagging);
        } catch (\Throwable $e) {
            if ($tagging === null || ! $this->isTaggingAccessDenied($e)) {
                throw $e;
            }
            Log::warning('log ship: PutObjectTagging denied; stored without tag', ['key' => $key]);
            $this->putFileStream($disk, $key, $localPath, null);
        }
    }

    private function putFileStream($disk, string $key, string $localPath, ?string $tagging): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open {$localPath}");
        }

        try {
            $options = ($tagging !== null && $tagging !== '') ? ['Tagging' => $tagging] : [];
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

    private function isTaggingAccessDenied(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'PutObjectTagging')
            || (str_contains($msg, 'AccessDenied') && str_contains($msg, 'Tagging'));
    }

    private function fingerprint(string $path): ?string
    {
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }

        return hash('sha256', $path.'|'.$stat['ino'].'|'.$stat['size'].'|'.$stat['mtime']);
    }

    /**
     * @return array<string, array{path: string, class: string, shipped_at: string}>
     */
    private function loadState(): array
    {
        $path = (string) config('pbx3_logs.state_path');
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, array{path: string, class: string, shipped_at: string}>  $state
     */
    private function saveState(array $state): void
    {
        $path = (string) config('pbx3_logs.state_path');
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        file_put_contents(
            $path,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX
        );
    }
}
