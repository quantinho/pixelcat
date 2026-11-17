<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Pixelforge\GenerationSnapshot;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One image request. Money columns are int nanodollars.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property Size $size
 * @property Quality $quality
 * @property list<string> $reference_images
 * @property string $request_hash
 * @property int $estimate_min
 * @property int $estimate_likely
 * @property int $estimate_max
 * @property array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string} $estimate_rates
 * @property array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}|null $rates
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $provider_cost
 * @property int|null $charged
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url
 * @property CarbonInterface|null $image_url_expires_at
 * @property string|null $image_path
 * @property CarbonInterface|null $provider_created_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface $available_at
 * @property int $submit_attempts
 * @property bool $had_ambiguous_attempt
 * @property bool $is_anomaly
 * @property CarbonInterface|null $last_synced_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $attributes = [
        'reference_images' => '[]',
        'submit_attempts' => 0,
        'had_ambiguous_attempt' => false,
        'is_anomaly' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => Size::class,
            'quality' => Quality::class,
            'reference_images' => 'array',
            'estimate_min' => 'integer',
            'estimate_likely' => 'integer',
            'estimate_max' => 'integer',
            'estimate_rates' => 'array',
            'rates' => 'array',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'provider_cost' => 'integer',
            'charged' => 'integer',
            'image_url_expires_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'available_at' => 'datetime',
            'submit_attempts' => 'integer',
            'had_ambiguous_attempt' => 'boolean',
            'is_anomaly' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<GenerationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(GenerationEvent::class)->orderBy('id');
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}|null
     */
    public function usage(): ?array
    {
        if ($this->output_tokens === null) {
            return null;
        }

        return [
            'input_text_tokens' => (int) $this->input_text_tokens,
            'input_image_tokens' => (int) $this->input_image_tokens,
            'output_tokens' => $this->output_tokens,
            'reasoning_tokens' => (int) $this->reasoning_tokens,
        ];
    }

    /**
     * The generation a Pixelforge snapshot is about, for webhooks and replays. Our id travels
     * as metadata.local_id, so an event can be matched before the create request returned;
     * it is trusted only when it agrees with the Pixelforge id we already have, so one
     * generation's usage can never be charged to another. The Pixelforge id covers the rest.
     */
    public static function forSnapshot(GenerationSnapshot $snapshot, bool $lock = false): ?self
    {
        $query = fn () => $lock ? self::query()->lockForUpdate() : self::query();

        if ($snapshot->localId !== null && Str::isUuid($snapshot->localId)) {
            $generation = $query()->whereKey($snapshot->localId)->first();

            if ($generation !== null && ($generation->pixelforge_id === null || $generation->pixelforge_id === $snapshot->id)) {
                return $generation;
            }

            if ($generation !== null) {
                Log::critical('A Pixelforge event names a local generation that has a different Pixelforge id.', [
                    'generation_id' => $generation->id,
                    'our_pixelforge_id' => $generation->pixelforge_id,
                    'event_pixelforge_id' => $snapshot->id,
                ]);
            }
        }

        return $query()->where('pixelforge_id', $snapshot->id)->first();
    }

    /**
     * Record an entry on the user-facing timeline.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function recordEvent(string $type, string $message, ?array $data = null): GenerationEvent
    {
        return $this->events()->create([
            'type' => $type,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
