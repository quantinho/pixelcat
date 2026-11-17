<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Our provider cost for one UTC completion day vs. Pixelforge's GET /v1/usage.
 *
 * @property Carbon $date
 * @property int $our_cost
 * @property int $our_count
 * @property int $pixelforge_cost
 * @property int $pixelforge_count
 * @property int $difference
 * @property Carbon $checked_at
 */
class Reconciliation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'date';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'our_cost' => 'integer',
            'our_count' => 'integer',
            'pixelforge_cost' => 'integer',
            'pixelforge_count' => 'integer',
            'difference' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function matches(): bool
    {
        return $this->difference === 0 && $this->our_count === $this->pixelforge_count;
    }
}
