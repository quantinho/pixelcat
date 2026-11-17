<?php

return [

    /*
    | Pixelforge API. Use one API key per environment: each key has its own
    | webhook URL, signing secret and usage report.
    */

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Seconds a webhook signature timestamp may differ from server time.
    'webhook_tolerance' => 300,

    // Seconds to wait for Pixelforge before treating a request as ambiguous.
    'timeout' => 10,

    // Local-only fake API and webhook simulator. Never enabled in production.
    'fake' => (bool) env('PIXELFORGE_FAKE', false),

];
