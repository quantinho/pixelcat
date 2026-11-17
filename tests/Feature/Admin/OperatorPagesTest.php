<?php

use App\Enums\DeliveryOutcome;
use App\Models\Generation;
use App\Models\Reconciliation;
use App\Models\User;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
});

/** A generation Pixelforge billed, with known amounts. */
function reportBilledGeneration(User $user, string $completedAt, int $providerCost, ?int $charged): Generation
{
    return Generation::factory()->for($user)->succeeded()->create([
        'provider_cost' => $providerCost,
        'charged' => $charged,
        'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
    ]);
}

function reportAnomaly(User $user, string $completedAt, int $providerCost): Generation
{
    return Generation::factory()->for($user)->failed('waited_too_long')->create([
        'is_anomaly' => true,
        'provider_cost' => $providerCost,
        'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
    ]);
}

function reportReconciled(string $date, int $ourCost, int $ourCount, int $pixelforgeCost, int $pixelforgeCount): Reconciliation
{
    return Reconciliation::query()->create([
        'date' => $date,
        'our_cost' => $ourCost,
        'our_count' => $ourCount,
        'pixelforge_cost' => $pixelforgeCost,
        'pixelforge_count' => $pixelforgeCount,
        'difference' => $ourCost - $pixelforgeCost,
        'checked_at' => CarbonImmutable::parse($date, 'UTC')->addDay()->setTime(0, 15),
    ]);
}

/** @return array{display: string, exact: string} */
function reportMoney(string $display, string $exact): array
{
    return ['display' => $display, 'exact' => $exact];
}

function webhookDeliveryRow(array $attributes = []): WebhookDelivery
{
    return WebhookDelivery::query()->create([
        'event_id' => 'evt_'.fake()->unique()->bothify('????????'),
        'event_type' => 'generation.succeeded',
        'delivery_attempt' => 1,
        'signature_valid' => true,
        'outcome' => DeliveryOutcome::Applied,
        'headers' => [],
        'payload' => ['type' => 'generation.succeeded'],
        'received_at' => now(),
        ...$attributes,
    ]);
}

describe('access', function () {
    it('sends guests to the login page', function (string $url) {
        $this->get($url)->assertRedirect(route('login'));
    })->with(['/admin/report', '/admin/deliveries']);

    it('forbids users who are not admins', function (string $url) {
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    })->with(['/admin/report', '/admin/deliveries']);

    it('lets admins in', function (string $url, string $component) {
        $this->actingAs($this->admin)->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    })->with([
        ['/admin/report', 'admin/Report'],
        ['/admin/deliveries', 'admin/Deliveries'],
    ]);

    it('names the routes as the contract says', function () {
        expect(route('admin.report', absolute: false))->toBe('/admin/report')
            ->and(route('admin.deliveries', absolute: false))->toBe('/admin/deliveries');
    });
});

