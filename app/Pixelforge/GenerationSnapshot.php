<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A Pixelforge generation object as returned by the API or carried in a webhook event.
 */
final readonly class GenerationSnapshot
{
    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}|null  $rates
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}|null  $usage
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?string $localId,
        public ?array $rates,
        public ?array $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        $status = GenerationStatus::tryFrom((string) ($json['status'] ?? ''));

        if (! is_string($json['id'] ?? null) || $status === null || $status->rank() < GenerationStatus::Queued->rank()) {
            throw new InvalidArgumentException('Not a Pixelforge generation object.');
        }

        $rates = null;
        if (is_array($json['rates'] ?? null)) {
            $rates = [];
            foreach (Pricing::TOKEN_TYPES as $type) {
                $rates[$type] = (string) $json['rates'][$type];
            }
        }

        $usage = null;
        if (is_array($json['usage'] ?? null)) {
            $usage = [];
            foreach (Pricing::TOKEN_TYPES as $type) {
                $usage[$type] = (int) $json['usage'][$type];
            }
        }

        $metadata = is_array($json['metadata'] ?? null) ? $json['metadata'] : [];
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];

        return new self(
            id: $json['id'],
            status: $status,
            localId: isset($metadata['local_id']) ? (string) $metadata['local_id'] : null,
            rates: $rates,
            usage: $usage,
            imageUrl: isset($json['image_url']) ? (string) $json['image_url'] : null,
            imageUrlExpiresAt: self::time($json['image_url_expires_at'] ?? null),
            errorCode: isset($error['code']) ? (string) $error['code'] : null,
            errorMessage: isset($error['message']) ? (string) $error['message'] : null,
            createdAt: self::time($json['created_at'] ?? null),
            startedAt: self::time($json['started_at'] ?? null),
            completedAt: self::time($json['completed_at'] ?? null),
        );
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value)->utc() : null;
    }
}
