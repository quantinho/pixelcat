<?php

/**
 * Delivers one Pixelforge webhook to the app in a fresh PHP process, for the concurrency test.
 *
 * Usage: php tests/Concurrency/bin/deliver-webhook.php <body-file> <start-at>
 *
 * The body is signed here with the configured webhook secret and handled by the real HTTP
 * kernel (route, middleware, controller), so two copies of this script race exactly like two
 * Pixelforge deliveries hitting two web servers. <start-at> is a Unix time with microseconds;
 * the script waits until then so both processes send at the same moment.
 * Prints the HTTP status code.
 */

use App\Pixelforge\WebhookSignature;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Like every test: never call a real API from here.
Http::preventStrayRequests();

[, $bodyFile, $startAt] = $argv;
$body = (string) file_get_contents($bodyFile);

/** @var array{id: string, type: string} $event */
$event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

$request = Request::create('/webhooks/pixelforge', 'POST', server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
    'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'],
    'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
    'HTTP_PIXELFORGE_SIGNATURE' => WebhookSignature::sign($body, (string) config('pixelforge.webhook_secret'), time()),
], content: $body);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo $response->getStatusCode();
