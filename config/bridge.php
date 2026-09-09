<?php

return [
    /*
    | AUTH-1: single shared workspace gate password (Captain-approved).
    | Override via BRIDGE_GATE_PASSWORD. Default remains lantis-internal.
    */
    'gate_password' => env('BRIDGE_GATE_PASSWORD', 'lantis-internal'),

    'org_timezone' => 'Europe/Ljubljana',
];
