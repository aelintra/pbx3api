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
    | In the DB, users.abilities must be valid JSON for an array (e.g. ["admin"]).
    | Storing the plain string admin is wrong; the column is cast to array and needs JSON.
    |
    */

    // Next step: add granular abilities (view_trunk, edit_trunk, view_extension, etc.)
    // and optionally split route groups (admin vs tenant). See pbx3spa/workingdocs/ADMIN_PANELS_AND_PERMISSIONS.md.

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
