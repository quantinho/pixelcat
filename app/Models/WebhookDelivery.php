<?php

namespace App\Models;

use App\Enums\DeliveryOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every POST received on the webhook endpoint.
 *
 * @property int $id
 * @property string|null $event_id
 * @property string|null $event_type
 * @property int|null $delivery_attempt
 * @property bool $signature_valid
 * @property DeliveryOutcome $outcome
 * @property string|null $generation_id
 * @property array<string, mixed> $headers
 * @property array<string, mixed>|null $payload
 * @property Carbon $received_at
 */
class WebhookDelivery extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'delivery_attempt' => 'integer',
            'signature_valid' => 'boolean',
            'outcome' => DeliveryOutcome::class,
            'headers' => 'array',
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
