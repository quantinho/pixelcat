<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per accepted Pixelforge event; the primary key is the dedupe.
 *
 * @property string $event_id
 * @property string $type
 * @property string|null $generation_id
 * @property array<string, mixed> $payload
 * @property Carbon $processed_at
 */
class WebhookEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
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
