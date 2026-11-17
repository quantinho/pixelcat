# Commands, schedule and jobs

## Artisan commands

| Command | Schedule | Behaviour |
|---------|----------|-----------|
| `pixelforge:dispatch` | hourly (sweep) | Runs `DispatchWaitingGenerations`; fails generations that waited too long |
| `pixelforge:poll` | hourly (sweep) | Re-dispatches stale `submitting`; fetches up to 10 `queued`/`processing` with no update for 15 minutes; stops when `X-RateLimit-Remaining` < 20 |
| `pixelforge:reconcile {--days=7}` | daily 00:15 UTC | Compares completed UTC days with `GET /v1/usage`; stores `reconciliations`; logs differences |
| `pixelforge:replay-event {event_id}` | manual | Re-applies a stored `webhook_events` payload through `ApplyGenerationSnapshot` |
| `pixelcat:demo-generations {--user=}` | manual | Submits demo prompts with keywords (`[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`) through the normal flow |

All scheduled commands use `onOneServer()` and `withoutOverlapping()`.

## Jobs

| Job | Unique by | Behaviour |
|-----|-----------|-----------|
| `SubmitGenerationToPixelforge` | generation id | One create request; outcome per the spec's Failure Modes table; releases itself with `Retry-After` + backoff |
| `DispatchWaiting` | — (serialized by advisory lock) | Runs `DispatchWaitingGenerations`; dispatched on create, on `GenerationFinished`, and after a concurrency 429 with `Retry-After` |
| `ExpireWaitingGeneration` | generation id | Delayed by the waiting limit; fails the generation (`waited_too_long`, hold released) if it is still `waiting` |
| `SyncGeneration` | generation id | Delayed; if the generation isn't final and had no update since it was scheduled, fetches it and applies the snapshot; reschedules itself with backoff 2, 4, 8, then 15 minutes |
| `StoreGenerationImage` | generation id | Downloads the PNG to the `images` disk; retries 3 times; skips if already stored |

## Core operations (actions)

| Action | Contract |
|--------|----------|
| `EstimatePrice` | request + current pricing → min, likely, max user price and breakdown |
| `CalculateCost` | usage + rates → provider cost; provider cost → user price |
| `CreateGeneration` | validated request + user → `waiting` generation and hold, in one transaction with the user row locked; enforces daily cap, balance and pricing version |
| `DispatchWaitingGenerations` | advisory-locked; moves eligible `waiting` → `submitting`; dispatches submit jobs |
| `ApplyGenerationSnapshot` | snapshot (+ optional event) → state, money and timeline changes under a row lock; the only writer of status after submission |
| `Ledger` | `balance(user)`, `grant`, `purchase`, `hold`, `settle(generation)`, `release(generation)` |

## Outbound Pixelforge client

| Method | Request | Returns / throws |
|--------|---------|------------------|
| `pricing()` | `GET /v1/pricing`, cached 5 minutes | `Pricing` |
| `createGeneration(Generation)` | `POST /v1/generations` with `Idempotency-Key`, `metadata.local_id` | `GenerationSnapshot`, or `PixelforgeRejected(code, retryAfter)` / `PixelforgeUnavailable` (ambiguous) |
| `getGeneration(pixelforgeId)` | `GET /v1/generations/{id}` | `GenerationSnapshot` |
| `usage(from, to)` | `GET /v1/usage` | daily rows with `cost_usd` parsed to nanodollars |

Timeouts: 10 s connect + response. Every response updates the cached rate-limit budget.
