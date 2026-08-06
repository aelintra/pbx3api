<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fleet node posture (Phase A — Egress to SBC)
    |--------------------------------------------------------------------------
    |
    | When true, outbound routes use fixed Egress trunk; SPA hides path pickers.
    | Auto-detected if an active trunks.pkey = Egress row exists (see FleetPostureService).
    |
    */

    'mode' => filter_var(env('PBX3_FLEET_MODE', false), FILTER_VALIDATE_BOOL),

    'sbc_egress_host' => env('PBX3_SBC_EGRESS_HOST', 'sbc.pbx3.com'),

    'sbc_egress_port' => (int) env('PBX3_SBC_EGRESS_PORT', 5060),

    'egress_trunk_pkey' => 'Egress',

    'egress_failover_pkey' => 'EgressFailover',

    /*
    |--------------------------------------------------------------------------
    | Fleet service token (S8.10 — control plane → this node)
    |--------------------------------------------------------------------------
    |
    | Shared bearer for /api/fleet/* mobility endpoints. Not a Sanctum admin
    | token. Must match the token the gatekeeper uses when calling this node.
    |
    */

    'service_token' => env('PBX3_FLEET_SERVICE_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Gatekeeper catalog (node → control) — sitename ≡ label dual-write
    |--------------------------------------------------------------------------
    |
    | Same PBX3_GATEKEEPER_* as recordings/ops. When URL+token are set, Site Name
    | save must PATCH catalog label or the whole save fails (FLEET_NAMING_LOCK).
    | Break-glass or fleet token with fleet_instances. Solo nodes leave unset.
    |
    */

    'gatekeeper_url' => env('PBX3_GATEKEEPER_URL', ''),

    'gatekeeper_token' => env('PBX3_GATEKEEPER_TOKEN', ''),

    'gatekeeper_http_verify' => filter_var(
        env('PBX3_GATEKEEPER_HTTP_VERIFY', true),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Dial cohort / Site Groups (C2+)
    |--------------------------------------------------------------------------
    |
    | When true, Sanctum forbids create/update/delete of dial prefixes that
    | target another tenant (403 → Fleet → Site Groups). Managed source=cohort
    | rows are always Sanctum read-only regardless of this flag.
    | Lab hand CRUD: leave false until Site Groups replace wild prefixes.
    |
    */

    'dial_cohort' => filter_var(env('PBX3_DIAL_COHORT', false), FILTER_VALIDATE_BOOL),

];
