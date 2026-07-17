<?php

namespace App\Services\Directory;

use App\Models\Sysglobal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 5: list + presigned download of shipped instance logs on org bucket.
 */
class InstanceLogArchiveService
{
    public function isAvailable(): bool
    {
        $bucket = config('pbx3_directory.org_bucket');

        return is_string($bucket) && trim($bucket) !== '';
    }

    public function instanceId(): string
    {
        $globals = Sysglobal::query()->where('pkey', 'global')->first(['id']);
        $id = $globals?->id;
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('globals.id (KSUID) not set on this node');
        }

        return $id;
    }

    /**
     * @return list<array{key: string, class: string, stamp: string, basename: string, size: int|null, last_modified: string|null}>
     */
    public function list(?string $class = null, ?string $from = null, ?string $to = null): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $instanceId = $this->instanceId();
        $prefix = "instances/{$instanceId}/logs/";
        $disk = Storage::disk('pbx3_org');

        $classes = $class !== null && $class !== ''
            ? [$class]
            : LogRetentionService::CLASSES;

        $fromTs = $from !== null && $from !== '' ? $this->parseBound($from, false) : null;
        $toTs = $to !== null && $to !== '' ? $this->parseBound($to, true) : null;

        $out = [];
        foreach ($classes as $cls) {
            if (! in_array($cls, LogRetentionService::CLASSES, true)) {
                throw new \InvalidArgumentException("Unknown log class: {$cls}");
            }
            $classPrefix = $prefix.$cls.'/';
            try {
                $files = $disk->allFiles($classPrefix);
            } catch (\Throwable $e) {
                Log::warning('log archive list failed', ['class' => $cls, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($files as $key) {
                if (! is_string($key) || str_ends_with($key, '/policy.json')) {
                    continue;
                }
                // instances/{id}/logs/{class}/{stamp}/{basename}
                $rel = substr($key, strlen($prefix));
                $parts = explode('/', $rel, 3);
                if (count($parts) < 3) {
                    continue;
                }
                [$keyClass, $stamp, $basename] = $parts;
                if ($keyClass !== $cls) {
                    continue;
                }
                if (! preg_match('/^\d{8}T\d{6}Z$/', $stamp)) {
                    continue;
                }
                $stampTs = $this->stampToUnix($stamp);
                if ($fromTs !== null && $stampTs < $fromTs) {
                    continue;
                }
                if ($toTs !== null && $stampTs > $toTs) {
                    continue;
                }

                $size = null;
                $lastModified = null;
                try {
                    $size = $disk->size($key);
                } catch (\Throwable) {
                }
                try {
                    $lm = $disk->lastModified($key);
                    if (is_int($lm)) {
                        $lastModified = gmdate('c', $lm);
                    }
                } catch (\Throwable) {
                }

                $out[] = [
                    'key' => $key,
                    'class' => $cls,
                    'stamp' => $stamp,
                    'basename' => $basename,
                    'size' => $size,
                    'last_modified' => $lastModified,
                ];
            }
        }

        usort($out, static fn ($a, $b) => strcmp($b['stamp'], $a['stamp']));

        return $out;
    }

    /**
     * @return array{url: string, expires_at: string, filename: string, key: string}
     */
    public function presignedDownloadUrl(string $key): array
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException('Org bucket not configured');
        }

        $key = ltrim($key, '/');
        $instanceId = $this->instanceId();
        $allowedPrefix = "instances/{$instanceId}/logs/";
        if (! str_starts_with($key, $allowedPrefix) || str_contains($key, '..')) {
            throw new \InvalidArgumentException('Key is outside this instance logs prefix');
        }
        if (str_ends_with($key, '/policy.json') || basename($key) === 'policy.json') {
            throw new \InvalidArgumentException('policy.json is not downloadable via this endpoint');
        }

        $disk = Storage::disk('pbx3_org');
        if (! $disk->exists($key)) {
            throw new \RuntimeException("Archive object not found: {$key}");
        }

        $ttlMinutes = max(1, (int) config('pbx3_logs.presigned_ttl_minutes', 15));
        $expires = now()->addMinutes($ttlMinutes);
        $filename = basename($key);
        $url = $disk->temporaryUrl($key, $expires, [
            'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"',
        ]);

        Log::info('log archive presigned download issued', [
            'key' => $key,
            'expires_at' => $expires->toIso8601String(),
            'user_id' => auth()->user()?->id,
        ]);

        return [
            'url' => $url,
            'expires_at' => $expires->toIso8601String(),
            'filename' => $filename,
            'key' => $key,
        ];
    }

    private function parseBound(string $value, bool $endOfDay): int
    {
        // Accept Y-m-d or full ISO
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= $endOfDay ? 'T23:59:59Z' : 'T00:00:00Z';
        }
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $dt->getTimestamp();
    }

    private function stampToUnix(string $stamp): int
    {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $stamp, new DateTimeZone('UTC'));
        if ($dt === false) {
            return 0;
        }

        return $dt->getTimestamp();
    }
}
