<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instance log ship to org bucket (Phase 1) + retention knobs (Phase 5)
    |--------------------------------------------------------------------------
    |
    | Rotated syslog / Asterisk messages / CDR CSV →
    | s3://{PBX3_ORG_BUCKET}/instances/{ksuid}/logs/{class}/{stamp}/…
    | Local rotation: /etc/logrotate.d/pbx3-asterisk-logs (+ system rsyslog).
    | Spec: FLEET_LOG_RETENTION_REQUIREMENTS.md
    |
    | Effective local_days / s3_maxage_days = env defaults merged with optional
    | override file (SPA/API writable — not .env rewrites).
    |
    */

    'upload_enabled' => filter_var(env('PBX3_LOG_UPLOAD_ENABLED', true), FILTER_VALIDATE_BOOL),

    's3_tagging' => filter_var(env('PBX3_LOG_S3_TAGGING', true), FILTER_VALIDATE_BOOL),

    /** State file of already-shipped local paths (inode+mtime fingerprints). */
    'state_path' => env('PBX3_LOG_SHIP_STATE', '/opt/pbx3/var/log-ship/state.json'),

    /** SPA/API override for retention knobs (merged over env). */
    'retention_override_path' => env('PBX3_LOG_RETENTION_OVERRIDE', '/opt/pbx3/var/log-retention.json'),

    /** Presigned GET TTL for S3 archive download (Phase 5). */
    'presigned_ttl_minutes' => (int) env('PBX3_LOG_PRESIGNED_TTL_MINUTES', 15),

    /**
     * Local hot retain (days). logrotate rotate count should match.
     * Overridable via retention_override_path.
     */
    'local_days' => [
        'syslog' => (int) env('PBX3_LOG_LOCAL_DAYS_SYSLOG', 7),
        'asterisk-messages' => (int) env('PBX3_LOG_LOCAL_DAYS_MESSAGES', 7),
        'cdr' => (int) env('PBX3_LOG_LOCAL_DAYS_CDR', 7),
    ],

    /** S3 lifecycle hint (written to instances/{id}/logs/policy.json). */
    's3_maxage_days' => [
        'syslog' => (int) env('PBX3_LOG_S3_MAXAGE_SYSLOG', 30),
        'asterisk-messages' => (int) env('PBX3_LOG_S3_MAXAGE_MESSAGES', 30),
        'cdr' => (int) env('PBX3_LOG_S3_MAXAGE_CDR', 60),
    ],

    /**
     * Globs per class (rotated segments only — never the live open file).
     * Syslog: system rsyslog rotation. Asterisk: pbx3-asterisk-logs.
     */
    'sources' => [
        'syslog' => [
            '/var/log/syslog.[0-9]*',
            '/var/log/syslog.*.gz',
        ],
        'asterisk-messages' => [
            '/var/log/asterisk/messages.[0-9]*',
            '/var/log/asterisk/messages.*.gz',
        ],
        'cdr' => [
            '/var/log/asterisk/cdr-csv/Master.csv.[0-9]*',
            '/var/log/asterisk/cdr-csv/Master.csv.*.gz',
        ],
    ],

];
