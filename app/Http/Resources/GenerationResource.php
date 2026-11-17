<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\GenerationEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as the frontend sees it (contracts/pages.md "Generation").
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /** User-facing messages per error code (spec "Failure Modes"). */
    private const ERROR_MESSAGES = [
        'content_policy_violation' => "This prompt was rejected. You weren't charged.",
        'overloaded' => "Pixelforge is too busy right now. You weren't charged.",
        'rate_limit_exceeded' => "Pixelforge is too busy right now. You weren't charged.",
        'waited_too_long' => "Pixelforge is too busy right now. You weren't charged.",
        'daily_limit_exceeded' => "Pixelforge's daily limit is reached. You weren't charged.",
        'validation_failed' => "Something went wrong on our side. You weren't charged.",
        'idempotency_key_reused' => "Something went wrong on our side. You weren't charged.",
        'invalid_api_key' => "Something went wrong on our side. You weren't charged.",
        'generation_failed' => "The model couldn't draw this one. You weren't charged.",
        'internal_error' => "Pixelforge had a problem. You weren't charged.",
    ];

    private const FALLBACK_ERROR_MESSAGE = "Something went wrong. You weren't charged.";

    public static function errorMessage(string $code): string
    {
        return self::ERROR_MESSAGES[$code] ?? self::FALLBACK_ERROR_MESSAGE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Generation $generation */
        $generation = $this->resource;

        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'status' => $generation->status->value,
            'status_label' => $generation->status->label(),
            'is_final' => $generation->status->isFinal(),
            'estimate' => [
                'min' => MoneyResource::format($generation->estimate_min),
                'likely' => MoneyResource::format($generation->estimate_likely),
                'max' => MoneyResource::format($generation->estimate_max),
            ],
            'charged' => MoneyResource::formatNullable($generation->charged),
            'estimate_position' => $this->estimatePosition($generation),
            'image_src' => $this->hasImage($generation) ? "/generations/{$generation->id}/image" : null,
            'image_expired' => $generation->status === GenerationStatus::Succeeded && ! $this->hasImage($generation),
            'error' => $generation->status === GenerationStatus::Failed && $generation->error_code !== null ? [
                'code' => $generation->error_code,
                'message' => self::errorMessage($generation->error_code),
            ] : null,
            'events' => $generation->relationLoaded('events')
                ? $generation->events->map(fn (GenerationEvent $event) => [
                    'type' => $event->type,
                    'message' => $event->message,
                    'at' => $event->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'created_at' => $generation->created_at?->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Where the actual price landed in the estimate range, from 0 (min) to 1 (max).
     */
    private function estimatePosition(Generation $generation): ?float
    {
        if ($generation->charged === null) {
            return null;
        }

        $range = $generation->estimate_max - $generation->estimate_min;

        if ($range <= 0) {
            return 0.5;
        }

        $position = ($generation->charged - $generation->estimate_min) / $range;

        return round(max(0.0, min(1.0, $position)), 3);
    }

    private function hasImage(Generation $generation): bool
    {
        if ($generation->status !== GenerationStatus::Succeeded) {
            return false;
        }

        if ($generation->image_path !== null) {
            return true;
        }

        return $generation->image_url !== null
            && ($generation->image_url_expires_at === null || $generation->image_url_expires_at->isFuture());
    }
}
