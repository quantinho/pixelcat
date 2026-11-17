# Implementation Plan: Paid image generation through Pixelforge

**Branch**: `main` (bootstrap), then one branch per slice | **Date**: 2026-11-17 |
**Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-image-generation/spec.md`

## Summary

A Laravel 13 + Inertia/Vue app where users generate images through Pixelforge and pay
provider cost + 25%. The design centres on three rules: money is integer nanodollars computed
in one place; every change of status or money goes through one `ApplyGenerationSnapshot`
operation under a row lock and database constraints; and Pixelforge is treated as unreliable
(idempotency keys from our own ids, capped retries, verified and deduplicated webhooks
applied in-transaction, polling as a fallback, fair local slot dispatching). Operators get a
report reconciled against `GET /v1/usage`, and users get credit balances with holds.
Details: [research.md](research.md), [data-model.md](data-model.md),
[contracts/](contracts/), [quickstart.md](quickstart.md).

## Technical Context

**Language/Version**: PHP 8.3+ (8.5 locally), TypeScript 5, Vue 3.5

**Primary Dependencies**: Laravel 13 (Vue starter kit), Inertia 3, Fortify, Wayfinder, Tailwind
4, reka-ui, `brick/math`, `aws/aws-sdk-php` (managed queue), `league/flysystem-aws-s3-v3`
(object storage)

**Storage**: Postgres (local, CI, Laravel Serverless Postgres); Laravel Object Storage for images

**Testing**: Pest 5 on Postgres, Larastan, Pint; `Http::fake` with a docs-based fake
Pixelforge; multi-process tests for concurrency

**Target Platform**: Laravel Cloud (Flex app cluster with scheduler, managed queue)

**Project Type**: Web application (Laravel monolith with Inertia SPA pages)

**Performance Goals**: Webhook answered in < 1 s (budget 10 s); status visible within 5 s;
estimate shown within 300 ms of typing pause

**Constraints**: Pixelforge account limits shared across environments: 60 requests/min,
3 concurrent, 500/day; webhooks at least once, any order; no floats on money

**Scale/Scope**: Demo scale (tens of users, hundreds of generations); 5 pages, ~6 tables

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | How the design complies | Status |
|-----------|-------------------------|--------|
| I. Money is exact | `bigint` nanodollars; `brick/math` for parsing and multiplication; ceil rounding only in `CalculateCost`; estimates computed server-side, Vue gets strings (R2, R3) | Pass |
| II. Charge exactly once | Partial unique index on `ledger_entries (generation_id, type)`; settle/release inside `ApplyGenerationSnapshot` under a row lock; append-only ledger (data-model) | Pass |
| III. Unreliable provider | UUID idempotency keys; retries per Failure Modes; ambiguous attempts never failed locally; signature-first webhook, dedupe + apply in one transaction; one apply path; fair dispatcher (R7–R10) | Pass |
| IV. Tests prove money paths | Pest on Postgres; fake Pixelforge; worked example; randomized estimate test; multi-process concurrency test; reconciliation test (R12, R13) | Pass |
| V. User always knows | Estimate range with breakdown; status labels; timeline; mapped error messages; busy notice (contracts/pages) | Pass |
| VI. Plain Laravel | Actions, jobs, commands, API resources, enums; no repositories or interfaces with one implementation | Pass |
| VII. Secrets | Env-only keys; gitleaks pre-commit; owner-only image route; admin gate | Pass |

Post-design re-check: Pass, with the two justified items under Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/001-image-generation/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── http.md
│   ├── pages.md
│   └── commands-and-jobs.md
├── checklists/requirements.md
└── tasks.md            # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Actions/Generations/     # CreateGeneration, DispatchWaitingGenerations, ApplyGenerationSnapshot
├── Actions/Pricing/         # EstimatePrice, CalculateCost
├── Billing/                 # Ledger, Money (nanodollar helpers)
├── Pixelforge/              # PixelforgeClient, Pricing, GenerationSnapshot, WebhookSignature, exceptions
├── Dev/FakePixelforge/      # local-only fake API + webhook simulator
├── Enums/                   # GenerationStatus, Size, Quality, LedgerEntryType, DeliveryOutcome
├── Events/                  # GenerationSucceeded, GenerationFinished
├── Listeners/               # DispatchNextGenerations, StoreImageOnSuccess
├── Jobs/                    # SubmitGenerationToPixelforge, StoreGenerationImage
├── Console/Commands/        # Dispatch, Poll, Reconcile, ReplayEvent, DemoGenerations
├── Http/Controllers/        # Generation, Estimate, GenerationImage, Credits, PixelforgeWebhook, Admin/*
├── Http/Requests/           # GenerationRequest
├── Http/Resources/          # GenerationResource, MoneyResource
routes/                      # generations, images, credits, operators, webhooks, fake-pixelforge (see contracts/http.md)
└── Models/                  # Generation, GenerationEvent, WebhookDelivery, WebhookEvent, LedgerEntry, Reconciliation
database/
├── migrations/
└── seeders/                 # DemoSeeder (non-production fake data), AdminSeeder
resources/js/
├── pages/generations/       # Create.vue, Index.vue
├── pages/credits/Index.vue
├── pages/admin/             # Report.vue, Deliveries.vue
├── components/pixelcat/     # EstimatePanel, StatusBadge, GenerationCard, Timeline, MoneyText, BusyNotice, CatMascot
├── composables/             # useEstimate
└── types/pixelcat.ts
tests/
├── Support/FakePixelforge.php
├── Unit/Pricing/            # CalculateCost, EstimatePrice, Money
├── Feature/Generations/     # create, dispatch, submit job, poll, history, image
├── Feature/Webhooks/        # signature, dedupe, ordering, apply
├── Feature/Billing/         # ledger, credits
├── Feature/Admin/           # report, reconcile, deliveries
└── Concurrency/             # multi-process exactly-once
.github/workflows/ci.yml     # checks, then deploy hook + smoke test on main
```

**Structure Decision**: A single Laravel monolith following the starter kit's layout.
Domain logic sits in small action classes grouped by area; controllers stay thin.

## Delivery slices

The work splits into a shared core and four slices that touch separate files, so each slice
can be built and reviewed as its own pull request:

1. **Core** (first, on its own): scaffold, all migrations and models, enums, `Money`,
   `CalculateCost`, `EstimatePrice`, `Ledger`, `ApplyGenerationSnapshot`, `PixelforgeClient`,
   both fakes, TypeScript prop types, CI and deploy pipeline.
2. **Submission**: `CreateGeneration`, `DispatchWaitingGenerations`,
   `SubmitGenerationToPixelforge`, `pixelforge:dispatch`, generation and estimate controllers.
3. **Webhooks and sync**: webhook controller, `pixelforge:poll`, `pixelforge:replay-event`,
   `StoreGenerationImage`, image route, timeline events.
4. **Frontend**: Create and Index pages, components, polling, cats.
5. **Operators and credits**: admin report and deliveries, `pixelforge:reconcile`, credits
   page and purchase, demo generations command.

## Complexity Tracking

| Item | Why needed | Simpler alternative rejected because |
|------|------------|-------------------------------------|
| Local fake Pixelforge in `app/Dev` | Run the whole app and every failure mode without an account; matches the docs | `Http::fake` alone can't drive a running app with webhooks |
| Multi-process concurrency tests | "Exactly once" depends on locks and constraints across connections | Single-process tests can't interleave transactions |
