# Quickstart: run and validate

## Prerequisites

PHP 8.3+, Composer, Node 22+, Postgres 16+ (two databases: `pixelforge`, `pixelforge_test`).

## Set up

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# .env: DB_* for the local database, DEMO_USER_PASSWORD, ADMIN_PASSWORD,
#       PIXELFORGE_FAKE=true, PIXELFORGE_BASE_URL=http://127.0.0.1:8001/_fake/pixelforge/v1
php artisan migrate --seed
composer run dev                 # web server, queue worker, scheduler, Vite
php artisan serve --port=8001    # the fake Pixelforge, in a second terminal
```

With `PIXELFORGE_FAKE=true` the app talks to the built-in fake Pixelforge and needs no account.
The fake runs on a second server because `php artisan serve` handles one request at a time.

## Automated checks

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test          # runs against pixelforge_test (Postgres)
npm run build
```

## Validation scenarios

| # | Scenario | How | Expected | Spec |
|---|----------|-----|----------|------|
| 1 | Worked example | `php artisan test --filter=WorkedExample` | provider `0.373875000`, user `0.467343750` | SC-001 |
| 2 | Estimate always contains the actual | `--filter=EstimateRange` | 1,000 random cases pass | SC-002 |
| 3 | Duplicate and concurrent events | `--filter=WebhookIdempotency` | one charge per success | SC-003 |
| 4 | Happy path | sign in as demo user, generate "a cat [succeed]" | waiting → queued → processing → succeeded within ~30 s; charged inside the estimate | US1 |
| 5 | Failure is free | generate "a cat [fail]" | failed, "You weren't charged", balance unchanged | SC-005 |
| 6 | Duplicate webhooks | generate "a cat [duplicate]" | timeline shows "Delivered again, ignored"; one charge | US2 |
| 7 | Slot waiting | submit 4 `[slow]` prompts from two users | at most 3 active, at most 2 per user, the rest "Waiting for a free slot" | US2 |
| 8 | Overload | generate "a cat [overloaded]" | retried with backoff, then failed and uncharged | FR-008 |
| 9 | Authorization | open another user's image URL; open `/admin/report` as a non-admin | 404; 403 | SC-008 |
| 10 | Reconciliation (real API) | `php artisan pixelforge:reconcile` the day after real generations | every completed day `match` | SC-004 |

## Against the real Pixelforge (local)

1. Create a "local" API key in the Pixelforge dashboard.
2. `cloudflared tunnel --url http://localhost:8000` and set the key's webhook URL to
   `https://<tunnel>/webhooks/pixelforge`.
3. In `.env`: `PIXELFORGE_FAKE=false`, `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`.
4. Repeat scenarios 4–8; use the dashboard's "Send test event" and "Resend" to check `ping` and
   duplicates.

## Production

Laravel Cloud: App cluster (Flex 512 MB, scheduler on, scale to zero on), managed queue,
Serverless Postgres, Object Storage bucket attached as the `images` disk. Push-to-deploy off;
CI calls the deploy hook. Deploy command: `php artisan migrate --force`. Separate "production"
Pixelforge key with webhook URL `https://<app>.laravel.cloud/webhooks/pixelforge`.
