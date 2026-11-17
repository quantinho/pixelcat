<?php

return [

    // User price = provider cost × (100 + markup) / 100, rounded up to the next nanodollar.
    'markup_percent' => (int) env('PIXELCAT_MARKUP_PERCENT', 25),

    // Pixelforge allows 3 queued or processing generations per account.
    'max_active' => (int) env('PIXELCAT_MAX_ACTIVE', 3),

    'max_active_per_user' => (int) env('PIXELCAT_MAX_ACTIVE_PER_USER', 2),

    'daily_cap_per_user' => (int) env('PIXELCAT_DAILY_CAP_PER_USER', 50),

    // A generation waiting for a slot longer than this fails, uncharged.
    'max_wait_minutes' => (int) env('PIXELCAT_MAX_WAIT_MINUTES', 15),

    // Nanodollars granted at sign-up ($2.00).
    'welcome_credit' => (int) env('PIXELCAT_WELCOME_CREDIT', 2_000_000_000),

    // Fake "Buy credits" packs, in whole dollars.
    'credit_packs' => [5, 10, 25],

];
