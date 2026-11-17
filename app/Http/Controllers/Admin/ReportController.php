<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MoneyResource;
use App\Models\Generation;
use App\Models\Reconciliation;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spend, provider cost and profit per UTC completion day and per user (FR-019), with each
 * day's reconciliation against GET /v1/usage and the billing anomalies.
 *
 * A generation counts once Pixelforge billed it (it has a provider cost), on the UTC day
 * of its Pixelforge completed_at, as in pixelforge:reconcile. Sums are integer
 * nanodollars from SQL; the page only gets formatted Money.
 */
class ReportController extends Controller
{
    /** UTC days shown, today included. */
    private const DAYS = 30;

    private const ANOMALIES = 50;

    public function __invoke(): Response
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = $today->subDays(self::DAYS - 1);

        $usersByDay = $this->usageByDayAndUser($from);
        $reconciliations = Reconciliation::query()
            ->where('date', '>=', $from->toDateString())
            ->get()
            ->keyBy(fn (Reconciliation $reconciliation) => $reconciliation->date->toDateString());

        $dates = collect($usersByDay)->keys()
            ->merge($reconciliations->keys())
            ->unique()
            ->sortDesc()
            ->values();

        $totals = ['charged' => 0, 'provider_cost' => 0];
        $days = [];

        foreach ($dates as $date) {
            $users = $usersByDay[$date] ?? [];
            $charged = $providerCost = $generations = 0;
            foreach ($users as $user) {
                $charged += $user['charged'];
                $providerCost += $user['provider_cost'];
                $generations += $user['generations'];
            }
            $totals['charged'] += $charged;
            $totals['provider_cost'] += $providerCost;

            $days[] = [
                'date' => $date,
                ...$this->money($charged, $providerCost),
                'generations' => $generations,
                'reconciliation' => $this->reconciliation($date, $today, $reconciliations->get($date)),
                'users' => array_map(fn (array $user) => [
                    'user' => $user['user'],
                    ...$this->money($user['charged'], $user['provider_cost']),
                    'generations' => $user['generations'],
                ], $users),
            ];
        }

        return Inertia::render('admin/Report', [
            'days' => $days,
            'totals' => $this->money($totals['charged'], $totals['provider_cost']),
            'anomalies' => $this->anomalies(),
        ]);
    }

    /**
     * Billed generations since $from, grouped by UTC completion day, then by user.
     *
     * @return array<string, list<array{user: string, charged: int, provider_cost: int, generations: int}>>
     */
    private function usageByDayAndUser(CarbonImmutable $from): array
    {
        $rows = Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->whereNotNull('generations.provider_cost')
            ->where('generations.completed_at', '>=', $from)
            ->selectRaw("(generations.completed_at AT TIME ZONE 'UTC')::date::text AS day")
            ->selectRaw('users.email AS user_email')
            // An anomaly has a provider cost but was never charged.
            ->selectRaw('COALESCE(SUM(generations.charged), 0)::text AS charged')
            ->selectRaw('SUM(generations.provider_cost)::text AS provider_cost')
            ->selectRaw('COUNT(*) AS generations')
            ->groupBy('day', 'users.id', 'users.email')
            ->orderBy('day')
            ->orderBy('users.email')
            ->toBase()
            ->get();

        $days = [];
        foreach ($rows as $row) {
            $days[(string) $row->day][] = [
                'user' => (string) $row->user_email,
                'charged' => (int) $row->charged,
                'provider_cost' => (int) $row->provider_cost,
                'generations' => (int) $row->generations,
            ];
        }

        return $days;
    }

    /**
     * @return array{status: string, pixelforge_cost: array{display: string, exact: string}|null, difference: array{display: string, exact: string}|null, checked_at: string|null}
     */
    private function reconciliation(string $date, CarbonImmutable $today, ?Reconciliation $reconciliation): array
    {
        if ($date >= $today->toDateString()) {
            return ['status' => 'in_progress', 'pixelforge_cost' => null, 'difference' => null, 'checked_at' => null];
        }

        if ($reconciliation === null) {
            return ['status' => 'not_checked', 'pixelforge_cost' => null, 'difference' => null, 'checked_at' => null];
        }

        return [
            'status' => $reconciliation->matches() ? 'match' : 'mismatch',
            'pixelforge_cost' => MoneyResource::format($reconciliation->pixelforge_cost),
            'difference' => MoneyResource::format($reconciliation->difference),
            'checked_at' => $reconciliation->checked_at->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{generation_id: string, user: string, provider_cost: array{display: string, exact: string}, at: string}>
     */
    private function anomalies(): array
    {
        return Generation::query()
            ->with('user')
            ->where('is_anomaly', true)
            ->whereNotNull('provider_cost')
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->limit(self::ANOMALIES)
            ->get()
            ->map(fn (Generation $generation) => [
                'generation_id' => $generation->id,
                'user' => $generation->user->email,
                'provider_cost' => MoneyResource::format((int) $generation->provider_cost),
                'at' => (string) $generation->completed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array{charged: array{display: string, exact: string}, provider_cost: array{display: string, exact: string}, profit: array{display: string, exact: string}}
     */
    private function money(int $charged, int $providerCost): array
    {
        return [
            'charged' => MoneyResource::format($charged),
            'provider_cost' => MoneyResource::format($providerCost),
            'profit' => MoneyResource::format($charged - $providerCost),
        ];
    }
}
