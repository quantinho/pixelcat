# Pixelcat

Users generate images through the Pixelforge API and pay Pixelforge's cost plus 25%.
Laravel 13, Inertia 3, Vue 3 + TypeScript, Tailwind 4, Postgres, Pest.

Read these before changing behaviour:

- `.specify/memory/constitution.md`: the non-negotiable rules (money, idempotency, tests).
- `specs/001-image-generation/`: spec, plan, data model, contracts and tasks.
- `DECISIONS.md`: why things are the way they are.

## Commands

```bash
composer run dev                 # app, queue worker, scheduler, Vite
php artisan test                 # Pest, against the Postgres database pixelforge_test
vendor/bin/pint                  # format PHP
vendor/bin/phpstan analyse --memory-limit=1G   # static analysis (level 8)
npm run check && npm run types:check
```

Set `PIXELFORGE_FAKE=true` in `.env` to run against the built-in fake Pixelforge.

## Rules that matter most

- **Money** is integer nanodollars (`bigint`, 1 USD = 1,000,000,000). Use `App\Billing\Money`
  and `brick/math`; never floats. User price rounding happens only in `CalculateCost`.
- **One writer**: after submission, only `ApplyGenerationSnapshot` changes a generation's status
  or money, under a row lock. Webhooks, polling and replay all call it.
- **Ledger** is append-only. Never update or delete `ledger_entries`.
- **Pixelforge is unreliable**: every create uses the generation UUID as `Idempotency-Key`; a
  generation with an ambiguous attempt is never failed locally.
- **Tests first** for money and webhook behaviour. Tests must not call the real API
  (`Http::preventStrayRequests()` is on).
- Frontend never does arithmetic on money: the backend sends `{display, exact}` strings.

## Where things live

| Area | Path |
|------|------|
| Pricing and cost | `app/Actions/Pricing/`, `app/Billing/` |
| Generation lifecycle | `app/Actions/Generations/`, `app/Jobs/` |
| Pixelforge client, webhook signature | `app/Pixelforge/` |
| Local fake Pixelforge | `app/Dev/FakePixelforge/` |
| Scheduled commands | `app/Console/Commands/`, `routes/console.php` |
| Pages and components | `resources/js/pages/`, `resources/js/components/pixelcat/` |
| Prop types | `resources/js/types/pixelcat.ts` |

## Workflow

Branch per slice, pull request, CI green, code review, merge. `main` deploys automatically
after CI passes.
