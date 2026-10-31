<?php

/*
| Cloudflare Turnstile on the sign-up form (docs/MULTI-USER-PLAN.md §0, relayed decision 1). Keys come from the
| Cloudflare dashboard (The Many / Tilen). Without a secret the Turnstile check is skipped; the honeypot and the
| minimum fill time always apply. With a secret it fails closed (network error or timeout = refused).
*/
return [
    'site_key' => env('TURNSTILE_SITE_KEY'),
    'secret_key' => env('TURNSTILE_SECRET_KEY'),
    'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    'timeout' => 5,
];
