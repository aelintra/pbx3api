<?php

namespace App\Services\Directory;

use App\Models\Sysglobal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 5: effective log retention knobs (config/env + optional override file).
 */
class LogRetentionService
{
    public const CLASSES = ['syslog', 'asterisk-messages', 'cdr'];

    /**
     * @return array{
     *   local_days: array<string, int>,
     *   s3_maxage_days: array<string, int>,
     *   override_path: string,
     *   has_override: bool
     * }
     */
    public function get(): array
    {
        return [
            'local_days' => $this->effectiveLocalDays(),
            's3_maxage_days' => $this->effectiveS3MaxageDays(),
            'override_path' => (string) config('pbx3_logs.retention_override_path'),
            'has_override' => is_file((string) config('pbx3_logs.retention_override_path')),
        ];
    }

    /**
     * @param  array{local_days?: array<string, mixed>, s3_maxage_days?: array<string, mixed>}  $knobs
     * @return array{local_days: array<string, int>, s3_maxage_days: array<string, int>, override_path: string, has_override: bool}
     */
    public function put(array $knobs): array
    {
        $localPatch = $this->normalizeDays($knobs['local_days'] ?? [], 1, 365);
        $s3Patch = $this->normalizeDays($knobs['s3_maxage_days'] ?? [], 1, 730);

        $payload = [
            'schema_version' => 1,
            'local_days' => array_merge($this->baseLocalDays(), $this->overrideSection('local_days'), $localPatch),
            's3_maxage_days' => array_merge($this->baseS3MaxageDays(), $this->overrideSection('s3_maxage_days'), $s3Patch),
            'updated_at' => gmdate('c'),
        ];

        $path = (string) config('pbx3_logs.retention_override_path');
        $dir = dirname($path);
        if ($dir !== '' && ! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Cannot create retention override dir: {$dir}");
            }
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($path, $json."\n") === false) {
            throw new \RuntimeException("Cannot write retention override: {$path}");
        }

        $this->rewritePolicyJson();

        return $this->get();
    }

    /**
     * @return array<string, int>
     */
    public function effectiveLocalDays(): array
    {
        return array_merge($this->baseLocalDays(), $this->overrideSection('local_days'));
    }

    /**
     * @return array<string, int>
     */
    public function effectiveS3MaxageDays(): array
    {
        return array_merge($this->baseS3MaxageDays(), $this->overrideSection('s3_maxage_days'));
    }

    /**
     * @return array<string, int>
     */
    private function baseLocalDays(): array
    {
        $cfg = config('pbx3_logs.local_days', []);

        return $this->intMap(is_array($cfg) ? $cfg : []);
    }

    /**
     * @return array<string, int>
     */
    private function baseS3MaxageDays(): array
    {
        $cfg = config('pbx3_logs.s3_maxage_days', []);

        return $this->intMap(is_array($cfg) ? $cfg : []);
    }

    private function rewritePolicyJson(): void
    {
        $bucket = config('pbx3_directory.org_bucket');
        if (! is_string($bucket) || trim($bucket) === '') {
            return;
        }

        $globals = Sysglobal::query()->where('pkey', 'global')->first(['id']);
        $instanceId = $globals?->id;
        if (! is_string($instanceId) || $instanceId === '') {
            return;
        }

        try {
            $disk = Storage::disk('pbx3_org');
            app(InstanceLogDirectoryUpload::class)->writePolicy($disk, $instanceId, true);
        } catch (\Throwable $e) {
            Log::warning('log retention: could not rewrite S3 policy.json', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function overrideSection(string $key): array
    {
        $override = $this->loadOverride();
        if (! isset($override[$key]) || ! is_array($override[$key])) {
            return [];
        }

        return $this->intMap($override[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOverride(): array
    {
        $path = (string) config('pbx3_logs.retention_override_path');
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
     * @param  array<string, mixed>  $in
     * @return array<string, int>
     */
    private function normalizeDays(array $in, int $min, int $max): array
    {
        $out = [];
        foreach (self::CLASSES as $class) {
            if (! array_key_exists($class, $in)) {
                continue;
            }
            $v = (int) $in[$class];
            if ($v < $min || $v > $max) {
                throw new \InvalidArgumentException("{$class} days must be between {$min} and {$max}");
            }
            $out[$class] = $v;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, int>
     */
    private function intMap(array $in): array
    {
        $out = [];
        foreach (self::CLASSES as $class) {
            if (array_key_exists($class, $in)) {
                $out[$class] = (int) $in[$class];
            }
        }

        return $out;
    }
}
