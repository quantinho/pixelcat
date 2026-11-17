<?php

namespace App\Console\Commands;

use App\Actions\Generations\CreateGeneration;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeRejected;
use App\Pixelforge\PixelforgeUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Leaves demo generations in every state on a real deployment (FR-022). They go through
 * the normal flow (caps, holds, the submit job, webhooks), so every recorded cost is one
 * Pixelforge really billed and the operator report still reconciles with GET /v1/usage.
 */
class DemoGenerations extends Command
{
    protected $signature = 'pixelcat:demo-generations {--user=demo@pixelcat.test : Email of the user who owns them}';

    protected $description = 'Submit demo generations with Pixelforge test keywords through the normal flow';

    /** Each keyword shows a different path through the app. */
    public const PROMPTS = [
        ['A ginger cat reading the morning paper, oil painting [succeed]', Size::Square, Quality::Medium],
        ['A tabby cat surfing a giant wave at sunset, vintage poster [succeed]', Size::Landscape, Quality::High],
        ['Two cats sharing one sunbeam, watercolor [duplicate]', Size::Square, Quality::Low],
        ['A cat knocks a vase off the table [fail]', Size::Square, Quality::Medium],
        ['A cat with a forbidden idea [blocked]', Size::Square, Quality::Low],
    ];

    public function handle(CreateGeneration $createGeneration, PixelforgeClient $pixelforge): int
    {
        $email = (string) $this->option('user');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with the email {$email}. Run the users seeder first.");

            return self::FAILURE;
        }

        try {
            $pricingVersion = $pixelforge->pricing()->version();
        } catch (PixelforgeUnavailable|PixelforgeRejected $e) {
            $this->error('Could not read Pixelforge pricing: '.$e->getMessage());

            return self::FAILURE;
        }

        $created = 0;

        foreach (self::PROMPTS as [$prompt, $size, $quality]) {
            try {
                $createGeneration($user, (string) Str::uuid(), $prompt, $size, $quality, [], $pricingVersion);
                $created++;
                $this->line("Submitted: {$prompt}");
            } catch (ValidationException $e) {
                $this->warn("Skipped \"{$prompt}\": ".collect($e->errors())->flatten()->first());
            }
        }

        $this->info("Submitted {$created} demo generation(s) for {$email}; they finish within a minute or two.");

        return self::SUCCESS;
    }
}
