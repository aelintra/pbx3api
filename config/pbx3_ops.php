<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fleet ops — misconfigured phone REGISTER loops (whitelist-gated)
    |--------------------------------------------------------------------------
    |
    | Scans /var/log/asterisk/messages for auth failures whose source IP is in
    | Fail2ban ignoreip (pbx3-jails.conf). Emits to Gatekeeper notify mail.
    | Do not ban those IPs — ops fix the handset.
    |
    | Enable with PBX3_OPS_REGISTER_LOOP_ENABLED=true and Gatekeeper URL/token
    | (same PBX3_GATEKEEPER_* as recordings, or dedicated overrides below).
    |
    */

    'register_loop_enabled' => filter_var(env('PBX3_OPS_REGISTER_LOOP_ENABLED', false), FILTER_VALIDATE_BOOL),

    'asterisk_messages_path' => env('PBX3_OPS_ASTERISK_MESSAGES', '/var/log/asterisk/messages'),

    'fail2ban_jail_path' => env(
        'PBX3_OPS_FAIL2BAN_JAIL',
        '/etc/fail2ban/jail.d/pbx3-jails.conf'
    ),

    'state_path' => env('PBX3_OPS_REGISTER_STATE', storage_path('app/ops-register-loop.json')),

    /** Failures in window before emit (align with asterisk jail maxretry). */
    'threshold' => (int) env('PBX3_OPS_REGISTER_THRESHOLD', 5),

    'window_seconds' => (int) env('PBX3_OPS_REGISTER_WINDOW', 600),

    /** Local cooldown before re-emit for same ext|ip (Gatekeeper also throttles). */
    'emit_cooldown_seconds' => (int) env('PBX3_OPS_REGISTER_COOLDOWN', 1800),

    'gatekeeper_url' => env('PBX3_OPS_GATEKEEPER_URL', env('PBX3_GATEKEEPER_URL', '')),

    'gatekeeper_token' => env('PBX3_OPS_GATEKEEPER_TOKEN', env('PBX3_GATEKEEPER_TOKEN', '')),

    'gatekeeper_http_verify' => filter_var(
        env('PBX3_OPS_GATEKEEPER_HTTP_VERIFY', env('PBX3_GATEKEEPER_HTTP_VERIFY', true)),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Fleet ops — Egress Unavail (PJSIP qualify)
    |--------------------------------------------------------------------------
    |
    | Polls AMI ContactStatusDetail via FleetPostureService. Emits Gatekeeper
    | ops-events on Avail↔Unavail (hysteresis; first run seeds without mail).
    |
    */

    'egress_unavail_notify_enabled' => filter_var(
        env('PBX3_OPS_EGRESS_UNAVAIL_NOTIFY', false),
        FILTER_VALIDATE_BOOL
    ),

    'egress_state_path' => env('PBX3_OPS_EGRESS_STATE', storage_path('app/ops-egress-qualify.json')),

    /** Consecutive Unavail ticks before down notify (mirror Gatekeeper /up misses). */
    'egress_miss_threshold' => (int) env('PBX3_OPS_EGRESS_MISS_THRESHOLD', 2),

];
