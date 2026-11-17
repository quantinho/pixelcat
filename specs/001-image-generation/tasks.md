---
description: "Tasks for paid image generation through Pixelforge"
---

# Tasks: Paid image generation through Pixelforge

**Input**: Design documents from `specs/001-image-generation/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: Required. Constitution IV: money and webhook behaviour is specified by tests before
or with the code, on Postgres.

**Organization**: By user story. The [Delivery slices](#delivery-slices) section maps tasks to
the plan's slices, which touch separate files and can be built as separate pull requests.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project scaffold, tooling and delivery pipeline

- [x] T001 Scaffold the Laravel 13 Vue starter kit (pgsql, Pest, npm) in a temporary folder and move it into the repository root, merging its `.gitignore` into ours and keeping `.gitleaks.toml`, `.specify/` and `specs/` untouched (composer.json, package.json, .gitignore)
- [x] T002 Require `brick/math`, `aws/aws-sdk-php`, `league/flysystem-aws-s3-v3`, and dev `larastan/larastan` in composer.json
- [x] T003 [P] Configure Larastan (level 8 over app, bootstrap, config, database and routes; Pest tests are excluded because their closures confuse static analysis) in phpstan.neon and the Pint preset in pint.json
- [x] T004 [P] Create config/pixelforge.php and config/pixelcat.php for every key in data-model.md "Configuration (env)", and list them with empty or default values in .env.example
- [x] T005 [P] Point tests at the Postgres database `pixelforge_test` in phpunit.xml
- [x] T006 [P] Create the CI workflow (Postgres service, Composer and npm install, Pint `--test`, Larastan, Pest, `npm run build`) in .github/workflows/ci.yml
- [x] T007 Add the deploy job to .github/workflows/ci.yml: on `main` after checks pass, POST `secrets.LARAVEL_CLOUD_DEPLOY_HOOK` with `?commit_hash=${{ github.sha }}`, then run scripts/smoke.sh against `vars.APP_URL` (site up, login page loads, webhook rejects a bad signature with 400)
- [x] T008 [P] Add a pull request template with a constitution checklist in .github/pull_request_template.md
- [x] T009 [P] Write the project guide for contributors and coding agents (commands, conventions, money rules, where things live) in CLAUDE.md
- [x] T010 Rename the app to Pixelcat, make `/dashboard` redirect to `/generate`, keep `User` without `MustVerifyEmail` and set the mailer to `log` (spec Assumptions), and register the empty slice route files with the middleware listed in contracts/http.md (routes/generations.php, routes/images.php, routes/credits.php, routes/operators.php, routes/webhooks.php, routes/fake-pixelforge.php) in bootstrap/app.php and routes/web.php
- [x] T011 Create stub commands `pixelforge:dispatch`, `pixelforge:poll` and `pixelforge:reconcile` (signature and description only; their slices fill `handle()`) in app/Console/Commands/DispatchGenerations.php, PollPixelforge.php and ReconcileUsage.php, and register the schedule (dispatch and poll hourly as the safety sweep of FR-011, reconcile daily 00:15 UTC, all `onOneServer()->withoutOverlapping()`) in routes/console.php

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema, money, Pixelforge client, ledger and the single apply path that every
story uses

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

### Tests for the foundation (write first, see them fail)

- [x] T012 [P] Test support: `FakePixelforge` with `Http::fake` responders for pricing, create (scripted outcomes per error code, timeouts), get and usage, a snapshot factory, and a signed webhook request builder in tests/Support/FakePixelforge.php; call `Http::preventStrayRequests()` for every test in tests/Pest.php so no test can reach the real API (constitution IV)
- [x] T013 [P] Unit tests for `Money` (parse 9-decimal strings without floats, multiply by rates, ceil markup, display rounded half-up to 4 decimals, exact to 9) in tests/Unit/Pricing/MoneyTest.php
- [x] T014 [P] Unit tests for `CalculateCost`: the docs' worked example gives exactly 0.373875000 provider cost and 0.467343750 user price (SC-001); rates with 2 decimals; a non-integer nanodollar result throws, in tests/Unit/Pricing/CalculateCostTest.php
- [x] T015 [P] Unit tests for `EstimatePrice`: multi-byte prompts counted in characters, keywords counted, reference images, text range `floor(0.85e)`…`ceil(1.15e)`, and a 1,000-case randomized check that the actual price lies within min…max (SC-002) in tests/Unit/Pricing/EstimatePriceTest.php
- [x] T016 [P] Unit tests for `WebhookSignature` (valid, wrong secret, tampered body, timestamp 301 s in the past or future, malformed header) in tests/Unit/Pixelforge/WebhookSignatureTest.php
- [x] T017 [P] Feature tests for `Ledger` (balance is the sum; grant; purchase; hold; settle writes charge and release; release on failure; a second charge or hold for a generation is rejected by the database; sign checks) in tests/Feature/Billing/LedgerTest.php
- [x] T018 [P] Feature tests for `ApplyGenerationSnapshot` (forward-only ranks, skipped states, lower rank only fills missing data, success settles exactly once, failure releases, final never changes, anomaly records cost without charging, timeline events written, `GenerationFinished` fired once) in tests/Feature/Generations/ApplyGenerationSnapshotTest.php
- [x] T019 [P] Feature test for `GenerationResource`: every status has its label, every row of the spec's Failure Modes table maps to its user-facing message (SC-006), money uses the `{display, exact}` shape, `estimate_position` is correct in tests/Feature/Generations/GenerationResourceTest.php
- [x] T020 [P] Feature test for the busy signal: `PixelforgeClient::isBusy()` turns on with `X-RateLimit-Remaining` under 10 or a 503 in the last 2 minutes and off afterwards, and the shared prop `pixelforge_busy` follows it (FR-014) in tests/Feature/Pixelforge/BusyStatusTest.php

### Implementation for the foundation

- [x] T021 Create migrations per data-model.md: `User.is_admin`; `generations` with `Generation.id` (uuid primary key), `Generation.pixelforge_id` (unique, nullable), `Generation.status`, `Generation.request_hash`, the bigint money columns `Generation.estimate_min`, `Generation.estimate_likely`, `Generation.estimate_max`, `Generation.provider_cost`, `Generation.charged`, plus `Generation.rates`, `Generation.available_at`, `Generation.had_ambiguous_attempt`, `Generation.is_anomaly`, `Generation.completed_at`, `Generation.last_synced_at` and the listed indexes; `generation_events` with `GenerationEvent.type`; `webhook_deliveries` with `WebhookDelivery.outcome` and nullable `WebhookDelivery.payload`; `webhook_events` with `WebhookEvent.event_id` as primary key; `ledger_entries` with the partial unique index on `LedgerEntry.generation_id`+`LedgerEntry.type` and the sign check on `LedgerEntry.amount`; `reconciliations` with `Reconciliation.date` as primary key and `Reconciliation.difference`, in database/migrations/
- [x] T022 [P] Create enums `GenerationStatus` (values per data-model.md `Generation.status`, with `rank()`, `isFinal()`, `label()`), `Size` and `Quality` (data-model.md `Generation.size`, `Generation.quality`), `LedgerEntryType` (data-model.md `LedgerEntry.type`) and `DeliveryOutcome` (data-model.md `WebhookDelivery.outcome`) in app/Enums/
- [x] T023 Create models with casts, relationships and factories for User, Generation, GenerationEvent, WebhookDelivery, WebhookEvent, LedgerEntry, Reconciliation in app/Models/ and database/factories/
- [x] T024 [P] Implement `Money` (nanodollar parsing, multiplication, rounding, formatting) in app/Billing/Money.php and `MoneyResource` (`{display, exact}`) in app/Http/Resources/MoneyResource.php
- [x] T025 Implement `CalculateCost` (provider cost from usage and the generation's rates; user price with configurable markup, rounded up) in app/Actions/Pricing/CalculateCost.php
- [x] T026 [P] Implement `Pricing`, `GenerationSnapshot` and the exceptions `PixelforgeRejected` (code, status, retryAfter) and `PixelforgeUnavailable` (ambiguous) in app/Pixelforge/
- [x] T027 Implement `PixelforgeClient` (pricing cached 5 minutes, createGeneration with `Idempotency-Key` and `metadata.local_id` and no unknown params, getGeneration, usage, 10 s timeouts, rate-limit budget and recent-503 tracking in cache, `isBusy()`) in app/Pixelforge/PixelforgeClient.php
- [x] T028 [P] Implement `WebhookSignature` in app/Pixelforge/WebhookSignature.php
- [x] T029 Implement `EstimatePrice` (min, likely, max user price, breakdown, `pricing_version`) in app/Actions/Pricing/EstimatePrice.php
- [x] T030 Implement `Ledger` (balance, grant, purchase, hold with user row lock, settle, release) in app/Billing/Ledger.php
- [x] T031 [P] Create domain events `GenerationAccepted`, `GenerationSucceeded` and `GenerationFinished` in app/Events/
- [x] T032 Implement `ApplyGenerationSnapshot` (row lock, rank rules, stores `Generation.rates` and `Generation.completed_at` from Pixelforge, updates `Generation.last_synced_at`, settles or releases through `Ledger`, sets `Generation.is_anomaly` for success after a local failure, timeline events, domain events fired after commit) in app/Actions/Generations/ApplyGenerationSnapshot.php
- [x] T033 [P] Implement `GenerationResource` per contracts/pages.md `Generation` (status labels and user-facing error messages per the spec's Failure Modes table, `estimate_position`) in app/Http/Resources/GenerationResource.php
- [x] T034 [P] Add the `admin` gate (data-model.md `User.is_admin`) and shared props `auth.user.is_admin`, `balance`, `pixelforge_busy`, `flash` in app/Providers/AppServiceProvider.php and app/Http/Middleware/HandleInertiaRequests.php
- [x] T035 [P] Add the TypeScript types from contracts/pages.md in resources/js/types/pixelcat.ts and a `MoneyText` component (display with exact value on hover) in resources/js/components/pixelcat/MoneyText.vue
- [ ] T036 Deploy the skeleton to Laravel Cloud (app cluster with scheduler, scale to zero off, managed queue, Serverless Postgres, object storage bucket as the `images` disk, deploy hook in GitHub secrets, push-to-deploy off) and confirm CI deploys `main` and the smoke test passes

**Checkpoint**: Foundation ready; the four slices can start in parallel.

---

## Phase 3: User Story 1 - Generate an image and pay exactly its price (Priority: P1) 🎯 MVP

**Goal**: A user estimates, submits, and is charged the exact price on success or nothing on
failure.

**Independent Test**: Against `FakePixelforge`, submit every size/quality/reference count,
complete with known usage, assert provider cost and user price to the nanodollar.

### Tests for User Story 1

- [x] T037 [P] [US1] Feature test for `POST /generations/estimate` (response shape per contracts/http.md, 422, `affordable`) in tests/Feature/Generations/EstimateEndpointTest.php
- [x] T038 [P] [US1] Feature test for `POST /generations`: creates `waiting` with a hold; same id and params creates nothing new; same id with other params → 409 `id_reused`; changed pricing → 409 `prices_changed`; low balance → 422 `insufficient_balance`; 51st of the day → 429 `daily_cap`; validation per data-model.md `Generation.prompt`, `Generation.size`, `Generation.quality`, `Generation.reference_images`; and `GET /generate?retry={id}` pre-fills from the user's own failed generation, ignores other users' ids, and never submits anything (FR-015) in tests/Feature/Generations/CreateGenerationTest.php
- [x] T039 [P] [US1] Feature test for `DispatchWaitingGenerations` (at most 3 active, at most 2 per user, FIFO, respects `Generation.available_at`, waited too long → failed with hold released; `DispatchWaiting` is queued on create, on `GenerationFinished` and after a concurrency 429; `ExpireWaitingGeneration` fails only a still-waiting generation) in tests/Feature/Generations/DispatchWaitingGenerationsTest.php
- [x] T040 [P] [US1] Feature test for the submit job happy path (exact request body and headers, 202 → `queued` with `pixelforge_id` and rates stored) in tests/Feature/Generations/SubmitGenerationJobTest.php
- [ ] T041 [P] [US1] Feature test for the webhook happy path (`generation.succeeded` charges exactly once; `generation.failed` is free; responses 204) in tests/Feature/Webhooks/WebhookApplyTest.php

### Implementation for User Story 1

- [x] T042 [P] [US1] Implement `GenerationRequest` with Pixelforge's rules for data-model.md `Generation.prompt`, `Generation.size`, `Generation.quality`, `Generation.reference_images` in app/Http/Requests/GenerationRequest.php
- [x] T043 [US1] Implement `CreateGeneration` (form-supplied `Generation.id`, `Generation.request_hash` idempotency, daily cap, pricing version, user row lock, hold, `submitted` event, triggers dispatch) in app/Actions/Generations/CreateGeneration.php
- [x] T044 [US1] Implement `EstimateController` and `GenerationController@create/store` (Create page props per contracts/pages.md, including `prefill` from `?retry={id}` owned by the user) with routes in app/Http/Controllers/ and routes/generations.php
- [x] T045 [US1] Implement (a) `DispatchWaitingGenerations` (advisory lock, slot counts, `SKIP LOCKED`, only rows whose `Generation.available_at` has passed, waited-too-long failures) in app/Actions/Generations/DispatchWaitingGenerations.php; (b) the queued jobs `DispatchWaiting` and `ExpireWaitingGeneration` (due at the waiting limit, each delay capped at 15 minutes per FR-011) in app/Jobs/DispatchWaiting.php and app/Jobs/ExpireWaitingGeneration.php; (c) a `GenerationFinished` listener that queues `DispatchWaiting` in app/Listeners/DispatchNextGenerations.php
- [x] T046 [US1] Implement `SubmitGenerationToPixelforge` happy path and definite rejections (400, 401, 409, 422, daily 429 → failed and released) in app/Jobs/SubmitGenerationToPixelforge.php
- [x] T047 [US1] Fill in `pixelforge:dispatch` (the hourly sweep's dispatch and expiry part) in app/Console/Commands/DispatchGenerations.php
- [ ] T048 [US1] Implement `PixelforgeWebhookController` (signature first, delivery recorded outside the transaction with `WebhookDelivery.payload` left null when rejected, dedupe insert and `ApplyGenerationSnapshot` in one transaction, outcomes per contracts/http.md) and its route in app/Http/Controllers/PixelforgeWebhookController.php and routes/webhooks.php
- [ ] T049 [P] [US1] Implement `useEstimate` (debounced `POST /generations/estimate`, cancels stale requests) in resources/js/composables/useEstimate.ts
- [ ] T050 [P] [US1] Implement `EstimatePanel` (range, likely, breakdown, rates age, "Failed images are free") in resources/js/components/pixelcat/EstimatePanel.vue
- [ ] T051 [US1] Implement the Create page (form, size and quality pickers, reference URLs, keyword chips, "Surprise me with a cat", Cmd/Ctrl+Enter, form-generated UUID renewed after success and on `id_reused`, handling of `prices_changed`, `insufficient_balance` and `daily_cap`) in resources/js/pages/generations/Create.vue

**Checkpoint**: A user can generate and be charged correctly (history view comes with US3).

---

## Phase 4: User Story 2 - Generation state is always right (Priority: P1)

**Goal**: Correct final state and charge whatever Pixelforge does.

**Independent Test**: `FakePixelforge` delivers duplicates, reorders, races the POST, drops all
webhooks, times out after creating, answers 429/503; assert states and charges.

### Tests for User Story 2

- [x] T052 [P] [US2] Feature tests for every create-side row of the spec's Failure Modes table (503 backoff and cap; rate 429; concurrency 429 → `waiting` with `available_at`; timeout and other 5xx → stays `submitting` with `had_ambiguous_attempt`, never failed; a crash after acceptance replays the same Pixelforge generation; job uniqueness) in tests/Feature/Generations/SubmitGenerationFailureModesTest.php
- [ ] T053 [P] [US2] Feature tests for webhook robustness (bad signature and timestamps → 400 and `rejected` with headers only; duplicate; out of order; event before the POST returns matched by `metadata.local_id`; unknown generation; `ping`; exception → 500 and `error` delivery, then redelivery applies) in tests/Feature/Webhooks/WebhookRobustnessTest.php
- [ ] T054 [P] [US2] Feature tests for `SyncGeneration` (scheduled on `GenerationAccepted`; does nothing if an update arrived or the generation is final; fetches and applies otherwise; reschedules with backoff 2, 4, 8, 15 minutes) and for `pixelforge:poll` (re-dispatches stale `submitting`, fetches `queued`/`processing` with no update for 15 minutes, at most 10, stops when the budget is under 20, `invalid_api_key`/`not_found` leave state unchanged) in tests/Feature/Generations/PollPixelforgeTest.php
- [ ] T055 [P] [US2] Multi-process test: two processes (each running a small bootstrap script in tests/Concurrency/bin/ that applies a given event) apply the same event at once; exactly one charge (SC-003) in tests/Concurrency/ExactlyOnceChargeTest.php
- [ ] T056 [P] [US2] Feature test for `pixelforge:replay-event` (re-applies a stored event through `ApplyGenerationSnapshot`; replaying a final event changes nothing; unknown event id fails with a clear message) (FR-021) in tests/Feature/Webhooks/ReplayEventTest.php

### Implementation for User Story 2

- [x] T057 [US2] Complete `SubmitGenerationToPixelforge` failure handling (`ShouldBeUnique`, `Retry-After` plus exponential backoff, 503 cap of 5, concurrency → `waiting` with a new `Generation.available_at`, ambiguous attempts set `Generation.had_ambiguous_attempt` and retry up to 10-minute backoff, never failing locally once it is set, timeline events) in app/Jobs/SubmitGenerationToPixelforge.php
- [ ] T058 [US2] Implement `SyncGeneration` (unique per generation, budget-aware, self-rescheduling, delays capped at 15 minutes) and a `GenerationAccepted` listener that schedules it in app/Jobs/SyncGeneration.php and app/Listeners/ScheduleSync.php; fill in `pixelforge:poll` (the hourly sweep, staleness by `Generation.last_synced_at`) in app/Console/Commands/PollPixelforge.php
- [ ] T059 [US2] Implement `pixelforge:replay-event` in app/Console/Commands/ReplayEvent.php
- [ ] T060 [US2] Implement the local fake Pixelforge (API routes with the real shapes, errors and keyword behaviour; a simulator job that advances generations and posts signed webhooks, including duplicates and reordering) in app/Dev/FakePixelforge/ and routes/fake-pixelforge.php

**Checkpoint**: US1 and US2 together are the correct-money core of the assessment.

---

## Phase 5: User Story 3 - History with live status (Priority: P2)

**Goal**: Users see every generation with live status, prices, image, errors and timeline.

**Independent Test**: Seed every state; assert history props, polling flag and authorization.

### Tests for User Story 3

- [ ] T061 [P] [US3] Feature test for the history page (props per contracts/pages.md, newest first, cursor pagination, `has_unfinished`, only own generations) in tests/Feature/Generations/HistoryPageTest.php
- [ ] T062 [P] [US3] Feature tests for `StoreGenerationImage` (stores once, retries, skips when stored) and `GET /generations/{id}/image` (owner only, signed redirect, Pixelforge URL fallback, 404 when expired) in tests/Feature/Generations/GenerationImageTest.php

### Implementation for User Story 3

- [ ] T063 [US3] Implement `StoreGenerationImage` and a `GenerationSucceeded` listener that dispatches it in app/Jobs/StoreGenerationImage.php and app/Listeners/StoreImageOnSuccess.php
- [ ] T064 [US3] Implement `GenerationImageController` and its route in app/Http/Controllers/GenerationImageController.php and routes/images.php
- [ ] T065 [US3] Implement `GenerationHistoryController@index` and its route in app/Http/Controllers/GenerationHistoryController.php and routes/generations.php
- [ ] T066 [P] [US3] Implement `StatusBadge`, `Timeline`, `GenerationCard` (image, prices, estimate position bar, error with "You weren't charged", "Try again" link) in resources/js/components/pixelcat/
- [ ] T067 [P] [US3] Implement `BusyNotice` and `CatMascot` (empty state, failure state; never next to prices) in resources/js/components/pixelcat/
- [ ] T068 [US3] Implement the history page with `usePoll(3000)` active only while `has_unfinished`, reloading only the props listed in contracts/pages.md, in resources/js/pages/generations/Index.vue

**Checkpoint**: The full required part of the assessment works.

---

## Phase 6: User Story 4 - Operator report and reconciliation (Priority: P3, stretch)

**Goal**: Admins see spend, provider cost and profit per UTC day and user, reconciled with
`/v1/usage`.

**Independent Test**: Seed generations across UTC days and fake `/v1/usage`; assert rows,
profit and match flags.

### Tests for User Story 4

- [ ] T069 [P] [US4] Feature tests for `pixelforge:reconcile` (completed days only; days by `Generation.completed_at`; anomalies included in our cost; match, mismatch and difference stored per data-model.md `Reconciliation`) in tests/Feature/Admin/ReconcileUsageTest.php
- [ ] T070 [P] [US4] Feature tests for the report and deliveries pages (props per contracts/pages.md, today `in_progress`, 403 for non-admins) in tests/Feature/Admin/OperatorPagesTest.php

### Implementation for User Story 4

- [ ] T071 [US4] Fill in `pixelforge:reconcile` (our cost grouped by the UTC date of `Generation.completed_at`, stores `Reconciliation.difference` per data-model.md) in app/Console/Commands/ReconcileUsage.php
- [ ] T072 [US4] Implement `Admin\ReportController` and `Admin\DeliveriesController` with routes behind the `admin` gate in app/Http/Controllers/Admin/ and routes/operators.php
- [ ] T073 [P] [US4] Implement the report page (days, per-user rows, totals, reconciliation status, anomalies) in resources/js/pages/admin/Report.vue
- [ ] T074 [P] [US4] Implement the deliveries page in resources/js/pages/admin/Deliveries.vue

---

## Phase 7: User Story 5 - Credit balances (Priority: P3, stretch)

**Goal**: Balances with a welcome credit, fake purchases, and holds that prevent overspending.

**Independent Test**: Buy, generate, succeed and fail; assert ledger entries and balance after
each step.

### Tests for User Story 5

- [ ] T075 [P] [US5] Feature tests for the welcome credit on sign-up, the credits page and `POST /credits/purchase` (packs 5, 10, 25 only) in tests/Feature/Billing/CreditsTest.php
- [ ] T076 [P] [US5] Multi-process test: two submissions (bootstrap scripts in tests/Concurrency/bin/ calling `CreateGeneration`) that together exceed the balance; only one is accepted in tests/Concurrency/ConcurrentHoldsTest.php

### Implementation for User Story 5

- [ ] T077 [US5] Grant the welcome credit (`LedgerEntry.type` `grant`, amount from `PIXELCAT_WELCOME_CREDIT`) at registration in app/Actions/Fortify/CreateNewUser.php
- [ ] T078 [US5] Implement `CreditsController` (index, purchase) with routes in app/Http/Controllers/CreditsController.php and routes/credits.php
- [ ] T079 [P] [US5] Implement the credits page (balance, packs, recent entries) in resources/js/pages/credits/Index.vue
- [ ] T080 [P] [US5] Show the shared `balance` prop with a "Buy credits" link in the app header (FR-016) in resources/js/components/AppSidebarHeader.vue

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T081 [P] Seeders: admin and demo users with passwords from `ADMIN_PASSWORD` and `DEMO_USER_PASSWORD`; fake generations in every state only outside production (FR-022) in database/seeders/
- [ ] T082 [P] Implement `pixelcat:demo-generations` (real API, keyword prompts, normal flow) in app/Console/Commands/DemoGenerations.php
- [ ] T083 [P] Write README.md: what it does, screenshots, mermaid diagrams of the state machine and the money flow, local setup, tests, demo logins "sent with the application"
- [ ] T084 [P] Write DECISIONS.md: each open question, options and choice (nanodollars, rounding up, in-request webhook apply, fake Pixelforge, polling, slot fairness, ambiguity rule, talking points on Typesense, ClickHouse, ECS and WebSockets)
- [ ] T085 Accessibility and copy pass on all pages (labels, focus states, keyboard use, sentence case, error copy per the spec)
- [ ] T086 Run every quickstart.md validation scenario locally against the fake, then against the real Pixelforge with a local key through cloudflared
- [ ] T087 Production: create the production Pixelforge key and webhook URL, set Cloud env vars, run `pixelcat:demo-generations`, and after the UTC day closes confirm `pixelforge:reconcile` shows `match` (SC-004)
- [ ] T088 Security pass: `gitleaks git` over the full history, GitHub secret scanning and push protection on, webhook route rate limit, then make the repository public

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: after Setup; blocks all stories. Tests T012–T020 run in parallel
  with implementation T021–T035 (different files). T036 needs your Laravel Cloud account.
- **US1 (Phase 3)** and **US2 (Phase 4)**: after Foundational. US2 extends US1's submit job and
  webhook controller, so their tasks on those two files are sequential (T046 → T057, T048 → T053).
- **US3 (Phase 5)**: after Foundational; its pages are usable once US1 creates generations.
- **US4, US5 (Phases 6–7)**: after Foundational; independent of US1–US3 files.
- **Polish (Phase 8)**: after the stories it touches; T087–T088 last.

### Within each story

Tests first and failing, then models and actions, then controllers and jobs, then pages.

## Delivery slices

Each slice owns its files, so slices can be built in parallel after Phase 2 and merged as
separate pull requests.

| Slice | Tasks | Owns |
|-------|-------|------|
| Core | T001–T036 | everything in Phases 1–2 |
| Submission | T037–T040, T042–T047, T052, T057 | `CreateGeneration`, dispatcher, submit job, `GenerationController`, `EstimateController`, routes/generations.php (create/store) |
| Webhooks and sync | T041, T048, T053–T056, T058–T064 | webhook controller, poll and replay commands, concurrency test and its bootstrap scripts, fake API, image job and route, routes/webhooks.php, routes/images.php, routes/fake-pixelforge.php |
| Frontend | T049–T051, T061, T065–T068 | Vue pages for generations, pixelcat components, `GenerationHistoryController`, history route |
| Operators and credits | T069–T082 | reconcile, admin and credits controllers and pages, header balance, routes/operators.php, routes/credits.php, `CreateNewUser`, seeders, demo command |

Shared file note: routes/generations.php is touched by Submission (T044) and Frontend (T065);
Frontend adds one route line after Submission merges.

## Implementation Strategy

1. Phases 1–2, deploy the skeleton (T036).
2. US1 + US2 (correct money and robustness): the assessment's core; deploy.
3. US3: completes the required part; deploy and run quickstart scenarios 4–9.
4. US4 + US5 (stretch), then Polish.
5. If time runs short, cut in this order: T063–T064 image copies, the estimate position bar in
   T066, per-user rows in T073, T067 `BusyNotice`. Never cut US1, US2 or their tests.
