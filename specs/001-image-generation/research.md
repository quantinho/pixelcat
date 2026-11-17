# Research: Paid image generation through Pixelforge

Each entry: decision, rationale, alternatives considered.

## R1. Stack baseline

- **Decision**: Laravel 13 Vue starter kit: Inertia 3, Vue 3 + TypeScript, Tailwind 4, reka-ui
  components, Fortify auth, Wayfinder typed routes, Pest 5. PHP 8.5 locally, `^8.3` required.
- **Rationale**: Their stack; the starter kit gives auth, settings and layout for free. Inertia 3
  ships `usePoll`, which covers live status. `brick/math` is already in the dependency tree;
  we require it explicitly because we depend on it directly.
- **Alternatives**: Breeze-style manual scaffolding (more code, no benefit); React kit (not
  their stack).

## R2. Money representation

- **Decision**: Integer nanodollars in `bigint` columns; `brick/math` `BigDecimal` for parsing
  Pixelforge decimal strings and for multiplication; one `Money` helper for conversion,
  rounding (ceil for user price) and formatting.
- **Rationale**: Pixelforge costs are exact to 9 decimals, so nanodollars are exact integers and
  sums are plain SQL `SUM`. Rates like `12.50` per 1M tokens give 12,500 nanodollars per token,
  still an integer. `bigint` holds about 9.2 billion USD.
- **Alternatives**: `numeric(20,9)` columns (exact too, but every read becomes a string and
  rounding rules hide in casts); floats (rejected by the constitution); cents (lose precision).

## R3. Where estimates are calculated

- **Decision**: On the server only. The form calls `POST /generations/estimate` (debounced)
  and renders the formatted strings it returns.
- **Rationale**: One implementation of the pricing rules, in PHP, covered by the same tests
  as the real cost. Keeps floats out of Vue entirely.
- **Alternatives**: Duplicate the formula in TypeScript with `BigInt` (two implementations to
  keep in sync; drift risk).

## R4. Queue

- **Decision**: Production uses a Laravel Cloud **managed queue** (Flex, standard, SQS-backed;
  `QUEUE_CONNECTION=cloud`, requires `aws/aws-sdk-php` and Laravel ≥ 13.19). Local and CI use
  the `database` driver. Jobs are idempotent because delivery is at least once.
- **Rationale**: Managed queues are Cloud's recommended option, scale to zero and cost cents.
  Per-job delays up to 15 minutes cover all our `Retry-After` and backoff values; jobs finish
  far below the 90-second Flex limit.
- **Alternatives**: `queue:work` as a background process on the app cluster (stops when the app
  sleeps); worker clusters (Growth plan).
- **Source**: https://laravel.com/cloud/docs/queues

## R5. Scheduler and scale to zero

- **Decision**: Enable the Scheduler on the App cluster. Turn scale to zero off while reviewers
  use the app.
- **Rationale**: The poller, dispatcher sweep and reconciliation run every minute. Cloud wakes a
  sleeping environment for scheduled tasks, but an every-minute task keeps it awake anyway, and
  a sleeping app adds cold starts to webhook deliveries (10-second limit). Cost of an always-on
  Flex 512 MB instance is about $6 a month.
- **Alternatives**: Scale to zero with wake-ups (slower first requests, nothing saved).
- **Source**: https://laravel.com/cloud/docs/scheduled-tasks, https://laravel.com/cloud/docs/pricing

## R6. Database

- **Decision**: Postgres everywhere: Postgres.app locally and in CI (service container), Laravel
  Serverless Postgres in production.
- **Rationale**: Correctness relies on Postgres behaviour: unique and partial unique indexes,
  `SELECT … FOR UPDATE`, `SKIP LOCKED`, transaction-scoped advisory locks. Tests must exercise
  the same engine (constitution IV). SQLite is not supported in Cloud production anyway.

## R7. Fair slot dispatching

- **Decision**: A `DispatchWaitingGenerations` action, serialized by a transaction-scoped
  Postgres advisory lock. It counts active generations (global ≤ 3, per user ≤ 2), picks the
  oldest eligible `waiting` rows (`available_at <= now`, FIFO by `created_at`), moves them to
  `submitting` and dispatches one submit job each. It runs after every submission, after every
  generation reaches a final state, after a concurrency 429 (delayed by `Retry-After`), and
  every minute from the scheduler.
- **Rationale**: Simple, no long-running process, safe with several workers. The local count
  is an optimization; Pixelforge's 429 remains the source of truth (limits are per account
  across environments).
