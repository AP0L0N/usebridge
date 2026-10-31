<?php

// Polymarket desk: server-built copy (freshness badge + banner). The rest of the page copy lives in resources/js/lib/strings-pm.ts.
return [
    'fresh' => 'Fresh',
    'late' => [
        'badge' => 'Late · missed :dow post',
        'title' => 'The :dow ~:time post hasn’t arrived.',
        'body' => 'Figures are from :asof and may have moved.',
    ],
    'stale' => [
        'badge' => 'Stale · :n posts missed',
        'title' => ':n weekday posts missed.',
        'body' => 'Book value and prices are out of date. Check Captain’s weekday desk routine.',
    ],
    'last_post' => 'Last post :when',
];
