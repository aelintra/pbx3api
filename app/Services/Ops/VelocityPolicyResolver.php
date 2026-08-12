<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * V3 — pull fleet velocity policy from S3 catalog/velocity-policy.json.
 *
 * Merge: PBX3_OPS_VELOCITY_POLICY=local → env only; else S3 → cache → env.
 * Spec: FLEET_TOLL_FRAUD_VELOCITY_IMPLEMENTATION_PLAN.md L1 / WP3.
 */
final class VelocityPolicyResolver
{
    public const S3_KEY = 'catalog/velocity-policy.json';

    /**
     * Apply merged IRSF knobs onto config('pbx3_ops.*') for this process.
     *
     * @return array{
     *   source: 'local'|'s3'|'cache'|'env',
     *   policy: ?array<string, mixed>,
     *   error: string
     * }
     */
    public function apply(): array
    {
        $mode = strtolower(trim((string) config('pbx3_ops.velocity_policy_mode', 'fleet')));
        if ($mode === 'local') {
            return ['source' => 'local', 'policy' => null, 'error' => ''];
        }

        $pulled = $this->pull();
        $policy = $pulled['policy'];
        if (! is_array($policy)) {
            return ['source' => 'env', 'policy' => null, 'error' => $pulled['error']];
        }

        $irsf = is_array($policy['irsf'] ?? null) ? $policy['irsf'] : [];
        if ($irsf !== []) {
            if (isset($irsf['n'])) {
                config(['pbx3_ops.velocity_threshold' => max(1, (int) $irsf['n'])]);
            }
            if (isset($irsf['t_minutes'])) {
                config(['pbx3_ops.velocity_window_minutes' => max(1, (int) $irsf['t_minutes'])]);
            }
            if (isset($irsf['q_minutes'])) {
                config(['pbx3_ops.velocity_quiet_minutes' => max(1, (int) $irsf['q_minutes'])]);
            }
            if (isset($irsf['prefixes'])) {
                $prefixes = $irsf['prefixes'];
                if (is_array($prefixes)) {
                    config(['pbx3_ops.velocity_prefixes' => implode(',', array_map('strval', $prefixes))]);
                } elseif (is_string($prefixes) && trim($prefixes) !== '') {
                    config(['pbx3_ops.velocity_prefixes' => $prefixes]);
                }
            }
            if (array_key_exists('act_enabled', $irsf)) {
                config(['pbx3_ops.velocity_act_enabled' => filter_var($irsf['act_enabled'], FILTER_VALIDATE_BOOL)]);
            }
        }

        $allow = $policy['allowlist_extensions'] ?? null;
        if (is_array($allow)) {
            config(['pbx3_ops.velocity_allowlist' => implode(',', array_map('strval', $allow))]);
        }

        $det = is_array($policy['detectors'] ?? null) ? $policy['detectors'] : [];
        if (array_key_exists('off_hours', $det)) {
            config(['pbx3_ops.velocity_off_hours_enabled' => filter_var($det['off_hours'], FILTER_VALIDATE_BOOL)]);
        }

        $oh = is_array($policy['off_hours'] ?? null) ? $policy['off_hours'] : [];
        if ($oh !== []) {
            if (isset($oh['tz']) && is_string($oh['tz']) && trim($oh['tz']) !== '') {
                config(['pbx3_ops.velocity_off_hours_tz' => trim($oh['tz'])]);
            }
            if (isset($oh['n'])) {
                config(['pbx3_ops.velocity_off_hours_threshold' => max(1, (int) $oh['n'])]);
            }
            if (isset($oh['t_minutes'])) {
                config(['pbx3_ops.velocity_off_hours_window_minutes' => max(1, (int) $oh['t_minutes'])]);
            }
            if (isset($oh['windows']) && is_array($oh['windows'])) {
                config(['pbx3_ops.velocity_off_hours_windows' => $oh['windows']]);
            }
        }

        config(['pbx3_ops.velocity_fleet_policy' => $policy]);

        return [
            'source' => $pulled['source'],
            'policy' => $policy,
            'error' => $pulled['error'],
        ];
    }

    /**
     * @return array{source: 's3'|'cache'|'env', policy: ?array<string, mixed>, error: string}
     */
    public function pull(): array
    {
        $cachePath = (string) config('pbx3_ops.velocity_policy_cache_path', storage_path('app/ops-velocity-policy.json'));
        $bucket = config('pbx3_directory.org_bucket');
        if (! is_string($bucket) || trim($bucket) === '') {
            $cached = $this->readCache($cachePath);
            if ($cached !== null) {
                return ['source' => 'cache', 'policy' => $cached, 'error' => 'PBX3_ORG_BUCKET unset'];
            }

            return ['source' => 'env', 'policy' => null, 'error' => 'PBX3_ORG_BUCKET unset'];
        }

        try {
            $disk = Storage::disk('pbx3_org');
            $raw = $disk->get(self::S3_KEY);
            $data = json_decode((string) $raw, true);
            if (! is_array($data) || $data === []) {
                throw new \RuntimeException('velocity policy JSON empty or invalid');
            }
            $this->writeCache($cachePath, $data);

            return ['source' => 's3', 'policy' => $data, 'error' => ''];
        } catch (\Throwable $e) {
            Log::debug('velocity policy S3 pull failed', ['error' => $e->getMessage()]);
            $cached = $this->readCache($cachePath);
            if ($cached !== null) {
                return ['source' => 'cache', 'policy' => $cached, 'error' => $e->getMessage()];
            }

            return ['source' => 'env', 'policy' => null, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed>|null */
    private function readCache(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && $data !== [] ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function writeCache(string $path, array $data): void
    {
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $path,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}
