# Feature Specification: Paid image generation through Pixelforge

**Feature Branch**: `main` (bootstrap; later work lands through pull requests)

**Created**: 2026-11-17

**Status**: Draft

**Input**: Signed-in users generate images through the Pixelforge API, see live status and
results, and are charged Pixelforge's cost plus 25% for each successful generation. Webhooks
are verified and safe against duplicates and reordering. Stretch: operator report reconciled
with `GET /v1/usage`, and credit balances with a fake "Buy credits".
Sources: [assessment](https://pixelforge.promptedit.dev/assessment),
[API docs](https://pixelforge.promptedit.dev/docs).

## Clarifications

### Session 2026-11-17

- Q: Who can create an account? → A: Anyone; per-user caps protect the shared Pixelforge quota.
- Q: Starting balance for new users? → A: $2.00 welcome credit, as a `grant` ledger entry.
- Q: Retry `internal_error` automatically? → A: No; the user gets "Try again", which reopens the
  form with the same parameters.
- Q: How many generations may one user start per UTC day? → A: 50 (configurable).

## Glossary

- **Provider cost**: what Pixelforge bills us for a generation.
- **User price**: what we charge the user: provider cost plus 25%, rounded up to the next
  nanodollar. Stored as `charged`.
- **Active generation**: one in `submitting`, `queued` or `processing`.
- **Definite rejection**: a Pixelforge answer that, per the docs, means nothing was created
  (400, 401, 409, 422, 429, 503).
- **Ambiguous attempt**: a create request whose outcome we don't know (timeout, connection
  error, other 5xx). Pixelforge may have created the generation. A definite answer to a later
  attempt with the same Idempotency-Key resolves it, because Pixelforge replays an existing
  generation before applying limits.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Generate an image and pay exactly its price (Priority: P1)

A signed-in user writes a prompt, picks size and quality, optionally adds up to 4 reference
image URLs, sees an estimated price range, and submits. When the generation succeeds they see
the image and are charged its exact price. If it fails they pay nothing.

**Why this priority**: This is the product and the core of the assessment (correct money).

**Independent Test**: Against the fake Pixelforge, submit generations of every size, quality and
reference count; complete them with known token usage; assert stored provider cost and user
price to the nanodollar.

**Acceptance Scenarios**:

1. **Given** a 400-character prompt, high quality, 1536x1024 and 2 reference images, **When**
   the generation succeeds with the documented maximum usage (115 text, 1,530 image, 6,450
   output, 8,000 reasoning tokens), **Then** provider cost is exactly `0.373875000` USD and the
   user price is exactly `0.467343750` USD.
2. **Given** any valid form input, **When** the user changes it, **Then** the estimate range
   updates without a page reload and says the final price depends on prompt length and the
   model's thinking.
3. **Given** a submitted generation, **When** it fails (`generation_failed` or
   `internal_error`), **Then** no charge exists and the user sees why, plus "You weren't
   charged".
4. **Given** the user double-clicks Generate or the browser resubmits, **When** both requests
   arrive, **Then** exactly one generation exists.

---

### User Story 2 - Generation state is always right (Priority: P1)

Whatever Pixelforge does (limits, overloads, failures after acceptance, duplicate, late or
missing webhooks, lost responses), every generation ends in the correct final state with the
correct charge, and our records agree with what Pixelforge bills.

**Why this priority**: Robustness is the second grading criterion, and wrong state means wrong
money.

**Independent Test**: The fake Pixelforge delivers duplicates, reorders events, delivers
`generation.succeeded` before the create request returns, drops all webhooks for a generation,
times out after creating, and answers 429/503. Final states and charges are asserted.

**Acceptance Scenarios**:

1. **Given** a succeeded generation, **When** `generation.succeeded` arrives again (same event
   id), **Then** it is answered 2xx, logged as a duplicate and changes nothing.
2. **Given** a succeeded generation, **When** a retried `generation.processing` arrives,
   **Then** it is logged as out of order and the status stays `succeeded`.
3. **Given** a webhook whose signature is wrong or whose timestamp is more than 300 s away from
   server time, **When** it arrives, **Then** it is rejected with 400 and nothing changes.
4. **Given** Pixelforge never delivers any webhook for a generation, **When** the polling
   fallback runs, **Then** the generation reaches its final state and correct charge.
5. **Given** the create request times out after Pixelforge created the generation, **When** we
   resend it with the same Idempotency-Key, **Then** we get that same generation back, and it
   is never marked failed on our side while it may exist at Pixelforge.
6. **Given** the account is at its concurrency limit, **When** users submit, **Then**
   generations wait in `waiting`, the user sees "Waiting for a free slot", and they are
   submitted in fair order.
7. **Given** the same webhook event is processed by two requests at the same moment, **When**
   both finish, **Then** exactly one charge exists.

---

### User Story 3 - History with live status (Priority: P2)

The user sees all their generations, newest first, with live status, estimated and actual
price, the image, plain error messages, a per-generation event timeline and "Try again" for
failures.

**Why this priority**: Required by the assessment; carries most of the product-sense score.

**Independent Test**: Seed generations in every state; assert the history page props and that
the page polls only while something is unfinished.

**Acceptance Scenarios**:

1. **Given** unfinished generations, **When** the history page is open, **Then** status updates
   appear within 5 seconds without a reload, and polling stops when all are final.
2. **Given** a succeeded generation, **When** its Pixelforge URL has expired, **Then** the
   stored copy is shown; if no copy exists, an "Image expired" placeholder is shown.
3. **Given** a failed generation, **When** the user presses "Try again", **Then** the generate
   form opens with the same parameters and a fresh estimate; nothing is submitted until the
   user confirms.
4. **Given** another user's generation id, **When** the user requests it, **Then** they get 404.

---

### User Story 4 - Operator report and reconciliation (Priority: P3, stretch)

An admin sees, per UTC day and per user: credit spend (charged to users), provider cost and
profit. Daily provider cost is compared with `GET /v1/usage`, and differences are flagged.

**Why this priority**: Stretch goal; it also proves money correctness to the reviewers.

**Independent Test**: Seed succeeded and failed generations across UTC days, fake
`/v1/usage`, and assert report rows, profit and the match/mismatch flag.

**Acceptance Scenarios**:

1. **Given** a generation created on one UTC day and completed (Pixelforge `completed_at`) on
   the next, **When** the report is built, **Then** it counts on the completion day, as
   Pixelforge bills.
2. **Given** a past UTC day whose provider cost equals `cost_usd` from `/v1/usage`, **When**
   the reconciliation runs, **Then** the day is marked as matching; otherwise the difference is
   shown and logged. Today is shown as "in progress", not as a mismatch.
3. **Given** a non-admin user, **When** they open an operator page, **Then** they get 403.

---

### User Story 5 - Credit balances (Priority: P3, stretch)

Each user has a credit balance. "Buy credits" adds credits (fake purchase). Generating is
blocked when the user can't afford the top of the estimate.

**Why this priority**: Stretch goal.

**Independent Test**: Buy credits, generate, succeed and fail; assert the ledger entries and the
balance after each step.

**Acceptance Scenarios**:

1. **Given** a balance below the estimate's maximum, **When** the user submits, **Then** the
   request is refused with the amount missing and a "Buy credits" action.
2. **Given** a submitted generation, **When** it succeeds, **Then** the hold is released and the
   user price is charged; **When** it fails, **Then** the hold is released and the balance is
   back to its value before submitting.
3. **Given** two submissions sent at the same moment that together exceed the balance, **When**
   both are processed, **Then** only the affordable one is accepted.

---

### Edge Cases

- Prompt with multi-byte characters (Ukrainian, emoji): length counted in Unicode characters.
- Prompt containing test keywords (`[fail]`, `[slow]`, ...): keywords count as prompt tokens.
- Webhook for a generation we don't know (e.g. another environment): 2xx, logged, ignored.
- Webhook arriving before our create request returned: matched by `metadata.local_id`.
- `ping` event: 2xx, logged.
- Generation created on one UTC day, completed on the next: billed and reported on completion
  day, using Pixelforge's `completed_at`, not when we heard about it.
- Pixelforge prices change between estimate and submit: the submission is refused and the
  user sees the new estimate before anything is sent.
- Prices change while a generation is waiting for a slot: Pixelforge bills at the rates in force
  when it creates the generation, so the actual price can exceed the hold. We charge the actual
  price (the balance may dip below zero by that difference) and log a warning.
- Pixelforge reports success for a generation we had marked failed: must not happen (we never
  fail a generation after an ambiguous attempt). If it does, keep it `failed` and uncharged,
  but store its usage and provider cost so reconciliation still matches Pixelforge, flag it as
  an anomaly, log it as critical and show it in the admin report.
- One person creating many accounts can still exhaust the account's 500 per day; accepted risk
  for a demo app, and `daily_limit_exceeded` is handled cleanly when it happens.
- Limits are per Pixelforge account, shared by all its keys: local development can consume
  production's slots. Pixelforge's 429 is the source of truth; the local slot count only avoids
  needless requests.

## Requirements *(mandatory)*

### Functional Requirements

**Generating**

- **FR-001**: Users MUST sign up and sign in (starter-kit auth). Sign-up is open to anyone and
  needs no email verification. Each user sees only their own generations.
- **FR-002**: The generate form MUST accept a prompt (1–4,000 characters), size (`1024x1024`
  default, `1536x1024`, `1024x1536`), quality (`low`, `medium` default, `high`) and up to 4
  http(s) reference image URLs, validated with the same rules as Pixelforge.
- **FR-003**: The form MUST show a live user-price estimate: minimum, most likely and maximum,
  with a per-token-type breakdown and the rates' age. It offers one-click chips that add the
  test keywords, and Cmd/Ctrl+Enter submits.
- **FR-004**: Each generation id MUST be a UUID created by the form and replaced after every
  accepted submission. It is also the Pixelforge `Idempotency-Key` and `metadata.local_id`. The
  same id with the same parameters returns the existing generation; the same id with different
  parameters is refused (409) and the form gets a new id.
- **FR-005**: Submitting MUST create the generation in `waiting` (and its hold, FR-017) and
  return immediately; a queued job sends it to Pixelforge when a slot is free.
- **FR-006**: The app MUST hand out Pixelforge concurrency slots fairly: FIFO across users, at
  most 2 active generations per user. A generation waiting longer than a configurable limit
  (default 15 minutes) fails with "Pixelforge is too busy right now. You weren't charged."
- **FR-007**: A user MUST NOT start more than 50 generations per UTC day (configurable),
  failed ones included. A submission over the cap is refused before anything is created or
  held, with the time the cap resets.

**Talking to Pixelforge**

- **FR-008**: The submit job MUST handle every create outcome as in the Failure Modes table:
  definite rejections end the generation (`failed`, no charge) or put it back in `waiting`;
  429 and 503 are retried after `Retry-After` with increasing backoff, capped; ambiguous
  attempts are retried with the same Idempotency-Key until Pixelforge gives a definite answer.
- **FR-009**: The webhook endpoint MUST verify the signature on the raw body before anything
  else, record every delivery, and then, in one database transaction, insert the event id
  (unique) and apply the snapshot. Duplicates are answered 2xx. If the transaction fails the
  endpoint answers 5xx so Pixelforge retries. Slow work (image copy) is queued.
- **FR-010**: Webhooks, polling and replay MUST change state and money only through one "apply
  generation snapshot" operation, which locks the generation row.
- **FR-011**: Follow-ups MUST be event-driven, so an idle app touches neither Pixelforge nor the
  database (both can scale to zero):
  - creating a generation dispatches the slot dispatcher and a delayed expiry check at the
    waiting limit (FR-006);
  - a generation reaching a final state, or a concurrency 429 (after `Retry-After`), dispatches
    the slot dispatcher;
  - Pixelforge accepting a generation schedules a delayed sync (+2 minutes) that fetches it if no
    update arrived, and reschedules itself with backoff (2, 4, 8, then every 15 minutes) until the
    generation is final;
  - at most one submit job and one sync job run per generation at a time; syncs respect a
    request budget that leaves room for new submissions.
  An hourly sweep is the safety net: it dispatches waiting generations, re-dispatches stuck
  `submitting` ones (same Idempotency-Key), syncs `queued`/`processing` ones with no update for
  over 15 minutes, and fails generations that waited too long.
- **FR-012**: On success the app MUST store a copy of the image and keep showing it after the
  Pixelforge URL expires.

**Seeing results**

- **FR-013**: History MUST show status (with "Waiting for a free slot" for `waiting`), estimate,
  actual price and where it landed in the estimate range, image, error message, event timeline
  and "Try again", and update live while anything is unfinished.
- **FR-014**: When Pixelforge is rate-limiting or overloaded (low `X-RateLimit-Remaining`, recent
  503s), the app MUST show a "Pixelforge is busy" notice.
- **FR-015**: A failed generation's "Try again" MUST open the generate form with the same
  parameters; it never resubmits on its own. `internal_error` is not retried automatically.

**Balances (stretch)**

- **FR-016**: Users MUST have a credit balance, shown in the header, and a fake "Buy credits"
  action with fixed packs ($5, $10, $25).
- **FR-017**: Submitting MUST check the balance and place a hold of the estimate's maximum in
  one transaction that locks the user's ledger, so concurrent submissions can't overspend.
  Submitting is refused when the balance is below the maximum.
- **FR-018**: New users MUST start with a $2.00 `grant` ledger entry ("Welcome credit").

**Operators (stretch)**

- **FR-019**: Admins MUST see a report per UTC day and per user (charged, provider cost,
  profit), the daily reconciliation against `/v1/usage`, recent webhook deliveries and any
  billing anomalies.
- **FR-020**: `pixelforge:reconcile` MUST compare each completed UTC day's provider cost and
  count with `/v1/usage`, store the result and log differences; it runs daily and on demand.
- **FR-021**: `pixelforge:replay-event {evt_id}` MUST reprocess a stored event through FR-010.

**Demo data**

- **FR-022**: The seeder MUST create a demo user and an admin user, with passwords read from
  environment variables. Fake generations are seeded only outside production. In production,
  demo generations are created through the real API (a command that submits prompts with test
  keywords), so every recorded cost matches Pixelforge's own records.

### Key Entities

- **User**: starter-kit user plus `is_admin`.
- **Generation**: one image request. UUID primary key (also the Idempotency-Key);
  `pixelforge_id` unique, nullable until known; request fields and a hash of them (FR-004);
  status; estimate (min, likely, max, user nanodollars) and the rates used for it; Pixelforge
  `rates` snapshot; token usage; `provider_cost` and `charged` (nanodollars); error code and
  message; Pixelforge image URL and expiry; stored image path; Pixelforge `created_at`,
  `started_at`, `completed_at`; submit attempts and whether any was ambiguous; anomaly flag.
- **WebhookDelivery**: every webhook POST received, recorded even if processing fails: event
  id, type, delivery attempt, signature valid, outcome (`applied`, `duplicate`, `out_of_order`,
  `unknown_generation`, `ping`, `rejected`, `error`), raw payload. Rejected (unsigned)
  deliveries keep headers only, so anyone posting junk can't fill the database.
- **WebhookEvent**: one row per accepted event id; the unique `event_id` is the dedupe.
- **LedgerEntry**: append-only: user, optional generation, type (`grant`, `purchase`, `hold`,
  `hold_release`, `charge`), signed nanodollar amount. Unique (generation, type) for `hold`,
  `hold_release` and `charge`.
- **Reconciliation**: per UTC day: our provider cost and count, Pixelforge's `cost_usd` and
  count, difference, checked at.

## Technical Requirements

### State Machine

```
waiting ──slot──▶ submitting ──202──▶ queued ──▶ processing ──▶ succeeded
   ▲                  │                  │            │
   └── concurrency ───┤                  └────────────┴── generation.failed ──▶ failed
       429            └── definite rejection, or waited too long ─────────────▶ failed
```

- `waiting` and `submitting` are ours; `queued`, `processing`, `succeeded` and `failed` mirror
  Pixelforge.
- Pixelforge snapshots only move a generation forward. Rank: waiting 0, submitting 1, queued 2,
  processing 3, succeeded/failed 4. A snapshot of lower or equal rank changes nothing except
  filling missing data (e.g. `pixelforge_id`). Snapshots can skip states.
- The one backward move is ours: the submit job returns `submitting` → `waiting` on
  `concurrency_limit_exceeded`, when nothing exists at Pixelforge.
- We set `failed` ourselves only after a definite rejection and only if no attempt was ambiguous.
- `succeeded` and `failed` are final.

### Money Rules

- Unit: integer nanodollars. `1 USD = 1_000_000_000`.
- Rates are USD per 1M tokens as decimal strings. Cost of n tokens at rate r =
  `n × r × 1000` nanodollars, computed with arbitrary-precision decimals; a non-integer result
  is an error.
- Provider cost = Σ over the 4 token types, using the generation's own `rates`.
- User price = `ceil(provider_cost × 5 / 4)` nanodollars, per generation. Profit = user price −
  provider cost.
- Estimate (user price, at current `/v1/pricing` rates):
  - text tokens: `e = ceil(chars / 4)`; range `floor(0.85·e)` … `ceil(1.15·e)` (slightly wider
    than the docs' ±15%, so the hold always covers the real count).
  - image tokens: `765 × reference_count` (exact).
  - output tokens: exact by quality and size.
  - reasoning tokens: per-quality min … max; most likely = midpoint.
  - min, likely and max are each converted to user price with the same rounding.
- Ledger amounts: `hold` = −estimate max; `hold_release` = +estimate max; `charge` = −user
  price; `grant` and `purchase` positive. Balance = sum of entries.
- Success writes `charge` and `hold_release`; failure writes `hold_release` only.
- Days are UTC and come from Pixelforge's `completed_at`.
- Display: 4 decimals in lists; the exact 9-decimal value on hover and in details.

### External API Contract

All from the [API docs](https://pixelforge.promptedit.dev/docs):

- `GET /v1/pricing` (no auth): rates and token rules.
- `POST /v1/generations` with `Idempotency-Key`: 202 + generation in `queued`. Same key and
  params → same generation with `Idempotent-Replayed: true`, not counted against limits. Same
  key, different params → 409. Unknown params → 422. `metadata`: string values only.
- `GET /v1/generations/{id}`: current generation; 404 for other accounts.
- `GET /v1/usage?start_date&end_date`: per UTC completion day, `cost_usd` with 9 decimals,
  max 92 days, per API key.
- Webhooks: `generation.processing`, `generation.succeeded`, `generation.failed`, `ping`.
  Headers `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt`,
  `Pixelforge-Signature: t=…,v1=…` = hex HMAC-SHA256 of `t.raw_body` with the key's `whsec_`
  secret. 2xx within 10 s counts as delivered; otherwise retried up to 6 attempts over ~27 min.
  Redirects are not followed. At least once, any order.
- Limits per account (all keys): 60 requests/min, 3 queued or processing, 500 per UTC day.
  Every authenticated response has `X-RateLimit-Limit` and `X-RateLimit-Remaining`.
- Image URLs expire 24 h after completion.

### Failure Modes

| Failure | Source | Handling | User sees |
|---------|--------|----------|-----------|
| `content_policy_violation` 400 | create | `failed`, release hold | "This prompt was rejected. You weren't charged." |
| `overloaded` 503 | create | retry after `Retry-After`, backoff; `failed` after the cap | while retrying: "Pixelforge is busy, retrying…"; if it gives up: "Pixelforge is too busy right now. You weren't charged." |
| `rate_limit_exceeded` 429 | any | wait `Retry-After`, retry, capped | while retrying: "Waiting for Pixelforge…"; if it gives up: "Pixelforge is too busy right now. You weren't charged." |
| `concurrency_limit_exceeded` 429 | create | back to `waiting`, retry after 5 s | "Waiting for a free slot" |
| `daily_limit_exceeded` 429 | create | `failed`, release hold | "Pixelforge's daily limit is reached. You weren't charged." |
| `validation_failed` 422, `idempotency_key_reused` 409 | create | `failed`, release hold, log error (our bug) | "Something went wrong on our side. You weren't charged." |
| `invalid_api_key` 401 | create | `failed`, release hold, log critical | same as above |
| `invalid_api_key` 401, `not_found` 404 | poll | log critical, state unchanged | — |
| Timeout, connection error, other 5xx | create | ambiguous: keep `submitting`, resend with the same key | "Submitting…" |
| `generation_failed` | event/poll | `failed`, release hold | "The model couldn't draw this one. You weren't charged." |
| `internal_error` | event/poll | `failed`, release hold | "Pixelforge had a problem. You weren't charged." + Try again |
| Waited too long for a slot | ours | `failed`, release hold | "Pixelforge is too busy right now. You weren't charged." |
| Bad signature or timestamp | webhook | 400, delivery logged `rejected` | — |
| Duplicate event | webhook | 2xx, logged `duplicate` | timeline: "Delivered again, ignored" |
| Out-of-order event | webhook | 2xx, logged `out_of_order` | timeline: "Arrived late, ignored" |
| Database error while applying | webhook | 5xx, logged `error`; Pixelforge retries | — |
| No webhook ever | — | polling fallback | normal status |
| Image URL expired | display | stored copy, else placeholder | "Image expired" |

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The documented worked example costs exactly `0.373875000` USD provider cost and
  `0.467343750` USD user price.
- **SC-002**: For 1,000 random valid requests and usages within the documented ranges, at
  unchanged rates, the actual user price always lies within the estimate's min and max.
- **SC-003**: Delivering any event twice, in any order, including concurrently, yields exactly
  one charge per succeeded generation and none per failed one.
- **SC-004**: For every completed UTC day, the app's provider cost and generation count equal
  `/v1/usage` for the production key, to the nanodollar.
- **SC-005**: Every failed generation ends with a balance identical to before it was submitted.
- **SC-006**: Every row of the Failure Modes table maps to a tested outcome and message.
- **SC-007**: Status changes appear in the history page within 5 seconds of the webhook, without
  a reload.
- **SC-008**: A request for another user's generation returns 404; an operator page requested
  by a non-admin returns 403.

## Assumptions

- Credits are US dollars, as the assessment states; purchases are fake.
- Images may stop loading after expiry per the assessment, but we store copies anyway.
- English UI only; users see times in their browser's time zone, operators in UTC.
- One production Pixelforge key; the operator report covers that key.
- Admins are created by the seeder, not through the UI.
- No mail service is configured: sign-up works without email verification, and password-reset
  emails go to the log. Reviewers can register and generate immediately.
- Live updates by polling are enough; WebSockets are out of scope.
