<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Call recordings — spool, archive, offload (Phase R1 / R1.5)
    |--------------------------------------------------------------------------
    |
    | Tier 1 spool: MixMonitor capture (PBX3_RECORDINGS_ROOT).
    | Tier 2 archive: tenant/date tree after offload (PBX3_RECORDINGS_ARCHIVE_ROOT).
    | Telephony never depends on offload — Rule 1.
    |
    */

    'spool_root' => env('PBX3_RECORDINGS_ROOT', '/var/spool/asterisk/monitor'),

    'archive_root' => env('PBX3_RECORDINGS_ARCHIVE_ROOT', '/opt/pbx3/media/recordings'),

    'offload_enabled' => filter_var(env('PBX3_RECORDINGS_OFFLOAD_ENABLED', true), FILTER_VALIDATE_BOOL),

    /** Minutes after mtime before a spool .wav is considered stable for offload. */
    'stable_minutes' => (int) env('PBX3_RECORDINGS_STABLE_MINUTES', 5),

    /** SQL applied on first recordings job if the table is missing. */
    'schema_sql' => env(
        'PBX3_RECORDINGS_SCHEMA_SQL',
        '/opt/pbx3/db/db_sql/sqlite_add_recordings_table.sql'
    ),

];
