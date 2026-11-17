<?php

namespace App\Http\Resources;

use App\Billing\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Money for the frontend: a display string and the exact value, never a number,
 * so Vue never does arithmetic on amounts.
 *
 * @property int $resource
 */
class MoneyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{display: string, exact: string}
     */
    public function toArray(Request $request): array
    {
        return self::format($this->resource);
    }

    /**
     * @return array{display: string, exact: string}
     */
    public static function format(int $nanos): array
    {
        return [
            'display' => Money::display($nanos),
            'exact' => Money::toDecimal($nanos),
        ];
    }

    /**
     * @return array{display: string, exact: string}|null
     */
    public static function formatNullable(?int $nanos): ?array
    {
        return $nanos === null ? null : self::format($nanos);
    }
}
