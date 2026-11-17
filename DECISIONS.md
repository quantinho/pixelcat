# Decisions

The assessment leaves some details open on purpose. These are the choices I made, why, and
what I considered instead. The full reasoning lives in
[`specs/001-image-generation/research.md`](specs/001-image-generation/research.md); the rules
the code must follow are in the [constitution](.specify/memory/constitution.md).

## Money

### 1. Amounts are integer nanodollars

- **Decision:** every amount is a `bigint` in nanodollars (1 USD = 1,000,000,000). Parsing and
  multiplication go through `brick/math`; floats never touch money, in PHP, SQL or Vue.
- **Why:** Pixelforge prices are exact to 9 decimals, and a single reasoning token costs
  $0.0000125. Nanodollars represent every price exactly, rates become whole numbers
  (12.50 per 1M tokens = 12,500 nanodollars per token), and sums are plain integer sums that
  match `GET /v1/usage` to the last digit.
- **Considered:** cents (a low-quality image costs about $0.014, so everything rounds away),
  micro-dollars (half-units get lost), floats (drift), `numeric(20,9)` columns (also exact; a
  matter of taste, but every value becomes a string and rounding hides in casts).

### 2. The markup rounds up, once, per generation

- **Decision:** user price = `ceil(provider cost × 1.25)` to the next nanodollar, computed in
  one function (`CalculateCost::userPrice`). It is the only rounding in the system.
- **Why:** rounding down would sell below cost on some generations; rounding per generation
  keeps every charge explainable on its own. The difference is under one nanodollar.

### 3. The estimate is a range, and the hold is its maximum

- **Decision:** before submitting, users see a minimum, a most likely price and a maximum. The
  prompt token range is rounded outwards from the docs' ±15%, and reasoning tokens span the
  quality's documented range. With balances on, we hold the maximum and settle to the actual
  price when the generation finishes.
- **Why:** two parts of the price are only known afterwards. A range is honest; holding the
  maximum means a user can't overspend.
- **Edge case:** if Pixelforge raises prices while a generation waits for a slot, the actual
  price can exceed the hold. We charge the actual price and log it.

### 4. Costs use the generation's own rates

- **Decision:** the provider cost uses the `rates` on the generation object, not the current
  price list.
- **Why:** Pixelforge fixes rates when it creates a generation; that is what it bills.

### 5. The ledger is append-only

- **Decision:** holds, charges, releases, grants and purchases are rows that are never updated
  or deleted. A balance is their sum. The database enforces it: a partial unique index allows
  one hold, one release and one charge per generation, a check constraint fixes each entry's
  sign, and a trigger refuses updates.
- **Why:** "never charged twice" should not depend on application code being right.

## Talking to Pixelforge

### 6. Our generation id is the idempotency key

- **Decision:** the form creates the generation's UUID. It is also Pixelforge's
  `Idempotency-Key` and `metadata.local_id`.
- **Why:** a double click, a browser resubmit, a retried job or a crash after Pixelforge
  accepted the request all map to one Pixelforge generation, never two.

### 7. Unknown outcomes are never treated as failures

- **Decision:** a generation is marked failed locally only after an answer that, per the docs,
  means nothing was created (400, 401, 409, 422, 429, 503). After a timeout or an unexpected
  response we keep resending with the same key until Pixelforge answers clearly.
- **Why:** if Pixelforge created the generation and we gave up, it would bill us for an image
  we never charged for.

### 8. Webhooks are applied inside the request

- **Decision:** verify the signature, record the delivery, then in one transaction insert the
  event id (the dedupe) and apply the change. Only slow work, like copying the image, is queued.
- **Why:** applying a snapshot takes milliseconds, far inside the 10-second limit. Doing it in
  the dedupe transaction means an event can't be marked as seen without being applied; if
  anything fails we answer 5xx and Pixelforge redelivers.
- **Considered:** dedupe first, then queue the work (an event could be recorded and then lost).

