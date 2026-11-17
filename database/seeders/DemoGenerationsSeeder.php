<?php

namespace Database\Seeders;

use App\Billing\Ledger;
use App\Billing\Money;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Fake finished generations (done, failed and an anomaly), for local development and review only; never run in
 * production. Each one gets the ledger entries the real flow would write (hold, then
 * charge and release, or release only), so balances stay consistent.
 *
 * Seeds once: if the demo user already has generations, nothing is added.
 */
class DemoGenerationsSeeder extends Seeder
{
    private const PROMPTS = [
        'A ginger cat reading a newspaper, oil painting',
        'Two kittens playing chess in a Kyiv cafe',
        'A cat knight in shining armour, pixel art',
        'A Siamese cat DJ at a rooftop party, neon',
        'A cat napping on a pile of books, soft light',
        'A Maine Coon as a ship captain in a storm',
    ];

    public function __construct(private readonly Ledger $ledger) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('Fake generations are never seeded in production.');
        }

        $demo = User::query()->where('email', UsersSeeder::DEMO_EMAIL)->firstOrFail();
        $admin = User::query()->where('email', UsersSeeder::ADMIN_EMAIL)->firstOrFail();

        if ($demo->generations()->exists()) {
            return;
        }

        DB::transaction(function () use ($demo, $admin) {
            $this->ledger->purchase($demo, 10 * Money::NANOS_PER_DOLLAR);

            $today = CarbonImmutable::now('UTC');

            // Finished on earlier UTC days, so the operator report has history.
            foreach ([3, 2, 2, 1, 1, 1] as $i => $daysAgo) {
                $this->succeeded($demo, $today->subDays($daysAgo)->setTime(9 + 2 * $i, 15), self::PROMPTS[$i]);
            }
            $this->succeeded($admin, $today->subDay()->setTime(16, 40), 'A tabby cat as a lighthouse keeper, watercolour', Quality::High, Size::Landscape);
            $this->succeeded($demo, $today->subMinutes(20), 'A black cat astronaut floating past Saturn [succeed]');

            $this->failed($demo, $today->subDays(2)->setTime(14, 5), 'generation_failed', 'A cat made entirely of spaghetti [fail]');
            $this->failed($demo, $today->subMinutes(45), 'internal_error', 'A cat conducting an orchestra of mice');

            // Pixelforge finished it after we had given up waiting: we pay, the user doesn't.
            $this->anomaly($demo, $today->subDay()->setTime(22, 50));

            // No fixtures in waiting, submitting, queued or processing: Pixelforge has never
            // heard of them, so they would hold the demo user's slots forever and every real
            // submission would wait. Submit a prompt to see those states; the fake finishes it.
        });
    }

    private function succeeded(User $user, CarbonImmutable $completedAt, string $prompt, Quality $quality = Quality::Medium, Size $size = Size::Square): void
    {
        $generation = Generation::factory()->for($user)->succeeded()->create([
            'prompt' => $prompt,
            'quality' => $quality,
            'size' => $size,
            ...$this->timestamps($completedAt),
            // Pixelforge image URLs expire 24 hours after completion.
            'image_url_expires_at' => $completedAt->addDay(),
        ]);

        $this->ledger->hold($generation, $generation->estimate_max);
        $this->ledger->settle($generation);
    }

    private function failed(User $user, CarbonImmutable $completedAt, string $code, string $prompt): void
    {
        $generation = Generation::factory()->for($user)->failed($code)->create([
            'prompt' => $prompt,
            ...$this->timestamps($completedAt),
        ]);

        $this->ledger->hold($generation, $generation->estimate_max);
        $this->ledger->release($generation);
    }

    private function anomaly(User $user, CarbonImmutable $completedAt): void
    {
        // Build a succeeded generation for its usage and cost, then record it as the
        // anomaly ApplyGenerationSnapshot would leave: failed, cost recorded, not charged.
        $generation = Generation::factory()->for($user)->succeeded()->make([
            'prompt' => 'A cat waiting patiently for a free slot',
            ...$this->timestamps($completedAt),
        ]);
        $generation->forceFill([
            'status' => GenerationStatus::Failed,
            'charged' => null,
            'is_anomaly' => true,
            'error_code' => 'waited_too_long',
            'error_message' => 'Pixelforge was too busy, so we stopped waiting.',
            'image_url' => null,
            'image_url_expires_at' => null,
        ])->save();

        $this->ledger->hold($generation, $generation->estimate_max);
        $this->ledger->release($generation);
    }

    /**
     * Pixelforge-like timestamps for a generation that completed at $completedAt.
     *
     * @return array<string, CarbonImmutable>
     */
    private function timestamps(CarbonImmutable $completedAt): array
    {
        return [
            'created_at' => $completedAt->subSeconds(40),
            'provider_created_at' => $completedAt->subSeconds(38),
            'started_at' => $completedAt->subSeconds(30),
            'completed_at' => $completedAt,
            'last_synced_at' => $completedAt,
            'updated_at' => $completedAt,
        ];
    }
}
