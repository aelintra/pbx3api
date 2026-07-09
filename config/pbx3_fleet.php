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

];
