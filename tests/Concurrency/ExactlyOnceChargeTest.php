<?php

use App\Billing\Ledger;
use App\Enums\DeliveryOutcome;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakePixelforge;

/**
 * SC-003 / US2 scenario 7: the same success processed by two requests at the same moment
 * yields exactly one charge. Two real PHP processes (two database connections) race, so this
 * exercises the database guarantees (unique event id, row lock, ledger unique index), not
 * just the application checks.
 *
 * The data must be committed for the other processes to see it, so these tests truncate
 * instead of wrapping each test in a transaction.
 */
// The test case comes from tests/Pest.php; child processes need committed rows, so truncate.
pest()->use(DatabaseTruncation::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->balanceBeforeSubmitting = app(Ledger::class)->balance($this->user);
    $this->generation = Generation::factory()->for($this->user)->processing()->create(['estimate_max' => 500_000_000]);
    app(Ledger::class)->hold($this->generation, 500_000_000);
});

// Leave nothing behind for the transaction-wrapped feature tests.
afterEach(fn () => $this->truncateTablesForAllConnections());

/**
 * Deliver each body from its own PHP process, all starting at the same instant.
 *
 * @param  list<string>  $bodies
 * @return list<string> HTTP status codes
 */
function deliverConcurrently(array $bodies): array
{
    $startAt = sprintf('%.6F', microtime(true) + 2.0); // leaves time for both apps to boot
    $files = [];

    foreach ($bodies as $body) {
        $file = tempnam(sys_get_temp_dir(), 'webhook');
        file_put_contents($file, $body);
        $files[] = $file;
    }

    $connection = config('database.connections.pgsql');
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        // Follow-up jobs are queued in the test database, never run against an API.
        'QUEUE_CONNECTION' => 'database',
        'PIXELFORGE_BASE_URL' => 'https://pixelforge.test/v1',
        'PIXELFORGE_WEBHOOK_SECRET' => FakePixelforge::WEBHOOK_SECRET,
        'PIXELFORGE_FAKE' => 'false',
    ];

    $results = Process::pool(function (Pool $pool) use ($files, $startAt, $env) {
        foreach ($files as $file) {
            $pool->path(base_path())
                ->env($env)
                ->timeout(30)
                ->command([PHP_BINARY, 'tests/Concurrency/bin/deliver-webhook.php', $file, $startAt]);
        }
    })->start()->wait();

    array_map('unlink', $files);

    return $results->collect()->map(function ($result) {
        expect($result->successful())->toBeTrue($result->errorOutput());

        return trim($result->output());
    })->values()->all();
}

function chargesFor(Generation $generation): int
{
    return LedgerEntry::query()
        ->where('generation_id', $generation->id)
        ->where('type', LedgerEntryType::Charge)
        ->count();
}

it('charges once when the same event is delivered twice at the same moment', function () {
    $body = FakePixelforge::webhookFor(FakePixelforge::generationFor($this->generation, 'succeeded'))['body'];

    $statuses = deliverConcurrently([$body, $body]);

    expect($statuses)->toBe(['204', '204'])
        ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(chargesFor($this->generation))->toBe(1)
        ->and(app(Ledger::class)->balance($this->user))->toBe($this->balanceBeforeSubmitting - FakePixelforge::WORKED_EXAMPLE_USER_PRICE)
        ->and(WebhookDelivery::query()->pluck('outcome')->map->value->sort()->values()->all())
        ->toBe([DeliveryOutcome::Applied->value, DeliveryOutcome::Duplicate->value]);
});

it('charges once when the same success arrives under two event ids at the same moment', function () {
    $object = FakePixelforge::generationFor($this->generation, 'succeeded');

    $statuses = deliverConcurrently([
        FakePixelforge::webhookFor($object)['body'],
        FakePixelforge::webhookFor($object)['body'],
    ]);

    expect($statuses)->toBe(['204', '204'])
        ->and(chargesFor($this->generation))->toBe(1)
        ->and(LedgerEntry::query()->where('generation_id', $this->generation->id)->where('type', LedgerEntryType::HoldRelease)->count())->toBe(1)
        ->and(WebhookDelivery::query()->pluck('outcome')->map->value->sort()->values()->all())
        ->toBe([DeliveryOutcome::Applied->value, DeliveryOutcome::OutOfOrder->value]);
});