- **Alternatives**: A FIFO SQS queue (no per-user cap); a semaphore in cache (drifts from the
  database truth).

## R8. Webhook processing in the request

- **Decision**: Verify the signature, record the delivery, then in one transaction insert the
  event id and apply the snapshot. Only the image copy is queued.
- **Rationale**: Applying a snapshot is a few indexed writes, far below the 10-second budget.
  Doing it inside the dedupe transaction means an event can't be marked as seen without being
  applied; a failure rolls back and returns 5xx, so Pixelforge redelivers.
- **Alternatives**: Dedupe then queue (an event can be recorded and lost if the dispatch or job
  fails).

## R9. Submit job retries and ambiguity

- **Decision**: `SubmitGenerationToPixelforge` is unique per generation (`ShouldBeUnique`). It
  sends the stored parameters with the generation UUID as `Idempotency-Key`. Outcomes follow
  the spec's Failure Modes table: definite rejections end it or return it to `waiting`; 429/503
  release the job with `Retry-After` plus exponential backoff (cap 5 attempts for 503); network
  errors and unexpected 5xx mark the generation as having an ambiguous attempt and retry with
  the same key indefinitely (backoff up to 10 minutes); the poller re-dispatches stuck
  `submitting` rows.
- **Rationale**: Pixelforge idempotency keys never expire and replays don't count against
  limits, so resending is always safe and eventually yields a definite answer.
- **Alternatives**: Marking failed after N network errors (risks a billed-but-uncharged image).

## R10. Polling and the request budget

- **Decision**: The Pixelforge client records `X-RateLimit-Remaining` in cache after every
  response. `pixelforge:poll` (every minute) fetches at most 10 stale generations and stops
  early when the remaining budget is under 20, leaving room for creates.
- **Rationale**: The 60 requests/minute limit is shared by every environment of the account.
- **Alternatives**: Polling everything every minute (wastes the budget when webhooks work).

## R11. Image storage

- **Decision**: After success, `StoreGenerationImage` downloads the PNG to the `images` disk:
  Laravel Object Storage (S3 driver, private bucket) in production, the local disk otherwise.
  The browser loads `/generations/{id}/image`, which authorizes and redirects to a short-lived
  signed URL (or streams locally). Until the copy exists, the Pixelforge URL is used.
- **Rationale**: Images keep working after 24 hours, and only the owner can see them.
- **Source**: https://laravel.com/cloud/docs/pricing (Object Storage, R2, S3-compatible)

## R12. Fake Pixelforge

- **Decision**: Two fakes built from the docs. (1) `Tests\Support\FakePixelforge`, an
  `Http::fake` responder with scripted outcomes and a helper that builds signed webhook
  requests, for tests. (2) `App\Dev\FakePixelforge`, local-only routes under
  `/_fake/pixelforge/v1` plus a simulator job that advances generations and posts signed
  webhooks (duplicates, reordering, failures and keyword behaviour), so the app runs end to end
  without an account. Registered only when `PIXELFORGE_FAKE=true` and the environment is not
  production.
- **Rationale**: Every failure mode is testable on demand; development starts before the real
  account exists.
- **Alternatives**: Recording real responses (needs the account; can't produce rare cases).

## R13. Concurrency tests

- **Decision**: Tests that prove "exactly once" under concurrency run two real PHP processes
  (`Process::pool`) applying the same event against the test database, then assert one charge.
- **Rationale**: Locks and unique constraints only show their value across connections; a
  single-process test can't interleave transactions.

## R14. Deploys

- **Decision**: GitHub Actions runs Pint, Larastan, Pest (Postgres service) and the Vite build.
  On `main`, after all pass, it POSTs the Cloud deploy hook with `?commit_hash=${{ github.sha }}`
  and then runs the smoke test against the public URL. Cloud's push-to-deploy is off. Cloud
  deploy commands run `php artisan migrate --force`.
- **Rationale**: Only green commits reach production; the hook pins the exact commit.
- **Source**: https://laravel.com/cloud/docs/deployments

## R15. Plan

- **Decision**: Laravel Cloud Starter ($5/month including $5 of usage credit, first month free):
  Flex compute, 1 managed queue, Serverless Postgres up to 1 CU, Object Storage, custom domains.
- **Rationale**: Covers everything this app needs.
- **Source**: https://laravel.com/cloud/docs/pricing
