<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Call recordings — spool, archive, offload (Phase R1 / R1.5), S3 (S7)
    |--------------------------------------------------------------------------
    |
    | Tier 1 spool: MixMonitor capture (PBX3_RECORDINGS_ROOT).
    | Tier 2 archive: tenant/date tree after offload (PBX3_RECORDINGS_ARCHIVE_ROOT).
    | Tier 3 S3: dedicated PBX3_RECORDINGS_BUCKET via gatekeeper presigns (PCI-shaped, not attested).
    | Telephony never depends on offload or S3 — Rule 1.
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

    /*
    |--------------------------------------------------------------------------
    | S7 — S3 offload (gatekeeper presigns)
    |--------------------------------------------------------------------------
    */

    /** Master switch. Default false until control host + node are wired. */
    'upload_enabled' => filter_var(env('PBX3_RECORDING_UPLOAD_ENABLED', false), FILTER_VALIDATE_BOOL),

    /** Max rows per pbx3:recordings-s3-upload run. */
    'upload_batch' => (int) env('PBX3_RECORDING_UPLOAD_BATCH', 50),

    /** Comma-separated tenant shortuids; empty = all tenants with local rows. */
    'upload_tenants' => env('PBX3_RECORDING_UPLOAD_TENANTS', ''),

    /** e.g. https://control.pbx3.com — no trailing slash required. */
    'gatekeeper_url' => env('PBX3_GATEKEEPER_URL', ''),

    /** Bearer accepted by gatekeeper (break-glass or fleet service token). */
    'gatekeeper_token' => env('PBX3_GATEKEEPER_TOKEN', ''),

    'gatekeeper_http_verify' => filter_var(env('PBX3_GATEKEEPER_HTTP_VERIFY', true), FILTER_VALIDATE_BOOL),

    'presign_ttl_seconds' => (int) env('PBX3_RECORDING_PRESIGN_TTL', 900),

];
