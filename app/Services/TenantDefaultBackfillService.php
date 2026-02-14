<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time backfill: set NULL columns in existing rows to the new schema defaults.
 * DEFAULT in SQLite only applies to new INSERTs; existing rows keep NULL until updated.
 *
 * Run: php artisan tenant:backfill-defaults
 * Uses the default DB connection (.env DB_DATABASE).
 * For multiple tenant DBs, run once per DB (e.g. change .env or pass --database=... if we add that).
 */
class TenantDefaultBackfillService
{
    /**
     * Table => list of [column, default value].
     * Value is the literal to set (string or int); we bind it in the UPDATE.
     */
    protected static array $backfillMap = [
        'cluster' => [
            ['masteroclo', 'AUTO'],
        ],
        'ipphone' => [
            ['celltwin', 'OFF'],
        ],
        'ivrmenu' => [
            ['cluster', 'default'],
            ['timeout', '30'],
        ],
        'inroutes' => [
            ['cluster', 'default'],
            ['openroute', 'None'],
            ['closeroute', 'None'],
        ],
        'queue' => [
            ['cluster', 'default'],
            ['devicerec', 'None'],
        ],
        'route' => [
            ['cluster', 'default'],
        ],
        'trunks' => [
            ['cluster', 'default'],
            ['openroute', 'None'],
            ['closeroute', 'None'],
            ['devicerec', 'default'],
        ],
        'cos' => [
            ['cluster', 'default'],
        ],
        'ipphonecosopen' => [
            ['cluster', 'default'],
        ],
        'ipphonecosclosed' => [
            ['cluster', 'default'],
        ],
        'page' => [
            ['cluster', 'default'],
        ],
    ];

    /**
     * Run all backfill UPDATEs. Returns summary of rows updated per table.column.
     *
     * @return array<string, array<string, int>> table => (column => count)
     */
    public function run(): array
    {
        $summary = [];

        foreach (self::$backfillMap as $table => $columns) {
            foreach ($columns as [$column, $value]) {
                $count = $this->backfillColumn($table, $column, $value);
                if ($count >= 0) {
                    $summary[$table] = $summary[$table] ?? [];
                    $summary[$table][$column] = $count;
                }
            }
        }

        return $summary;
    }

    /**
     * Run UPDATE table SET column = ? WHERE column IS NULL. Returns rows updated or -1 on error.
     */
    protected function backfillColumn(string $table, string $column, string|int $value): int
    {
        try {
            $affected = DB::table($table)->whereNull($column)->update([$column => $value]);
            return $affected;
        } catch (\Throwable $e) {
            Log::warning("TenantDefaultBackfill: skip {$table}.{$column}: " . $e->getMessage());
            return -1;
        }
    }
}
