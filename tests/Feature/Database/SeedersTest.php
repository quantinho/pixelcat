<?php

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoGenerationsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Support\Facades\Hash;

/** Set (or with null, clear) an environment variable the way the process environment does. */
function setSeedEnv(string $key, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        return;
    }

    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

beforeEach(function () {
    setSeedEnv('ADMIN_PASSWORD', 'admin-test-password');
    setSeedEnv('DEMO_USER_PASSWORD', 'demo-test-password');
});

afterEach(function () {
    setSeedEnv('ADMIN_PASSWORD', null);
    setSeedEnv('DEMO_USER_PASSWORD', null);
});

/** @return list<string> ledger entry types for a generation, in order */
function seededEntryTypes(Generation $generation): array
{
    return LedgerEntry::query()->where('generation_id', $generation->id)->orderBy('id')
        ->get()->map(fn (LedgerEntry $entry) => $entry->type->value)->all();
}

it('creates the admin and the demo user with passwords from the environment', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', UsersSeeder::ADMIN_EMAIL)->sole();
    $demo = User::query()->where('email', UsersSeeder::DEMO_EMAIL)->sole();

    expect($admin->is_admin)->toBeTrue()
        ->and(Hash::check('admin-test-password', $admin->password))->toBeTrue()
        ->and($demo->is_admin)->toBeFalse()
        ->and(Hash::check('demo-test-password', $demo->password))->toBeTrue();
});

it('gives both users the welcome credit', function () {
    $this->seed(DatabaseSeeder::class);

    foreach ([UsersSeeder::ADMIN_EMAIL, UsersSeeder::DEMO_EMAIL] as $email) {
        $grants = LedgerEntry::query()
            ->where('user_id', User::query()->where('email', $email)->value('id'))
            ->where('type', LedgerEntryType::Grant)
            ->get();

        expect($grants)->toHaveCount(1)
            ->and($grants[0]->amount)->toBe(2_000_000_000)
            ->and($grants[0]->description)->toBe('Welcome credit');
    }
});

it('refuses to run without passwords', function (string $missing) {
    setSeedEnv($missing, '');

    expect(fn () => $this->seed(DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, $missing);

    expect(User::query()->count())->toBe(0);
})->with(['ADMIN_PASSWORD', 'DEMO_USER_PASSWORD']);

it('seeds fake generations in every state outside production', function () {
    $this->seed(DatabaseSeeder::class);

    $statuses = Generation::query()->distinct()->pluck('status')->map->value->sort()->values()->all();

    expect($statuses)->toBe(['failed', 'processing', 'queued', 'submitting', 'succeeded', 'waiting'])
        ->and(Generation::query()->where('is_anomaly', true)->count())->toBeGreaterThan(0)
        ->and(Generation::query()->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '<', now()->startOfDay())->count())->toBeGreaterThan(0);
});

it('writes the ledger entries each seeded generation would have', function () {
    $this->seed(DatabaseSeeder::class);

    foreach (Generation::query()->get() as $generation) {
        $expected = match (true) {
            $generation->status === GenerationStatus::Succeeded => ['hold', 'charge', 'hold_release'],
            $generation->status === GenerationStatus::Failed => ['hold', 'hold_release'],
            default => ['hold'],
        };

        expect(seededEntryTypes($generation))->toBe($expected, "Generation in {$generation->status->value}");
    }

    $charge = LedgerEntry::query()->where('type', LedgerEntryType::Charge)->get();
    expect((int) $charge->sum('amount'))->toBe(-(int) Generation::query()->where('status', GenerationStatus::Succeeded)->sum('charged'));
});

it('keeps every seeded balance positive and equal to the sum of its entries', function () {
    $this->seed(DatabaseSeeder::class);
    $ledger = app(Ledger::class);

    foreach (User::all() as $user) {
        $held = (int) Generation::query()->where('user_id', $user->id)
            ->whereNotIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->sum('estimate_max');
        $charged = (int) Generation::query()->where('user_id', $user->id)->sum('charged');
        $credited = (int) LedgerEntry::query()->where('user_id', $user->id)
            ->whereIn('type', [LedgerEntryType::Grant, LedgerEntryType::Purchase])->sum('amount');

        expect($ledger->balance($user))->toBe($credited - $charged - $held)
            ->and($ledger->balance($user))->toBeGreaterThan(0);
    }
});

it('seeds no fake generations in production', function () {
    app()->detectEnvironment(fn () => 'production');

    // db:seed asks for confirmation in production; --force is what a deploy passes.
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

    expect(User::query()->count())->toBe(2)
        ->and(Generation::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->whereNot('type', LedgerEntryType::Grant)->count())->toBe(0);
});

it('can run twice without duplicating anything', function () {
    $this->seed(DatabaseSeeder::class);
    $generations = Generation::query()->count();
    $entries = LedgerEntry::query()->count();

    setSeedEnv('DEMO_USER_PASSWORD', 'changed-demo-password');
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(2)
        ->and(Generation::query()->count())->toBe($generations)
        ->and(LedgerEntry::query()->count())->toBe($entries)
        ->and(Hash::check('changed-demo-password', User::query()->where('email', UsersSeeder::DEMO_EMAIL)->value('password')))->toBeTrue();
});

it('refuses to seed fake generations in production even when called directly', function () {
    $this->seed(UsersSeeder::class);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoGenerationsSeeder::class)->run())->toThrow(LogicException::class);
    expect(Generation::query()->count())->toBe(0);
});
