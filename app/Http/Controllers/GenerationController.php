<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Http\Requests\GenerationRequest;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The generate form (GET /generate) and its submission (POST /generations).
 */
class GenerationController extends Controller
{
    /** Pixelforge's test keywords; the form offers them as one-click chips. */
    private const KEYWORDS = ['[succeed]', '[fail]', '[slow]', '[duplicate]', '[overloaded]', '[blocked]'];

    /** Prompts for "Surprise me with a cat". */
    private const CAT_PROMPTS = [
        'A ginger cat reading a newspaper in a Paris café, oil painting',
        'A tiny kitten astronaut floating past the Moon, watercolor',
        'A grumpy Persian cat as a Renaissance noble, museum portrait',
        'A black cat napping on a stack of old books by candlelight',
        'A tabby cat surfing a giant wave at sunset, vintage travel poster',
        'A fluffy white cat knitting a scarf by the fireplace, cozy illustration',
        'A cat detective in a trench coat on a rainy street at night, film noir',
        'Three cats having a picnic in a field of sunflowers, children\'s book style',
    ];

    public function create(Request $request, CreateGeneration $createGeneration): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('generations/Create', [
            'defaults' => ['size' => Size::Square->value, 'quality' => Quality::Medium->value],
            'prefill' => $this->prefill($user, $request->query('retry')),
            'keywords' => self::KEYWORDS,
            'cat_prompts' => self::CAT_PROMPTS,
            'daily' => [
                'used' => $createGeneration->usedToday($user),
                'cap' => (int) config('pixelcat.daily_cap_per_user'),
                'resets_at' => CreateGeneration::dailyCapResetsAt()->toIso8601String(),
            ],
        ]);
    }

    public function store(GenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $createGeneration(
            $user,
            (string) $request->validated('id'),
            $request->prompt(),
            $request->size(),
            $request->quality(),
            $request->referenceImages(),
            (string) $request->validated('pricing_version'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Generating…']);

        // The history page (generations.index) belongs to the history slice.
        return redirect()->to('/generations', 303);
    }

    /**
     * "Try again" (FR-015): the parameters of the user's own failed generation. Never submits.
     *
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>}|null
     */
    private function prefill(User $user, mixed $retryId): ?array
    {
        if (! is_string($retryId) || ! Str::isUuid($retryId)) {
            return null;
        }

        $failed = $user->generations()
            ->whereKey($retryId)
            ->where('status', GenerationStatus::Failed)
            ->first();

        if ($failed === null) {
            return null;
        }

        return [
            'prompt' => $failed->prompt,
            'size' => $failed->size->value,
            'quality' => $failed->quality->value,
            'reference_images' => $failed->reference_images,
        ];
    }
}
