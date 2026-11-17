<?php

namespace App\Console\Commands;

use App\Billing\Money;
use App\Models\Generation;
use App\Models\Reconciliation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeRejected;
use App\Pixelforge\PixelforgeUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Compares our provider cost per UTC completion day with GET /v1/usage (FR-020, SC-004).
 *
 * Only completed days are stored: today is still in progress. `--today` also shows today
 * so far, without storing it, to check a deployment right after it generated. Our side is every
 * generation Pixelforge billed (it has a provider cost), anomalies included, on the UTC
 * day of its Pixelforge completed_at.
 */
class ReconcileUsage extends Command
{
    protected $signature = 'pixelforge:reconcile {--days=7 : Completed UTC days to check}
        {--today : Also show today so far (not stored; it changes until the day ends)}';

    protected $description = 'Compare provider costs with Pixelforge usage per UTC day';

    /** GET /v1/usage accepts at most this many days per request. */
    private const MAX_DAYS = 92;

    public function handle(PixelforgeClient $pixelforge): int
    {
        $days = (int) $this->option('days');

        if ($days < 1 || $days > self::MAX_DAYS) {
            $this->error('--days must be between 1 and '.self::MAX_DAYS.'.');

            return self::FAILURE;
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = $today->subDays($days);
        $withToday = (bool) $this->option('today');
        $to = $withToday ? $today : $today->subDay();
        $until = $to->addDay();

        try {
            $theirs = $pixelforge->usage($from, $to);
        } catch (PixelforgeRejected|PixelforgeUnavailable $e) {
            Log::error('Pixelforge usage could not be fetched for reconciliation.', ['error' => $e->getMessage()]);
            $this->error('Could not fetch Pixelforge usage: '.$e->getMessage());

            return self::FAILURE;
        }

        $ours = $this->ourUsage($from, $until);
        $checkedAt = now();
        $rows = [];

        for ($day = $from; $day->lessThan($until); $day = $day->addDay()) {
            $date = $day->toDateString();
            $ourCost = $ours[$date]['cost'] ?? 0;
            $ourCount = $ours[$date]['generations'] ?? 0;
            $pixelforgeCost = $theirs[$date]['cost'] ?? 0;
            $pixelforgeCount = $theirs[$date]['generations'] ?? 0;

            $rows[] = [
                'date' => $date,
                'our_cost' => $ourCost,
                'our_count' => $ourCount,
                'pixelforge_cost' => $pixelforgeCost,
                'pixelforge_count' => $pixelforgeCount,
                'difference' => $ourCost - $pixelforgeCost,
                'checked_at' => $checkedAt,
            ];
        }

        Reconciliation::query()->upsert(
            array_values(array_filter($rows, fn (array $row) => $row['date'] !== $today->toDateString())),
            ['date'],
            ['our_cost', 'our_count', 'pixelforge_cost', 'pixelforge_count', 'difference', 'checked_at'],
        );

        $this->report($rows, $today->toDateString());

        return self::SUCCESS;
    }

    /**
     * Our billed cost and count per UTC completion day in [$from, $until).
     * Summed in SQL on bigint, so amounts never pass through a float.
     *
     * @return array<string, array{cost: int, generations: int}>
     */
    private function ourUsage(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $rows = Generation::query()
            ->whereNotNull('provider_cost')
            ->where('completed_at', '>=', $from)
            ->where('completed_at', '<', $until)
            ->selectRaw("(completed_at AT TIME ZONE 'UTC')::date::text AS day")
            ->selectRaw('SUM(provider_cost)::text AS cost')
            ->selectRaw('COUNT(*) AS generations')
            ->groupBy('day')
            ->toBase()
            ->get();

        $usage = [];
        foreach ($rows as $row) {
            $usage[(string) $row->day] = [
                'cost' => (int) $row->cost,
                'generations' => (int) $row->generations,
            ];
        }

        return $usage;
    }

    /**
     * @param  list<array{date: string, our_cost: int, our_count: int, pixelforge_cost: int, pixelforge_count: int, difference: int}>  $rows
     */
    private function report(array $rows, string $today): void
    {
        $table = [];

        foreach ($rows as $row) {
            $matches = (new Reconciliation($row))->matches();

            $inProgress = $row['date'] === $today;

            if (! $matches && ! $inProgress) {
                Log::warning('Pixelforge usage differs from our provider cost.', [
                    'date' => $row['date'],
                    'our_cost' => Money::toDecimal($row['our_cost']),
                    'pixelforge_cost' => Money::toDecimal($row['pixelforge_cost']),
                    'difference' => Money::toDecimal($row['difference']),
                    'our_count' => $row['our_count'],
                    'pixelforge_count' => $row['pixelforge_count'],
                ]);
            }

            $table[] = [
                $inProgress ? "{$row['date']} (so far)" : $row['date'],
                Money::toDecimal($row['our_cost']),
                Money::toDecimal($row['pixelforge_cost']),
                Money::toDecimal($row['difference']),
                "{$row['our_count']} / {$row['pixelforge_count']}",
                $matches ? 'match' : 'MISMATCH',
            ];
        }

        $this->table(['Date (UTC)', 'Our cost', 'Pixelforge cost', 'Difference', 'Count (ours / theirs)', 'Status'], $table);
    }
}
