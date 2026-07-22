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
    | Each ability is a string (e.g. "admin", "tenant", "recordings").
    | A user or token has a list of abilities – an array of those strings:
    |
    |   users.abilities                  – JSON array, e.g. ["admin"] or ["tenant", "recordings"]
    |   personal_access_tokens.abilities – same: JSON array of strings
    |   whoami response                  – "abilities": ["tenant"] (array of strings)
    |
    | So: one ability = one string. One user/token = many abilities = array of strings.
    |
    | In the DB, users.abilities must be valid JSON for an array (e.g. ["admin"]).
    | Storing the plain string admin is wrong; the column is cast to array and needs JSON.
    |
    | Instance user privileges: see pbx3spa/workingdocs/INSTANCE_USER_PRIVILEGES_REQUIREMENTS.md
    | (admin = instance-local; tenant/recordings = portable customer users + allowed_clusters).
    |
    */

    'abilities' => [
        'admin' => 'Full instance access: users, trunks/routes, system, all tenants.',
        'tenant' => 'Tenant ops within allowed_clusters (extensions, queues, CoS, CDR, commit).',
        'recordings' => 'Listen/download call recordings within allowed_clusters (additive).',
    ],

];
