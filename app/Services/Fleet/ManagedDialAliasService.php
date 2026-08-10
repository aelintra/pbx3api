<?php

namespace App\Services\Fleet;

use App\Models\DialAlias;
use App\Support\ExtLenPolicy;
use Illuminate\Support\Facades\DB;

/**
 * C2 — Gatekeeper→node managed dialalias projections (source=cohort).
 * Call path unchanged; genAst/commit is separate (POST /fleet/commit once per home step).
 */
class ManagedDialAliasService
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_COHORT = 'cohort';

    /** FQDN: lowercase labels + TLD; requires at least one dot. */
    private const TARGET_FQDN_REGEX = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Upsert a cohort-owned dial prefix on the calling tenant.
     *
     * @param  array{
     *   cluster: string,
     *   pkey: string,
     *   target_fqdn: string,
     *   target_cluster?: string|null,
     *   cohort_id: string,
     *   active?: string,
     *   description?: string|null
     * }  $body
     * @return array{ok: bool, action: string, dialalias: DialAlias}
     */
    public function upsert(array $body): array
    {
        $cluster = $this->requireClusterShortuid((string) ($body['cluster'] ?? ''));
        $pkey = $this->requirePrefix((string) ($body['pkey'] ?? ''));
        $cohortId = trim((string) ($body['cohort_id'] ?? ''));
        if ($cohortId === '') {
            throw new \InvalidArgumentException('cohort_id required for managed dialalias', 422);
        }

        $targetFqdn = $this->normalizeTenantFqdn($body['target_fqdn'] ?? null);
        if ($targetFqdn === null) {
            throw new \InvalidArgumentException(
                'target_fqdn must be a full tenant FQDN (e.g. sister.pbx3.com)',
                422
            );
        }

        $selfErr = $this->targetSelfConflictMessage($cluster, $targetFqdn);
        if ($selfErr !== null) {
            throw new \InvalidArgumentException($selfErr, 422);
        }

        $targetCluster = $this->resolveOptionalTargetCluster(
            $body['target_cluster'] ?? null,
            $targetFqdn
        );

        $callerLen = ExtLenPolicy::forClusterIdentifier($cluster);
        $destLen = ExtLenPolicy::forDialTarget($targetCluster, $targetFqdn, $callerLen);
        if (! ExtLenPolicy::shortDialLengthOk(strlen($pkey), $destLen, $callerLen)) {
            throw new \InvalidArgumentException(
                ExtLenPolicy::shortDialLengthValidationMessage(strlen($pkey), $destLen, $callerLen),
                422
            );
        }

        $existing = DialAlias::where('cluster', $cluster)->where('pkey', $pkey)->first();
        $now = gmdate('Y-m-d H:i:s');

        if ($existing) {
            $existing->target_fqdn = $targetFqdn;
            $existing->target_cluster = $targetCluster;
            $existing->source = self::SOURCE_COHORT;
            $existing->cohort_id = $cohortId;
            $existing->active = isset($body['active']) && is_string($body['active'])
                ? strtoupper(trim($body['active']))
                : 'YES';
            if (array_key_exists('description', $body)) {
                $existing->description = is_string($body['description']) ? $body['description'] : null;
            }
            $existing->z_updated = $now;
            $existing->z_updater = 'fleet-dial-cohort';
            $id = $existing->id;
            if ($id === null || $id === '') {
                throw new \RuntimeException('Dial prefix id is missing', 409);
            }
            DialAlias::where('id', $id)->update($existing->getDirty() ?: [
                'target_fqdn' => $targetFqdn,
                'source' => self::SOURCE_COHORT,
                'cohort_id' => $cohortId,
            ]);
            $existing->syncOriginal();
            $fresh = $existing->fresh() ?? $existing;

            return ['ok' => true, 'action' => 'updated', 'dialalias' => $fresh];
        }

        $row = new DialAlias;
        $row->id = generate_ksuid();
        $row->shortuid = $this->newShortuid();
        $row->pkey = $pkey;
        $row->cluster = $cluster;
        $row->target_fqdn = $targetFqdn;
        $row->target_cluster = $targetCluster;
        $row->source = self::SOURCE_COHORT;
        $row->cohort_id = $cohortId;
        $row->active = 'YES';
        $row->description = isset($body['description']) && is_string($body['description'])
            ? $body['description']
            : ('Site group '.$cohortId);
        $row->z_created = $now;
        $row->z_updated = $now;
        $row->z_updater = 'fleet-dial-cohort';
        $row->save();

        return ['ok' => true, 'action' => 'created', 'dialalias' => $row];
    }

    /**
     * Delete a dialalias row. Default: managed_only (source=cohort).
     * managed_only=false allows pruning hand/lab cross-tenant rows (C3).
     *
     * @param  array{
     *   cluster: string,
     *   pkey?: string,
     *   id?: string,
     *   shortuid?: string,
     *   managed_only?: bool
     * }  $body
     * @return array{ok: bool, deleted: string, pkey: string, cluster: string}
     */
    public function delete(array $body): array
    {
        $cluster = $this->requireClusterShortuid((string) ($body['cluster'] ?? ''));
        $managedOnly = ! array_key_exists('managed_only', $body) || (bool) $body['managed_only'];

        $row = null;
        if (! empty($body['id'])) {
            $row = DialAlias::where('id', (string) $body['id'])->where('cluster', $cluster)->first();
        } elseif (! empty($body['shortuid'])) {
            $row = DialAlias::where('shortuid', (string) $body['shortuid'])->where('cluster', $cluster)->first();
        } elseif (! empty($body['pkey'])) {
            $pkey = $this->requirePrefix((string) $body['pkey']);
            $row = DialAlias::where('cluster', $cluster)->where('pkey', $pkey)->first();
        } else {
            throw new \InvalidArgumentException('pkey, id, or shortuid required', 422);
        }

        if ($row === null) {
            throw new \RuntimeException('Dial prefix not found', 404);
        }

        if ($managedOnly && ! self::isManaged($row)) {
            throw new \RuntimeException(
                'Dial prefix is not managed (source≠cohort); pass managed_only:false to prune',
                409
            );
        }

        $pkey = (string) $row->pkey;
        $id = (string) $row->id;
        $row->delete();

        return [
            'ok' => true,
            'deleted' => $id,
            'pkey' => $pkey,
            'cluster' => $cluster,
        ];
    }

    public static function isManaged(object $row): bool
    {
        $source = strtolower(trim((string) ($row->source ?? self::SOURCE_MANUAL)));

        return $source === self::SOURCE_COHORT;
    }

    /** Cohort feature gate for Sanctum cross-tenant forbid. */
    public static function cohortFeatureOn(): bool
    {
        return (bool) config('pbx3_fleet.dial_cohort', false);
    }

    public function normalizeTenantFqdn(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = strtolower(trim((string) $raw));
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        if ($s === '') {
            return null;
        }

        if (str_contains($s, '://')) {
            $host = parse_url($s, PHP_URL_HOST);
            $s = is_string($host) && $host !== '' ? $host : preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $s);
            $s = strtolower(explode('/', (string) $s)[0] ?? '');
        } elseif (str_contains($s, '/') && ! str_contains($s, ' ')) {
            $s = strtolower(explode('/', $s)[0] ?? '');
        }

        if (str_contains($s, '@')) {
            $s = strtolower(explode('@', $s)[1] ?? $s);
        }

        $s = preg_replace('/:\d+$/', '', $s) ?? $s;
        $s = rtrim($s, '.');

        if ($s === '' || ! str_contains($s, '.')) {
            return null;
        }
        if (! preg_match(self::TARGET_FQDN_REGEX, $s)) {
            return null;
        }

        return $s;
    }

    private function requireClusterShortuid(string $raw): string
    {
        $resolved = cluster_identifier_to_shortuid($raw);
        if ($resolved === null) {
            // Accept raw shortuid even if tenant not local yet (rare); still must be alnum.
            $s = strtolower(trim($raw));
            if ($s === '' || ! preg_match('/^[a-z0-9]+$/', $s)) {
                throw new \InvalidArgumentException('cluster required (tenant shortuid)', 422);
            }
            if (! DB::table('cluster')->where('shortuid', $s)->exists()) {
                throw new \InvalidArgumentException("Unknown tenant cluster: {$s}", 422);
            }

            return $s;
        }

        return $resolved;
    }

    private function requirePrefix(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';
        if ($digits === '' || ! preg_match('/^\d{2,4}$/', $digits)) {
            throw new \InvalidArgumentException('pkey must be 2–4 digits', 422);
        }

        return $digits;
    }

    private function resolveOptionalTargetCluster(mixed $raw, string $targetFqdn): ?string
    {
        if ($raw !== null && trim((string) $raw) !== '') {
            $resolved = cluster_identifier_to_shortuid($raw);
            if ($resolved !== null) {
                return $resolved;
            }
            $s = strtolower(trim((string) $raw));
            if (preg_match('/^[a-z0-9]+$/', $s)) {
                return $s;
            }
        }

        try {
            $row = DB::table('cluster')
                ->whereRaw('LOWER(TRIM(fqdn)) = ?', [strtolower($targetFqdn)])
                ->first(['shortuid']);
            if ($row && ! empty($row->shortuid)) {
                return (string) $row->shortuid;
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    private function targetSelfConflictMessage(string $callingShortuid, string $targetFqdn): ?string
    {
        try {
            $calling = DB::table('cluster')
                ->where('shortuid', $callingShortuid)
                ->orWhere('pkey', $callingShortuid)
                ->orWhere('id', $callingShortuid)
                ->first(['shortuid', 'fqdn']);
            if ($calling) {
                $callFqdn = strtolower(trim((string) ($calling->fqdn ?? '')));
                if ($callFqdn !== '' && $callFqdn === $targetFqdn) {
                    return 'Target tenant FQDN must differ from the calling tenant.';
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    private function newShortuid(): string
    {
        try {
            return generate_shortuid();
        } catch (\Throwable) {
            $s = strtolower(substr(bin2hex(random_bytes(4)), 0, 6));
            if (! preg_match('/[a-z]/', $s)) {
                $s = 'a'.substr($s, 1);
            }

            return $s;
        }
    }
}
