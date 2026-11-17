<?php

use App\Billing\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

/*
 * FR-017 / US5 scenario 3: two submissions that together exceed the balance, sent at the
 * same moment, from two real PHP processes with their own database connections.
 *
 * Each process runs the submission's money step (lock the user, check the balance, place
 * the hold) through the Ledger directly; CreateGeneration wraps the same steps.
 *
 * The processes only see committed rows, so this file truncates instead of wrapping each
 * test in a transaction.
 */
pest()->use(DatabaseTruncation::class);

afterEach(function () {
    // Leave no committed rows behind for tests that run after this one.
    $this->truncateTablesForAllConnections();
});

const CONCURRENT_BALANCE = 2_000_000_000;
const CONCURRENT_HOLD = 1_500_000_000;

/**
 * Run place-hold.php for both generations at the same instant.
 *
 * @return list<string> each process's output, sorted
 */
function placeHoldsConcurrently(Generation $first, Generation $second, string $mode): array
{
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
    ];
    // Far enough ahead for both processes to boot before either starts.
    $startAt = sprintf('%.6F', microtime(true) + 2);

    $results = Process::pool(function (Pool $pool) use ($first, $second, $mode, $env, $startAt) {
        foreach ([$first, $second] as $generation) {
            $pool->path(base_path())->env($env)->timeout(30)->command([
                PHP_BINARY, 'tests/Concurrency/bin/place-hold.php',
                $generation->id, (string) CONCURRENT_HOLD, $startAt, $mode,
            ]);
        }
    })->start()->wait();

    $outputs = [];
    foreach ($results->collect() as $result) {
        expect($result->successful())->toBeTrue($result->errorOutput());
        $outputs[] = trim($result->output());
    }
    sort($outputs);

    return $outputs;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    app(Ledger::class)->grant($this->user, CONCURRENT_BALANCE, 'Welcome credit');
    $this->first = Generation::factory()->for($this->user)->create();
    $this->second = Generation::factory()->for($this->user)->create();
});

it('accepts only the affordable one of two simultaneous holds', function () {
    expect(placeHoldsConcurrently($this->first, $this->second, 'lock'))->toBe(['accepted', 'refused']);

    $holds = LedgerEntry::query()->where('user_id', $this->user->id)->where('type', LedgerEntryType::Hold)->get();
    expect($holds)->toHaveCount(1)
        ->and($holds[0]->amount)->toBe(-CONCURRENT_HOLD)
        ->and(app(Ledger::class)->balance($this->user))->toBe(CONCURRENT_BALANCE - CONCURRENT_HOLD);
});

it('overspends without the user lock, which shows the test can catch the race', function () {
    expect(placeHoldsConcurrently($this->first, $this->second, 'no-lock'))->toBe(['accepted', 'accepted']);

    expect(LedgerEntry::query()->where('type', LedgerEntryType::Hold)->count())->toBe(2)
        ->and(app(Ledger::class)->balance($this->user))->toBe(CONCURRENT_BALANCE - 2 * CONCURRENT_HOLD);
});
