<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TOTP issuer label (authenticator app)
    |--------------------------------------------------------------------------
    |
    | Distinct from SBC default "Aelintra SBC". Override per instance if wanted.
    |
    */
    'totp_issuer' => env('PBX3_TOTP_ISSUER', 'Aelintra PBX'),

    /*
    |--------------------------------------------------------------------------
    | Login 2FA challenge TTL (seconds)
    |--------------------------------------------------------------------------
    */
    'challenge_ttl' => (int) env('PBX3_TOTP_CHALLENGE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Max verify attempts per challenge
    |--------------------------------------------------------------------------
    */
    'challenge_max_attempts' => (int) env('PBX3_TOTP_CHALLENGE_MAX_ATTEMPTS', 5),

];
