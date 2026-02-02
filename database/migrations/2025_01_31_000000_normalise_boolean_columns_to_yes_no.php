<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * One-off fixer: normalise boolean-like TEXT columns to YES/NO.
 *
 * Historical data may contain ON/OFF, 1/0, true/false. This migration maps
 * them to YES/NO so the rest of the app can assume a single convention.
 * See pbx3-frontend/workingdocs/BOOLEAN_STANDARDISATION.md.
 */
return new class extends Migration
{
    /** Tables and TEXT columns that are boolean-like (will be normalised to YES/NO). */
    private array $booleanColumns = [
        'inroutes'          => ['active', 'callprogress', 'moh', 'swoclip'],
        'trunks'            => ['active', 'callprogress', 'moh', 'swoclip'],
        'cos'               => ['active', 'defaultclosed', 'defaultopen', 'orideclosed', 'orideopen'],
        'dateseg'           => ['active'],
        'ipphone'           => ['active'],
        'ipphonecosopen'     => ['active'],
        'ipphonecosclosed'   => ['active'],
        'ivrmenu'           => ['active', 'listenforext'],
        'page'              => ['active'],
        'queue'             => ['active'],
        'route'             => ['active', 'auth'],
        'globals'           => ['COSSTART', 'FQDNINSPECT', 'SENDEDOMAIN', 'SIPFLOOD'],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        $quote = $driver === 'sqlite' ? '"' : '`';

        foreach ($this->booleanColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $tableQuoted = $quote . $table . $quote;
                $colQuoted = $quote . $column . $quote;

                // Normalise: ON/1/true/yes -> YES; OFF/0/false/no -> NO; else leave as-is
                $sql = "UPDATE {$tableQuoted} SET {$colQuoted} = CASE "
                    . "WHEN UPPER(TRIM(CAST({$colQuoted} AS TEXT))) IN ('ON','1','TRUE','YES') THEN 'YES' "
                    . "WHEN UPPER(TRIM(CAST({$colQuoted} AS TEXT))) IN ('OFF','0','FALSE','NO') THEN 'NO' "
                    . "ELSE {$colQuoted} END "
                    . "WHERE {$colQuoted} IS NOT NULL";

                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        // Irreversible: we do not store original values to restore ON/OFF/1/0.
    }
};
