# Pixelcat Constitution

Pixelcat lets signed-in users generate images through the Pixelforge API and charges them
for each generation. Most of these rules exist because the app handles other people's money.

## Core Principles

### I. Money is exact (NON-NEGOTIABLE)

- All amounts are stored as integer nanodollars (`bigint`, 1 USD = 1,000,000,000).
- Money arithmetic uses arbitrary-precision decimals (`brick/math`). Floats MUST NOT touch an
  amount anywhere: PHP, SQL, JSON or Vue.
- Pixelforge amounts are parsed from their decimal strings, never through `float`.
- A provider cost is Σ(tokens × rate) using the `rates` stored on the generation, never the
  current price list. It MUST be exact to the nanodollar; a result that needs more precision
  is an error, not something to round.
- The user price is provider cost × 1.25, rounded **up** to the next nanodollar, once per
  generation. Rounding happens in exactly one function.
- Displayed amounts may be rounded for reading; the exact value is always available.

Rationale: Pixelforge reports costs to 9 decimals and the operator reconciles against them.
Any drift is lost money or an overcharged user.

### II. Charge exactly once; failure is free (NON-NEGOTIABLE)

- A generation is charged only when it reaches `succeeded`, and at most once. This is enforced
  by a database unique constraint, not only by application checks.
- A failed generation, a rejected request and anything that never reached Pixelforge costs
  the user nothing; any hold is released in full.
- Money changes happen in one database transaction with the status change that causes them,
  under a row lock on the generation.
- The ledger is append-only. Entries are never updated or deleted; corrections are new
  entries. A balance is the sum of its entries.

### III. The provider is unreliable by contract

- Every create request carries an `Idempotency-Key` derived from our own generation id, so any
  retry, crash or double submit maps to one Pixelforge generation.
- `Retry-After` is respected; retries back off and are capped. No unbounded loops.
- Webhooks are verified (HMAC over the raw body, constant-time compare, timestamp window)
  before anything else happens, deduplicated by event id, and answered quickly; slow work is
  queued.
- Status only moves forward: `waiting → submitting → queued → processing → succeeded | failed`.
  Final states never change. Out-of-order and duplicate events are recorded and ignored.
- Webhooks, polling and manual replay all go through one function that applies a generation
  snapshot. There is no second path that changes status or money.
- Account limits (requests per minute, concurrent generations, daily quota) are shared by all
  users and are managed by the app, fairly, instead of being surfaced as raw errors.

### IV. Tests prove the money paths

- Money and webhook behaviour is specified by tests before or with the code: cost formulas
  (including the documented worked example, exactly `0.373875000`), rounding, duplicates,
  out-of-order events, retries, crash recovery and concurrency.
- Tests that depend on database guarantees (unique constraints, locks) run on Postgres, the
  production database.
- Pixelforge is replaced in tests and local development by a fake that follows the published
  API docs, including its failure behaviour. No test calls the real API.
- Provider costs MUST match `GET /v1/usage` for every UTC day; the app checks this itself.

### V. The user always knows

- Before submitting, the user sees an estimated price range and why it is a range.
- At every moment the user can see each generation's status, its estimate, its actual price
  once known, and in plain words what went wrong and whether they were charged.
- Errors are mapped to clear messages; raw provider errors are not shown to users.

### VI. Plain, conventional Laravel

- Follow Laravel and Vue conventions; prefer framework features over custom infrastructure.
- Small classes with one job, named for what they do. No speculative abstractions,
  interfaces with one implementation, or configuration nobody asked for.
- Code is written so someone new to it can read it and extend it the same day: obvious over
  clever, comments only for the non-obvious why.

### VII. Secrets stay secret

- API keys, webhook secrets and deploy hooks live in environment variables and CI secrets,
  never in the repository. A secret scan runs before every commit and before publishing.
- Users can only see their own generations; operator pages require the admin role.

## Technology and Deployment

- Laravel 13, Vue 3, Inertia 3, Tailwind CSS, Postgres, Pest, Larastan, Pint.
- Hosted on Laravel Cloud with a web service, a queue worker and the scheduler.
- One Pixelforge API key per environment, each with its own webhook URL and signing secret.
- Live status uses polling while anything is unfinished; no WebSockets.

## Development Workflow and Quality Gates

- Work happens on branches and lands through pull requests.
- Every pull request passes CI (Pint, Larastan, Pest on Postgres, frontend build) and gets a
  code review before merge.
- `main` deploys automatically, only after CI is green, and a smoke test runs after each
  deploy.
- Decisions that the requirements leave open are recorded in `DECISIONS.md` with the options
  considered and the reason for the choice.

## Governance

- This constitution overrides other practices in this repository. Specs, plans and tasks MUST
  comply with it; reviews check compliance.
- Amendments are made through a pull request that explains the change and bumps the version
  (MAJOR: a principle removed or redefined; MINOR: a principle or section added; PATCH:
  wording).

**Version**: 1.0.0 | **Ratified**: 2026-11-17 | **Last Amended**: 2026-11-17