### 9. One writer, and status only moves forward

- **Decision:** webhooks, polling and replays all go through `ApplyGenerationSnapshot`, which
  locks the row and only moves a generation forward (waiting → submitting → queued →
  processing → succeeded/failed). Late and duplicate events are recorded and ignored.
- **Why:** one place to reason about money, and out-of-order delivery can't undo a result.

### 10. Follow-ups are event-driven, not a cron job

- **Decision:** each generation schedules its own follow-ups as delayed queue jobs (submit,
  expiry, and a sync that checks Pixelforge if no webhook arrived). An hourly sweep is the
  safety net. Delays are capped at 15 minutes, the queue's limit.
- **Why:** Serverless Postgres bills per awake second. An every-minute poller would keep it
  awake around the clock; event-driven jobs let the app and database sleep when nobody uses
  them, and a webhook usually arrives before any sync is due.

### 11. Shared limits are shared fairly

- **Decision:** Pixelforge's limits (3 at once, 60 requests a minute, 500 a day) apply to the
  whole account, so the app hands out slots itself: first come, first served across users, at
  most 2 running per user, at most 50 per user per day.
- **Why:** one user shouldn't be able to block everyone else, and users see "Waiting for a free
  slot" instead of an error.

## Product

### 12. Failures are free, and "Try again" never resubmits on its own

- **Decision:** a failed generation releases its hold in full and says "You weren't charged".
  "Try again" reopens the form with the same settings and a fresh price; the user confirms.
  Pixelforge's `internal_error` is not retried automatically.
- **Why:** the user stays in control of anything they pay for.

### 13. Open sign-up with a $2 welcome credit, no email verification

- **Why:** reviewers can try it immediately. There is no mail service, so verification would
  lock everyone out; per-user caps protect the shared Pixelforge quota.

### 14. Billing days follow Pixelforge's `completed_at`, in UTC

- **Decision:** reports and reconciliation group costs by the UTC day Pixelforge completed the
  generation, not when we received the webhook. The database session runs in UTC.
- **Why:** that is how `GET /v1/usage` bills, so our numbers match it day by day.

### 15. Images are copied to private storage

- **Why:** Pixelforge's URLs expire after 24 hours. The copy lives in a private bucket and is
  served only to its owner through short-lived signed URLs.

### 16. Live status uses polling, not WebSockets

- **Decision:** the history page refreshes every 3 seconds while something is unfinished.
- **Why:** it's enough for a 5–30 second process and has no extra moving parts.

## Engineering

### 17. A fake Pixelforge for tests and local development

- **Decision:** a fake built from the API docs drives every failure on demand: duplicates,
  reordering, timeouts after creating, 429s and 503s. No test calls the real API.
- **Why:** the rare cases are the ones that lose money, and they can't be produced reliably
  against the real service.

### 18. Postgres everywhere, tests included

- **Why:** the guarantees above (unique and partial indexes, row locks, `SKIP LOCKED`, the
  trigger) are Postgres behaviour, so the tests run against Postgres, including tests with two
  real processes racing for the same event.

### 19. Hosting on Laravel Cloud

- **Why:** a managed queue, scheduler, Postgres and object storage with no infrastructure code,
  scaling to zero when idle. Every merge to `main` deploys only after CI passes.

## Not built, and how I would add them

- **Prompt search (Typesense):** index prompts through Laravel Scout and search history from the
  History page.
- **Product analytics (ClickHouse):** emit events (submitted, succeeded, failed, cost) to a
  queue and load them into ClickHouse in batches; never query it on a user's request path.
- **Heavier media work (ECS):** an ffmpeg worker can't run on Laravel Cloud's managed queues;
  I'd run those workers as an ECS service reading the same queue, with Terraform for the
  infrastructure.
- **Push updates (Reverb/WebSockets):** broadcast status changes instead of polling once
  traffic justifies it.
