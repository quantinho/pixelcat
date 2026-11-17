<?php

use App\Billing\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, string> */
function registrationForm(string $email = 'new@example.com'): array
{
    return [
        'name' => 'New User',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
}

describe('welcome credit', function () {
    it('grants $2.00 to every new user at sign-up', function () {
        $this->post(route('register.store'), registrationForm())->assertRedirect();

        $user = User::query()->where('email', 'new@example.com')->sole();
        $entries = LedgerEntry::query()->where('user_id', $user->id)->get();

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->type)->toBe(LedgerEntryType::Grant)
            ->and($entries[0]->amount)->toBe(2_000_000_000)
            ->and($entries[0]->description)->toBe('Welcome credit')
            ->and($entries[0]->generation_id)->toBeNull()
            ->and(app(Ledger::class)->balance($user))->toBe(2_000_000_000);
    });

    it('uses the configured welcome credit', function () {
        config(['pixelcat.welcome_credit' => 500_000_000]);

        $this->post(route('register.store'), registrationForm())->assertRedirect();

        expect(app(Ledger::class)->balance(User::query()->where('email', 'new@example.com')->sole()))
            ->toBe(500_000_000);
    });

    it('creates no user when the grant cannot be written', function () {
        // A negative grant breaks the ledger's sign check, so the whole sign-up rolls back.
        config(['pixelcat.welcome_credit' => -1]);

        $this->post(route('register.store'), registrationForm());

        expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse()
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('grants nothing when sign-up is invalid', function () {
        $this->post(route('register.store'), [...registrationForm(), 'password_confirmation' => 'other'])
            ->assertSessionHasErrors('password');

        expect(User::query()->count())->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });
});

describe('credits page', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->ledger = app(Ledger::class);
    });

    it('sends guests to the login page', function () {
        $this->get('/credits')->assertRedirect(route('login'));
    });

    it('shows the packs, the balance and the latest entries newest first', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'));
        $this->ledger->grant($this->user, 2_000_000_000, 'Welcome credit');
        $this->travel(1)->minutes();
        $generation = Generation::factory()->for($this->user)->create(['estimate_max' => 86_356_250]);
        $this->ledger->hold($generation, 86_356_250);
        $this->ledger->grant(User::factory()->create(), 9_000_000_000, 'Someone else');

        $this->actingAs($this->user)->get('/credits')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credits/Index')
                ->where('packs', [
                    ['amount' => 5, 'label' => '$5.00'],
                    ['amount' => 10, 'label' => '$10.00'],
                    ['amount' => 25, 'label' => '$25.00'],
                ])
                ->where('entries', [
                    [
                        'type' => 'hold',
                        'description' => 'Held while generating',
                        'amount' => ['display' => '-$0.0864', 'exact' => '-0.086356250'],
                        'at' => '2026-11-17T10:01:00+00:00',
                    ],
                    [
                        'type' => 'grant',
                        'description' => 'Welcome credit',
                        'amount' => ['display' => '$2.0000', 'exact' => '2.000000000'],
                        'at' => '2026-11-17T10:00:00+00:00',
                    ],
                ])
                ->where('balance', ['display' => '$1.9136', 'exact' => '1.913643750']));
    });

    it('shows only the latest 50 entries', function () {
        foreach (range(1, 55) as $i) {
            $this->ledger->purchase($this->user, $i * 1_000_000_000);
        }

        $this->actingAs($this->user)->get('/credits')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 50)
                ->where('entries.0.amount.exact', '55.000000000')
                ->where('entries.49.amount.exact', '6.000000000'));
    });
});

describe('buying credits', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    it('adds a purchase entry for each pack', function (int $pack, int $nanos, string $message) {
        $this->actingAs($this->user)
            ->from('/credits')
            ->post('/credits/purchase', ['pack' => $pack])
            ->assertStatus(303)
            ->assertRedirect('/credits')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);

        $entry = LedgerEntry::query()->where('user_id', $this->user->id)->sole();
        expect($entry->type)->toBe(LedgerEntryType::Purchase)
            ->and($entry->amount)->toBe($nanos)
            ->and($entry->generation_id)->toBeNull()
            ->and(app(Ledger::class)->balance($this->user))->toBe($nanos);
    })->with([
        [5, 5_000_000_000, 'Added $5.00 of credits'],
        [10, 10_000_000_000, 'Added $10.00 of credits'],
        [25, 25_000_000_000, 'Added $25.00 of credits'],
    ]);

    it('adds to the existing balance', function () {
        app(Ledger::class)->grant($this->user, 2_000_000_000, 'Welcome credit');

        $this->actingAs($this->user)->post('/credits/purchase', ['pack' => 10]);
        $this->actingAs($this->user)->post('/credits/purchase', ['pack' => 5]);

        expect(app(Ledger::class)->balance($this->user))->toBe(17_000_000_000);
    });

    it('refuses anything but the fixed packs', function (mixed $pack) {
        $this->actingAs($this->user)
            ->postJson('/credits/purchase', ['pack' => $pack])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pack');

        expect(LedgerEntry::query()->count())->toBe(0);
    })->with([
        'not a pack' => [7],
        'zero' => [0],
        'negative' => [-5],
        'huge' => [1_000_000],
        'fraction' => ['10.5'],
        'text' => ['ten'],
        'empty' => [''],
        'missing' => [null],
        'list' => [[10]],
    ]);

    it('sends a form submission with a bad pack back with an error', function () {
        $this->actingAs($this->user)
            ->from('/credits')
            ->post('/credits/purchase', ['pack' => 7])
            ->assertRedirect('/credits')
            ->assertSessionHasErrors('pack');

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('sends guests to the login page', function () {
        $this->post('/credits/purchase', ['pack' => 10])->assertRedirect(route('login'));

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('names the routes as the contract says', function () {
        expect(route('credits.index', absolute: false))->toBe('/credits')
            ->and(route('credits.purchase', absolute: false))->toBe('/credits/purchase');
    });
});
