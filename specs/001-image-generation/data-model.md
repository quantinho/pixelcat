# Data Model: Paid image generation through Pixelforge

Postgres. Money columns are `bigint` nanodollars (1 USD = 1,000,000,000). All timestamps are
`timestamptz` in UTC.

## users (starter kit, extended)

| Column | Type | Notes |
|--------|------|-------|
| `is_admin` | boolean, default false | Operators; set by the seeder only |

Relationships: has many `generations`, has many `ledger_entries`.

## generations

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid, PK | Created by the form; also `Idempotency-Key` and `metadata.local_id` |
| `user_id` | FK users, indexed | |
| `pixelforge_id` | string, unique, nullable | `gen_…`, set once known |
| `status` | string (enum `GenerationStatus`) | `waiting`, `submitting`, `queued`, `processing`, `succeeded`, `failed` |
| `prompt` | text | 1–4,000 characters |
| `size` | string (enum) | `1024x1024`, `1536x1024`, `1024x1536` |
| `quality` | string (enum) | `low`, `medium`, `high` |
| `reference_images` | jsonb | array of 0–4 URLs |
| `request_hash` | string(64) | sha256 of the normalized request; detects id reuse with other params |
| `estimate_min` / `estimate_likely` / `estimate_max` | bigint | user price, nanodollars |
| `estimate_rates` | jsonb | rates the estimate used |
| `rates` | jsonb, nullable | Pixelforge's per-generation rates snapshot |
| `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens` | integer, nullable | from `usage` |
| `provider_cost` | bigint, nullable | set on success (or anomaly) |
| `charged` | bigint, nullable | user price, set on success only |
| `error_code` / `error_message` | string / text, nullable | Pixelforge or local codes (`waited_too_long`, `submit_rejected`) |
| `image_url` / `image_url_expires_at` | text / timestamptz, nullable | Pixelforge's signed URL |
| `image_path` | string, nullable | our stored copy |
| `provider_created_at`, `started_at`, `completed_at` | timestamptz, nullable | from Pixelforge; `completed_at` decides the billing day |
| `available_at` | timestamptz | earliest time the dispatcher may submit it (concurrency backoff) |
| `submit_attempts` | integer, default 0 | |
| `had_ambiguous_attempt` | boolean, default false | forbids marking failed locally |
| `is_anomaly` | boolean, default false | Pixelforge succeeded after we failed it |
| `last_synced_at` | timestamptz, nullable | last snapshot applied; drives polling |
| `created_at`, `updated_at` | timestamptz | |

Indexes: `(status, available_at, created_at)` for the dispatcher; `(user_id, created_at desc)`
for history; `(status, last_synced_at)` for polling; `(completed_at)` for reports.

Validation (form and server, mirrors Pixelforge): prompt required, 1–4,000 Unicode characters;
size and quality from the enums; `reference_images` ≤ 4 items, each a valid `http`/`https` URL.

### State transitions

| From | To | Trigger |
|------|----|---------|
| (new) | `waiting` | user submits (with hold) |
| `waiting` | `submitting` | dispatcher finds a free slot |
| `waiting` | `failed` | waited longer than `PIXELCAT_MAX_WAIT_MINUTES` (default 15) |
| `submitting` | `waiting` | `concurrency_limit_exceeded` (sets `available_at`) |
| `submitting` | `queued`/`processing`/final | 202 response or any snapshot |
| `submitting` | `failed` | definite rejection and `had_ambiguous_attempt = false` |
| `queued` | `processing`/final | snapshot |
| `processing` | final | snapshot |

Snapshot rule: apply only if its rank is higher than the current rank (waiting 0, submitting
1, queued 2, processing 3, final 4); always fill missing `pixelforge_id`, timestamps and image
data. Final states never change, except the anomaly case (status stays `failed`, usage and
`provider_cost` recorded, `is_anomaly = true`).

## generation_events

Append-only timeline shown to the user.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `generation_id` | FK generations, indexed | |
| `type` | string | e.g. `submitted`, `slot_wait`, `retrying`, `accepted`, `status_changed`, `duplicate_ignored`, `late_ignored`, `polled`, `failed`, `charged` |
| `message` | string | short, user-facing |
| `data` | jsonb, nullable | e.g. status, retry delay, delivery attempt |
| `created_at` | timestamptz | |

## webhook_deliveries

Every POST to the webhook endpoint. Written outside the processing transaction so it survives
rollbacks.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `event_id` | string, nullable, indexed | from `Pixelforge-Event-Id` |
| `event_type` | string, nullable | |
| `delivery_attempt` | integer, nullable | |
| `signature_valid` | boolean | |
| `outcome` | string (enum) | `applied`, `duplicate`, `out_of_order`, `unknown_generation`, `ping`, `rejected`, `error` |
| `generation_id` | FK generations, nullable | |
| `headers` | jsonb | |
| `payload` | jsonb, nullable | null when rejected |
| `received_at` | timestamptz | |

## webhook_events

| Column | Type | Notes |
|--------|------|-------|
| `event_id` | string, PK | the dedupe key |
| `type` | string | |
| `generation_id` | FK generations, nullable | |
| `payload` | jsonb | used by `pixelforge:replay-event` |
| `processed_at` | timestamptz | |

## ledger_entries

Append-only. Never updated or deleted.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `user_id` | FK users, indexed | |
| `generation_id` | FK generations, nullable | |
| `type` | string (enum) | `grant`, `purchase`, `hold`, `hold_release`, `charge` |
| `amount` | bigint | signed: grant/purchase/hold_release positive; hold/charge negative |
| `description` | string | e.g. "Welcome credit", "Bought $10 of credits" |
| `created_at` | timestamptz | |

Constraints: partial unique index on `(generation_id, type)` where type in (`hold`,
`hold_release`, `charge`); check constraint on the sign of `amount` per type.

Balance = `SUM(amount)` for the user. Balance checks and holds lock the user's row
(`SELECT … FOR UPDATE` on `users`) so concurrent submissions serialize.

## reconciliations

| Column | Type | Notes |
|--------|------|-------|
| `date` | date, PK | UTC completion day |
| `our_cost` | bigint | Σ `provider_cost` with `completed_at` on that day (anomalies included) |
| `our_count` | integer | |
| `pixelforge_cost` | bigint | parsed from `cost_usd` |
| `pixelforge_count` | integer | |
| `difference` | bigint | `our_cost - pixelforge_cost` |
| `checked_at` | timestamptz | |

## Configuration (env)

| Key | Default | Purpose |
|-----|---------|---------|
| `PIXELFORGE_BASE_URL` | `https://pixelforge.promptedit.dev/v1` | fake: `{APP_URL}/_fake/pixelforge/v1` |
| `PIXELFORGE_API_KEY` | — | per environment |
| `PIXELFORGE_WEBHOOK_SECRET` | — | `whsec_…`, per environment |
| `PIXELFORGE_FAKE` | false | enables the local fake (never in production) |
| `PIXELCAT_MARKUP_PERCENT` | 25 | user price markup |
| `PIXELCAT_MAX_ACTIVE` | 3 | account slots |
| `PIXELCAT_MAX_ACTIVE_PER_USER` | 2 | |
| `PIXELCAT_DAILY_CAP_PER_USER` | 50 | |
| `PIXELCAT_MAX_WAIT_MINUTES` | 15 | |
| `PIXELCAT_WELCOME_CREDIT` | 2000000000 | nanodollars |
| `DEMO_USER_PASSWORD`, `ADMIN_PASSWORD` | — | seeder |
