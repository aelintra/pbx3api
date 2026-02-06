<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ability lexicon
    |--------------------------------------------------------------------------
    |
    | Single source of truth for token/user ability names. Key = ability string
    | (used in middleware and tokens). Value = short description. Use
    | array_keys(config('abilities.abilities')) for a list of valid names.
    |
    | Each ability is a string (e.g. "admin", "viewer", "extensions:read").
    | A user or token has a list of abilities – an array of those strings:
    |
    |   users.abilities                  – JSON array, e.g. ["admin"] or ["viewer", "extensions:read"]
    |   personal_access_tokens.abilities – same: JSON array of strings
    |   whoami response                  – "abilities": ["admin"] (array of strings)
    |
    | So: one ability = one string. One user/token = many abilities = array of strings.
    |
    */

    'abilities' => [
        'admin' => 'Full access: users, all resources, system commands.',
        // Add more as needed, e.g.:
        // 'operator' => 'Manage extensions, queues, routes; no user management.',
        // 'viewer' => 'Read-only access to tenants, extensions, trunks.',
        // 'extensions:read' => 'View extensions only.',
        // 'extensions:write' => 'Create/update/delete extensions.',
        // 'cluster:default' => 'Access to default cluster.',
    ],

];
