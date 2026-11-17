# Core API (PHP)

The classes every slice builds on. Amounts are `int` nanodollars unless stated otherwise.
Signatures are the contract; implementations may add private helpers.

## Enums (`app/Enums`)

```php
enum Size: string { case Square = '1024x1024'; case Landscape = '1536x1024'; case Portrait = '1024x1536'; }
enum Quality: string { case Low = 'low'; case Medium = 'medium'; case High = 'high'; }

enum GenerationStatus: string {
    case Waiting = 'waiting'; case Submitting = 'submitting'; case Queued = 'queued';
    case Processing = 'processing'; case Succeeded = 'succeeded'; case Failed = 'failed';
    public function rank(): int;      // 0, 1, 2, 3, 4, 4
    public function isFinal(): bool;  // succeeded, failed
    public function label(): string;  // "Waiting for a free slot", "Submitting", "Queued",
                                      // "Generating", "Done", "Failed"
}

enum LedgerEntryType: string {
    case Grant = 'grant'; case Purchase = 'purchase'; case Hold = 'hold';
    case HoldRelease = 'hold_release'; case Charge = 'charge';
}

enum DeliveryOutcome: string {
    case Applied = 'applied'; case Duplicate = 'duplicate'; case OutOfOrder = 'out_of_order';
    case UnknownGeneration = 'unknown_generation'; case Ping = 'ping';
    case Rejected = 'rejected'; case Error = 'error';
}
```

## Money (`app/Billing/Money.php`)

```php
final class Money {
    public const NANOS_PER_DOLLAR = 1_000_000_000;
    public static function fromDecimal(string $usd): int;          // "0.373875000" → 373_875_000; throws on > 9 decimals or bad input
    public static function toDecimal(int $nanos): string;          // 373_875_000 → "0.373875000"; negative "-0.010000000"
    public static function display(int $nanos): string;            // half-up, 4 decimals: "$0.3739"; negative "-$0.0100"
    public static function perTokenNanos(string $ratePerMillion): int; // "12.50" → 12_500; throws if not a whole nanodollar
    public static function applyMarkup(int $nanos, int $percent): int; // ceil(nanos × (100 + percent) / 100)
}
```

`App\Http\Resources\MoneyResource::make(int $nanos)` serializes to
`['display' => Money::display($n), 'exact' => Money::toDecimal($n)]`.

## Pricing (`app/Pixelforge/Pricing.php`, `app/Actions/Pricing`)

```php
final readonly class Pricing {
    public static function fromArray(array $json): self;           // GET /v1/pricing body
    /** @var array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string} */
    public array $rates;
    public int $charactersPerTextToken;                            // 4
    public int $textTokenVariancePercent;                          // 15 (from 0.15)
    public int $tokensPerReferenceImage;                           // 765
    public function outputTokens(Quality $quality, Size $size): int;
    /** @return array{min: int, max: int} */
    public function reasoningRange(Quality $quality): array;
    public function version(): string;                             // sha256 of the canonical rates JSON
}

final class CalculateCost {
    /** @param array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int} $usage
     *  @param array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string} $rates */
    public function providerCost(array $usage, array $rates): int;
    public function userPrice(int $providerCost): int;             // Money::applyMarkup(cost, config('pixelcat.markup_percent'))
}

final readonly class PriceEstimate {
    public int $min; public int $likely; public int $max;          // user price
    /** @var list<array{type: string, min_tokens: int, max_tokens: int, min_cost: int, max_cost: int}> provider cost per token type */
    public array $breakdown;
    public int $markupPercent;
    public string $pricingVersion;
}

final class EstimatePrice {
    public function __invoke(string $prompt, Size $size, Quality $quality, int $referenceCount, Pricing $pricing): PriceEstimate;
}
```

Text tokens: `e = max(1, ceil(mb_strlen($prompt) / charactersPerTextToken))`; range
`floor(e × (100 − v) / 100)` … `ceil(e × (100 + v) / 100)`; likely `e`. Reasoning likely is the
integer midpoint `intdiv(min + max, 2)`.

## Pixelforge client (`app/Pixelforge`)

```php
final readonly class GenerationSnapshot {
    public static function fromArray(array $json): self;           // a generation object
    public string $id;                                             // gen_…
    public GenerationStatus $status;
    public ?string $localId;                                       // metadata.local_id
    /** @var array{input_text_tokens: string, ...}|null */ public ?array $rates;
    /** @var array{input_text_tokens: int, ...}|null */    public ?array $usage;
    public ?string $imageUrl; public ?CarbonImmutable $imageUrlExpiresAt;
    public ?string $errorCode; public ?string $errorMessage;
    public ?CarbonImmutable $createdAt; public ?CarbonImmutable $startedAt; public ?CarbonImmutable $completedAt;
}

class PixelforgeRejected extends RuntimeException {               // a definite answer: nothing was created
    public string $errorCode; public int $httpStatus; public ?int $retryAfter;
}
class PixelforgeUnavailable extends RuntimeException {}            // ambiguous: timeout, connection error, unexpected 5xx

class PixelforgeClient {
    public function pricing(): Pricing;                            // cached 5 minutes
    public function createGeneration(Generation $generation): GenerationSnapshot;
    public function getGeneration(string $pixelforgeId): GenerationSnapshot;
    /** @return array<string, array{generations: int, cost: int}> keyed by Y-m-d */
    public function usage(CarbonInterface $from, CarbonInterface $to): array;
    public function remainingRequests(): ?int;                     // last X-RateLimit-Remaining seen
    public function isBusy(): bool;                                // remaining < 10, or a 503 in the last 2 minutes
}

final class WebhookSignature {
    public function __construct(string $secret, int $toleranceSeconds = 300);
    public function verify(string $header, string $payload, ?int $now = null): bool;
    public static function sign(string $payload, string $secret, int $timestamp): string; // "t=…,v1=…"
}
```

`createGeneration` sends exactly `prompt`, `size`, `quality`, `reference_images` (only when not
empty) and `metadata: {local_id}`; header `Idempotency-Key: {generation id}`.

## Ledger (`app/Billing/Ledger.php`)

```php
final class Ledger {
    public function balance(User $user): int;
    public function grant(User $user, int $amount, string $description): LedgerEntry;
    public function purchase(User $user, int $amount): LedgerEntry;
    public function hold(Generation $generation, int $amount): LedgerEntry;   // writes −amount
    public function settle(Generation $generation): void;  // charge −charged, hold_release +held; no-op if already settled
    public function release(Generation $generation): void; // hold_release +held; no-op if none or already released
    public function lockUser(User $user): void;            // SELECT … FOR UPDATE on users; call inside a transaction
}
```

## Applying snapshots (`app/Actions/Generations`)

```php
final class ApplyGenerationSnapshot {
    // Locks the generation row; returns Applied when the status moved forward,
    // OutOfOrder when the snapshot's rank was ≤ the current one (missing data still filled).
    public function __invoke(Generation $generation, GenerationSnapshot $snapshot, string $source): DeliveryOutcome;
}
```

`$source` is one of `submit`, `webhook`, `poll`, `replay` and is stored on timeline events.
After commit it dispatches `App\Events\GenerationSucceeded` (success) and
`App\Events\GenerationFinished` (any final state), each carrying the `Generation`.

## Factories

`GenerationFactory` states: `waiting()`, `submitting()`, `queued()`, `processing()`,
`succeeded()`, `failed(string $code = 'generation_failed')`; `forUser(User $user)` via the
standard relationship helpers. `succeeded()` fills usage, `rates`, `provider_cost`, `charged`,
`completed_at` and `pixelforge_id` consistently with `CalculateCost`.
