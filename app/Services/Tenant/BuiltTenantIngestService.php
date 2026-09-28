<?php

namespace App\Services\Tenant;

use App\Models\Sysglobal;
use App\Services\Fleet\FleetPostureService;
use Illuminate\Support\Facades\DB;

/**
 * Ingest a sark-to-pbx3 split-tenant sqlite (.db) into this home.
 *
 * @see pbx3/workingdocs/FLEET_BUILT_TENANT_INGEST_REQUIREMENTS.md
 */
class BuiltTenantIngestService
{
    /**
     * Preflight only — no writes.
     *
     * @return array<string, mixed>
     */
    public function preflight(string $sourceDbPath): array
    {
        return $this->plan($sourceDbPath);
    }

    /**
     * @param  array{dry_run?: bool}  $options
     * @return array<string, mixed>
     */
    public function ingest(string $sourceDbPath, array $options = []): array
    {
        $plan = $this->plan($sourceDbPath);
        if (! empty($plan['blocking_errors'])) {
            throw new \RuntimeException(implode('; ', $plan['blocking_errors']));
        }

        if (! empty($options['dry_run'])) {
            $plan['dry_run'] = true;
            $plan['applied'] = false;

            return $plan;
        }

        $result = $this->apply($plan, $sourceDbPath);
        $result['dry_run'] = false;
        $result['applied'] = true;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $sourceDbPath): array
    {
        $path = $this->requireReadableDb($sourceDbPath);
        $src = $this->openSqlite($path);

        $errors = [];
        $warnings = [];

        if (! $this->tableExists($src, 'cluster')) {
            throw new \RuntimeException('Source DB has no cluster table');
        }

        $clusters = $src->query('SELECT * FROM cluster')->fetchAll(\PDO::FETCH_ASSOC);
        if (count($clusters) !== 1) {
            throw new \RuntimeException(
                'Source DB must contain exactly one cluster row (got '.count($clusters).')'
            );
        }

        $cluster = $clusters[0];
        $pkey = trim((string) ($cluster['pkey'] ?? ''));
        $shortuid = strtolower(trim((string) ($cluster['shortuid'] ?? '')));
        $clusterId = trim((string) ($cluster['id'] ?? ''));

        if ($pkey === '' || $pkey === 'default') {
            $errors[] = "Invalid cluster.pkey '{$pkey}' (reserved/empty)";
        }
        if ($shortuid === '') {
            $errors[] = 'cluster.shortuid is empty';
        }
        if ($clusterId === '') {
            $errors[] = 'cluster.id is empty';
        }

        $finalShortuid = $shortuid;
        $finalId = $clusterId;
        $remintShortuid = false;
        $remintId = false;

        if ($pkey !== '' && $pkey !== 'default') {
            $byPkey = DB::table('cluster')->where('pkey', $pkey)->first(['id', 'shortuid', 'pkey']);
            if ($byPkey !== null) {
                $errors[] = "pkey collision: Name '{$pkey}' already exists (shortuid=".($byPkey->shortuid ?? '').') — rename before ingest';
            }
        }

        if ($shortuid !== '' && DB::table('cluster')->where('shortuid', $shortuid)->exists()) {
            $remintShortuid = true;
            $finalShortuid = $this->mintUniqueShortuid();
            $warnings[] = "shortuid collision: will remint {$shortuid} → {$finalShortuid}";
        }

        if ($clusterId !== '' && DB::table('cluster')->where('id', $clusterId)->exists()) {
            $remintId = true;
            $finalId = generate_ksuid();
            $warnings[] = "cluster.id collision: will remint {$clusterId} → {$finalId}";
        }

        $apex = $this->homeApex();
        $finalFqdn = $finalShortuid !== '' && $apex !== ''
            ? strtolower("{$finalShortuid}.{$apex}")
            : '';

        if ($apex === '') {
            $errors[] = 'Home globals.domain (apex) is empty — cannot derive FQDN';
        }

        $sourceAliases = array_values(array_unique(array_filter([
            $shortuid,
            $pkey,
            $clusterId,
        ], static fn ($v) => $v !== null && $v !== '')));

        $rowCounts = ['cluster' => 1];
        foreach (TenantMobilityService::TENANT_DATA_TABLES as $table) {
            if (! $this->tableExists($src, $table)) {
                continue;
            }
            if (! $this->columnExists($src, $table, 'cluster')) {
                $warnings[] = "Table {$table} has no cluster column — skipped";
                continue;
            }
            $rowCounts[$table] = $this->countClusterRows($src, $table, $sourceAliases);
        }

        $fleet = app(FleetPostureService::class)->isFleetNode();
        if (! $fleet) {
            $warnings[] = 'Node is not in fleet posture — route.path* will not be forced to Egress';
        }

        return [
            'source_db' => $path,
            'source' => [
                'id' => $clusterId,
                'shortuid' => $shortuid,
                'pkey' => $pkey,
                'fqdn' => (string) ($cluster['fqdn'] ?? ''),
            ],
            'planned' => [
                'id' => $finalId,
                'shortuid' => $finalShortuid,
                'pkey' => $pkey,
                'fqdn' => $finalFqdn,
                'remint_shortuid' => $remintShortuid,
                'remint_id' => $remintId,
                'normalize_fleet_routes' => $fleet,
                'egress_trunk' => (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress'),
            ],
            'row_counts' => $rowCounts,
            'source_aliases' => $sourceAliases,
            'blocking_errors' => $errors,
            'warnings' => $warnings,
            'enroll_hint' => $finalShortuid !== '' && $finalFqdn !== ''
                ? $this->enrollHint($finalShortuid, $finalFqdn, $pkey)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function apply(array $plan, string $sourceDbPath): array
    {
        $path = $this->requireReadableDb($sourceDbPath);
        $src = $this->openSqlite($path);
        $target = $this->openHomePdo();

        $planned = $plan['planned'];
        $sourceAliases = $plan['source_aliases'];
        $newShortuid = (string) $planned['shortuid'];
        $newId = (string) $planned['id'];
        $newFqdn = (string) $planned['fqdn'];
        $pkey = (string) $planned['pkey'];

        $imported = [];
        $target->beginTransaction();
        try {
            $clusterRow = $src->query('SELECT * FROM cluster LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
            if ($clusterRow === false) {
                throw new \RuntimeException('Source cluster row disappeared');
            }
            $clusterRow['id'] = $newId;
            $clusterRow['shortuid'] = $newShortuid;
            $clusterRow['fqdn'] = $newFqdn;
            $clusterRow['pkey'] = $pkey;
            // cname historically mirrors fqdn on some schemas
            if (array_key_exists('cname', $clusterRow)) {
                $clusterRow['cname'] = $newFqdn;
            }

            $imported['cluster'] = $this->insertRowIntersecting($target, 'cluster', $clusterRow);

            foreach (TenantMobilityService::TENANT_DATA_TABLES as $table) {
                if (! $this->tableExists($src, $table) || ! $this->tableExists($target, $table)) {
                    continue;
                }
                if (! $this->columnExists($src, $table, 'cluster')) {
                    continue;
                }
                $imported[$table] = $this->copyClusterScopedRows(
                    $src,
                    $target,
                    $table,
                    $sourceAliases,
                    $newShortuid
                );
            }

            $routeNormalized = 0;
            if (! empty($planned['normalize_fleet_routes']) && $this->tableExists($target, 'route')) {
                $routeNormalized = $this->normalizeFleetRoutesForTenant(
                    $target,
                    $newShortuid,
                    $pkey,
                    $newId
                );
            }

            $target->commit();
        } catch (\Throwable $e) {
            $target->rollBack();
            throw $e;
        }

        $plan['imported_rows'] = $imported;
        $plan['route_fleet_normalized'] = $routeNormalized ?? 0;
        $plan['tenant'] = [
            'id' => $newId,
            'shortuid' => $newShortuid,
            'pkey' => $pkey,
            'fqdn' => $newFqdn,
        ];
        $plan['enroll_hint'] = $this->enrollHint($newShortuid, $newFqdn, $pkey);

        return $plan;
    }

    /**
     * @param  list<string>  $sourceAliases
     */
    /**
     * @param  list<string>  $sourceAliases
     */
    private function copyClusterScopedRows(
        \PDO $src,
        \PDO $target,
        string $table,
        array $sourceAliases,
        string $newShortuid,
    ): int {
        $srcCols = $this->tableColumns($src, $table);
        $tgtCols = $this->tableColumns($target, $table);
        $cols = array_values(array_intersect($srcCols, $tgtCols));
        if ($cols === [] || ! in_array('cluster', $cols, true)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($sourceAliases), '?'));
        $stmt = $src->prepare("SELECT * FROM {$table} WHERE cluster IN ({$placeholders})");
        $stmt->execute($sourceAliases);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $quoted = array_map(static fn (string $c) => '"'.$c.'"', $cols);
        $colList = implode(', ', $quoted);
        $valuePlaceholders = implode(', ', array_fill(0, count($cols), '?'));
        $insert = $target->prepare("INSERT INTO {$table} ({$colList}) VALUES ({$valuePlaceholders})");

        $count = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($cols as $col) {
                $val = $row[$col] ?? null;
                if ($col === 'cluster') {
                    // Canonical home value is shortuid (ETL already uses shortuid).
                    $val = $newShortuid;
                }
                $values[] = $val;
            }
            $insert->execute($values);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertRowIntersecting(\PDO $target, string $table, array $row): int
    {
        $tgtCols = $this->tableColumns($target, $table);
        $cols = [];
        $values = [];
        foreach ($tgtCols as $col) {
            if (! array_key_exists($col, $row)) {
                continue;
            }
            $cols[] = $col;
            $values[] = $row[$col];
        }
        if ($cols === []) {
            return 0;
        }
        $quoted = array_map(static fn (string $c) => '"'.$c.'"', $cols);
        $colList = implode(', ', $quoted);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $target->prepare("INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})")
            ->execute($values);

        return 1;
    }

    private function normalizeFleetRoutesForTenant(
        \PDO $pdo,
        string $shortuid,
        string $pkey,
        string $clusterId,
    ): int {
        $egress = (string) config('pbx3_fleet.egress_trunk_pkey', 'Egress');
        $aliases = array_values(array_unique(array_filter([$shortuid, $pkey, $clusterId])));
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $sql = "UPDATE route SET path1 = ?, path2 = NULL, path3 = NULL, path4 = NULL
            WHERE cluster IN ({$placeholders})
              AND (path1 IS NOT NULL OR path2 IS NOT NULL OR path3 IS NOT NULL OR path4 IS NOT NULL)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$egress], $aliases));

        return $stmt->rowCount();
    }

    /**
     * @param  list<string>  $aliases
     */
    private function countClusterRows(\PDO $pdo, string $table, array $aliases): int
    {
        if ($aliases === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE cluster IN ({$placeholders})");
        $stmt->execute($aliases);

        return (int) $stmt->fetchColumn();
    }

    private function homeApex(): string
    {
        $globals = Sysglobal::query()->first();
        $domain = trim((string) ($globals->domain ?? ''));
        if ($domain !== '') {
            return strtolower($domain);
        }
        $fqdn = trim((string) ($globals->fqdn ?? ''));
        if ($fqdn !== '' && str_contains($fqdn, '.')) {
            return strtolower(explode('.', $fqdn, 2)[1]);
        }

        return '';
    }

    private function mintUniqueShortuid(): string
    {
        for ($i = 0; $i < 32; $i++) {
            $candidate = strtolower(generate_shortuid());
            if (! DB::table('cluster')->where('shortuid', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not mint a unique shortuid');
    }

    private function enrollHint(string $shortuid, string $fqdn, string $pkey): string
    {
        $instanceId = (string) (Sysglobal::query()->value('id') ?? '');

        return implode("\n", [
            'Next (catalog + SBC + DIDs — do not leave node-only):',
            "  1) register-tenant.sh --tenant-shortuid {$shortuid} --instance-id {$instanceId} --cname {$fqdn} --fqdn {$fqdn}",
            "     (then ensure meta pkey/label={$pkey} via Gatekeeper/Fleet or meta patch)",
            '  2) Fleet → Tenants → Register on SBC (or Gatekeeper domain enroll)',
            '  3) Fleet → DIDs → Allocate (hop-1) to this shortuid if PSTN needed — not auto in ingest',
            '  4) SPA Commit on the home; desk/SIPp smoke',
            'See FLEET_BUILT_TENANT_INGEST_REQUIREMENTS.md · FLEET_DID_HOP1_LOCK.md · LAB_FLEET_TENANTS.md',
        ]);
    }

    private function requireReadableDb(string $sourceDbPath): string
    {
        $path = realpath($sourceDbPath) ?: $sourceDbPath;
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("Source DB not readable: {$sourceDbPath}");
        }

        return $path;
    }

    private function openSqlite(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 30000');

        return $pdo;
    }

    private function openHomePdo(): \PDO
    {
        // Same pattern as TenantMobilityService::openPdo — file sqlite shared with Laravel.
        $path = (string) config('database.connections.sqlite.database');
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 30000');

        return $pdo;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1"
        );
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        return in_array($column, $this->tableColumns($pdo, $table), true);
    }

    /** @return list<string> */
    private function tableColumns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_column($rows, 'name');
    }
}
