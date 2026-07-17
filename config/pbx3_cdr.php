<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instance Asterisk CDR SQLite (Phase 6)
    |--------------------------------------------------------------------------
    |
    | Asterisk cdr_sqlite3_custom writes /var/log/asterisk/master.db.
    | pbx3api reads it read-only for GET /cdr; prune uses local_days.cdr.
    |
    */

    'sqlite_path' => env('PBX3_CDR_SQLITE_PATH', '/var/log/asterisk/master.db'),

    /** Default page size for GET /cdr */
    'default_limit' => (int) env('PBX3_CDR_DEFAULT_LIMIT', 100),

    /** Hard cap on page size */
    'max_limit' => (int) env('PBX3_CDR_MAX_LIMIT', 500),

];
