<?php

namespace App\Services\Tenant;

use Illuminate\Support\Facades\DB;

/**
 * T4/T5 — wipe-list drift and orphan cluster-scoped rows (tenant delete integrity).
 */
class TenantWipeIntegrityService
{
    /**
     * Tables in tenant schema SQL that have a cluster column (excluding cluster itself).
     * Trunks live elsewhere / instance-owned — not in sqlite_create_tenant wipe set.
     *
     * @return list<string>
     */
    public function clusterScopedTablesFromSchema(?string $schemaPath = null): array
    {
        $path = $schemaPath ?? (string) config('pbx3_directory.tenant_schema_sql');
        if ($path === '' || ! is_file($path)) {
            throw new \RuntimeException("Tenant schema SQL not found: {$path}");
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read tenant schema: {$path}");
        }

        $tables = [];
        if (! preg_match_all(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+"?(\w+)"?\s*\((.*?)\)\s*;/is',
            $sql,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        foreach ($matches as $m) {
            $name = strtolower($m[1]);
            $body = $m[2];
            if ($name === 'cluster') {
                continue;
            }
            if (! preg_match('/"cluster"|\bcluster\s+/i', $body)) {
                continue;
            }
            $tables[] = $name;
        }

        sort($tables);

        return array_values(array_unique($tables));
    }

    /**
     * T5 — schema cluster-scoped tables must be covered by TENANT_DATA_TABLES.
     *
     * @return array{ok: bool, missing_from_wipe_list: list<string>, extra_in_wipe_list: list<string>, schema_tables: list<string>}
     */
    public function compareWipeListToSchema(?string $schemaPath = null): array
    {
        $schemaTables = $this->clusterScopedTablesFromSchema($schemaPath);
        $wipe = TenantMobilityService::TENANT_DATA_TABLES;
        $wipeSet = array_fill_keys($wipe, true);
        $schemaSet = array_fill_keys($schemaTables, true);

        $missing = [];
        foreach ($schemaTables as $t) {
            if (! isset($wipeSet[$t])) {
                $missing[] = $t;
            }
        }
        $extra = [];
        foreach ($wipe as $t) {
            if (! isset($schemaSet[$t])) {
                $extra[] = $t;
            }
        }

        return [
            'ok' => $missing === [],
            'missing_from_wipe_list' => $missing,
            'extra_in_wipe_list' => $extra,
            'schema_tables' => $schemaTables,
        ];
    }

    /**
     * T4 — child rows whose cluster is not any live cluster.id|shortuid|pkey.
     *
     * @return array{ok: bool, total: int, by_table: array<string, int>, samples: array<string, list<array<string, mixed>>>}
     */
    public function auditOrphanClusterRows(int $samplePerTable = 5): array
    {
        $valid = $this->validClusterIdentifiers();
        $byTable = [];
        $samples = [];
        $total = 0;

        foreach (TenantMobilityService::TENANT_DATA_TABLES as $table) {
            if (! $this->tableExists($table) || ! $this->tableHasColumn($table, 'cluster')) {
                continue;
            }

            $orphans = [];
            $rows = DB::table($table)->select(['cluster'])->distinct()->get();
            foreach ($rows as $row) {
                $c = trim((string) ($row->cluster ?? ''));
                if ($c === '' || isset($valid[$c])) {
                    continue;
                }
                $count = (int) DB::table($table)->where('cluster', $c)->count();
                if ($count < 1) {
                    continue;
                }
                $orphans[$c] = $count;
                $total += $count;
            }

            if ($orphans === []) {
                continue;
            }

            $byTable[$table] = array_sum($orphans);
            $sampleRows = [];
            foreach (array_keys($orphans) as $badCluster) {
                $chunk = DB::table($table)
                    ->where('cluster', $badCluster)
                    ->limit($samplePerTable)
                    ->get()
                    ->map(static fn ($r) => (array) $r)
                    ->all();
                foreach ($chunk as $r) {
                    $sampleRows[] = $r;
                    if (count($sampleRows) >= $samplePerTable) {
                        break 2;
                    }
                }
            }
            $samples[$table] = $sampleRows;
        }

        return [
            'ok' => $total === 0,
            'total' => $total,
            'by_table' => $byTable,
            'samples' => $samples,
        ];
    }

    /** @return array<string, true> */
    private function validClusterIdentifiers(): array
    {
        $valid = [];
        if (! $this->tableExists('cluster')) {
            return $valid;
        }
        foreach (DB::table('cluster')->get(['id', 'shortuid', 'pkey']) as $row) {
            foreach ([$row->id ?? null, $row->shortuid ?? null, $row->pkey ?? null] as $v) {
                if ($v !== null && trim((string) $v) !== '') {
                    $valid[trim((string) $v)] = true;
                }
            }
        }

        return $valid;
    }

    private function tableExists(string $table): bool
    {
        try {
            $row = DB::selectOne(
                "SELECT 1 AS ok FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
                [$table]
            );

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            foreach (DB::select("PRAGMA table_info({$table})") as $col) {
                if (strcasecmp((string) ($col->name ?? ''), $column) === 0) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