describe('report', function () {
    beforeEach(function () {
        $this->alice = User::factory()->create(['email' => 'alice@example.com']);
        $this->bob = User::factory()->create(['email' => 'bob@example.com']);

        // Today: still in progress.
        reportBilledGeneration($this->alice, '2026-11-17 09:00:00', 40_000_000, 50_000_000);
        // Yesterday: two users, reconciled as matching. A failed generation costs nothing.
        reportBilledGeneration($this->alice, '2026-11-16 08:00:00', 100_000_000, 125_000_000);
        reportBilledGeneration($this->bob, '2026-11-16 23:59:59', 200_000_000, 250_000_000);
        Generation::factory()->for($this->bob)->failed()->create(['completed_at' => CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC')]);
        reportReconciled('2026-11-16', 300_000_000, 2, 300_000_000, 2);
        // The 15th: an anomaly we paid for but never charged; Pixelforge reports nothing.
        $this->anomaly = reportAnomaly($this->bob, '2026-11-15 12:00:00', 30_000_000);
        reportReconciled('2026-11-15', 30_000_000, 1, 0, 0);
        // The 14th: checked, nothing happened.
        reportReconciled('2026-11-14', 0, 0, 0, 0);
        // The 13th: activity, but never checked.
        reportBilledGeneration($this->alice, '2026-11-13 18:00:00', 8_000_000, 10_000_000);
        // Long ago: outside the report window.
        reportBilledGeneration($this->alice, '2026-09-01 10:00:00', 1_000_000_000, 1_250_000_000);
    });

    it('lists days newest first with their reconciliation status', function () {
        $this->actingAs($this->admin)->get('/admin/report')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Report')
                ->has('days', 5)
                ->where('days.0.date', '2026-11-17')
                ->where('days.0.reconciliation.status', 'in_progress')
                ->where('days.1.date', '2026-11-16')
                ->where('days.1.reconciliation.status', 'match')
                ->where('days.2.date', '2026-11-15')
                ->where('days.2.reconciliation.status', 'mismatch')
                ->where('days.3.date', '2026-11-14')
                ->where('days.3.reconciliation.status', 'match')
                ->where('days.4.date', '2026-11-13')
                ->where('days.4.reconciliation.status', 'not_checked'));
    });

    it('sums charged, provider cost and profit per day and per user', function () {
        $this->actingAs($this->admin)->get('/admin/report')
            ->assertInertia(fn (Assert $page) => $page
                ->where('days.1', [
                    'date' => '2026-11-16',
                    'charged' => reportMoney('$0.3750', '0.375000000'),
                    'provider_cost' => reportMoney('$0.3000', '0.300000000'),
                    'profit' => reportMoney('$0.0750', '0.075000000'),
                    'generations' => 2,
                    'reconciliation' => [
                        'status' => 'match',
                        'pixelforge_cost' => reportMoney('$0.3000', '0.300000000'),
                        'difference' => reportMoney('$0.0000', '0.000000000'),
                        'checked_at' => '2026-11-17T00:15:00+00:00',
                    ],
                    'users' => [
                        [
                            'user' => 'alice@example.com',
                            'charged' => reportMoney('$0.1250', '0.125000000'),
                            'provider_cost' => reportMoney('$0.1000', '0.100000000'),
                            'profit' => reportMoney('$0.0250', '0.025000000'),
                            'generations' => 1,
                        ],
                        [
                            'user' => 'bob@example.com',
                            'charged' => reportMoney('$0.2500', '0.250000000'),
                            'provider_cost' => reportMoney('$0.2000', '0.200000000'),
                            'profit' => reportMoney('$0.0500', '0.050000000'),
                            'generations' => 1,
                        ],
                    ],
                ]));
    });

    it('shows the loss on an anomaly and the stored difference', function () {
        $this->actingAs($this->admin)->get('/admin/report')
            ->assertInertia(fn (Assert $page) => $page
                ->where('days.2.charged', reportMoney('$0.0000', '0.000000000'))
                ->where('days.2.provider_cost', reportMoney('$0.0300', '0.030000000'))
                ->where('days.2.profit', reportMoney('-$0.0300', '-0.030000000'))
                ->where('days.2.generations', 1)
                ->where('days.2.reconciliation.pixelforge_cost', reportMoney('$0.0000', '0.000000000'))
                ->where('days.2.reconciliation.difference', reportMoney('$0.0300', '0.030000000')));
    });

    it('leaves Pixelforge figures empty for days not checked yet', function () {
        $this->actingAs($this->admin)->get('/admin/report')
            ->assertInertia(fn (Assert $page) => $page
                ->where('days.0.reconciliation', [
                    'status' => 'in_progress', 'pixelforge_cost' => null, 'difference' => null, 'checked_at' => null,
                ])
                ->where('days.4.reconciliation', [
                    'status' => 'not_checked', 'pixelforge_cost' => null, 'difference' => null, 'checked_at' => null,
                ])
                ->where('days.3.generations', 0)
                ->where('days.3.users', []));
    });

    it('totals the listed days', function () {
        // Charged 50 + 125 + 250 + 0 + 10, provider cost 40 + 100 + 200 + 30 + 8 (millions).
        $this->actingAs($this->admin)->get('/admin/report')
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals', [
                    'charged' => reportMoney('$0.4350', '0.435000000'),
                    'provider_cost' => reportMoney('$0.3780', '0.378000000'),
                    'profit' => reportMoney('$0.0570', '0.057000000'),
                ]));
    });

    it('lists billing anomalies', function () {
        $this->actingAs($this->admin)->get('/admin/report')
            ->assertInertia(fn (Assert $page) => $page
                ->where('anomalies', [[
                    'generation_id' => $this->anomaly->id,
                    'user' => 'bob@example.com',
                    'provider_cost' => reportMoney('$0.0300', '0.030000000'),
                    'at' => '2026-11-15T12:00:00+00:00',
                ]]));
    });
});

describe('deliveries', function () {
    it('lists deliveries newest first in the contract shape', function () {
        $generation = Generation::factory()->succeeded()->create();
        webhookDeliveryRow([
            'event_id' => 'evt_old', 'outcome' => DeliveryOutcome::Duplicate, 'delivery_attempt' => 2,
            'generation_id' => $generation->id, 'received_at' => now()->subMinute(),
        ]);
        webhookDeliveryRow([
            'event_id' => null, 'event_type' => null, 'delivery_attempt' => null, 'signature_valid' => false,
            'outcome' => DeliveryOutcome::Rejected, 'payload' => null, 'received_at' => now(),
        ]);

        $this->actingAs($this->admin)->get('/admin/deliveries')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Deliveries')
                ->where('deliveries.data', [
                    [
                        'event_id' => null, 'event_type' => null, 'attempt' => null,
                        'outcome' => 'rejected', 'generation_id' => null,
                        'received_at' => '2026-11-17T10:00:00+00:00',
                    ],
                    [
                        'event_id' => 'evt_old', 'event_type' => 'generation.succeeded', 'attempt' => 2,
                        'outcome' => 'duplicate', 'generation_id' => $generation->id,
                        'received_at' => '2026-11-17T09:59:00+00:00',
                    ],
                ])
                ->where('deliveries.next_cursor', null));
    });

    it('pages through older deliveries with a cursor', function () {
        foreach (range(1, 55) as $i) {
            webhookDeliveryRow(['event_id' => "evt_{$i}", 'received_at' => now()->subMinutes(100 - $i)]);
        }

        $cursor = null;
        $this->actingAs($this->admin)->get('/admin/deliveries')
            ->assertInertia(function (Assert $page) use (&$cursor) {
                $page->has('deliveries.data', 50)
                    ->where('deliveries.data.0.event_id', 'evt_55')
                    ->where('deliveries.data.49.event_id', 'evt_6')
                    ->whereType('deliveries.next_cursor', 'string');
                $cursor = $page->toArray()['props']['deliveries']['next_cursor'];
            });

        $this->actingAs($this->admin)->get('/admin/deliveries?cursor='.urlencode($cursor))
            ->assertInertia(fn (Assert $page) => $page
                ->has('deliveries.data', 5)
                ->where('deliveries.data.0.event_id', 'evt_5')
                ->where('deliveries.data.4.event_id', 'evt_1')
                ->where('deliveries.next_cursor', null));
    });
});
