<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation expiry
    |--------------------------------------------------------------------------
    |
    | Implementation default under PO-P2-INVITE (TB-INVITE-001 field catalogue
    | remains Pending). Seven days is the documented Phase 2 default.
    |
    */

    'expires_hours' => (int) env('INVITATION_EXPIRES_HOURS', 168),

    /*
    |--------------------------------------------------------------------------
    | Token entropy
    |--------------------------------------------------------------------------
    */

    'token_bytes' => (int) env('INVITATION_TOKEN_BYTES', 32),

];
