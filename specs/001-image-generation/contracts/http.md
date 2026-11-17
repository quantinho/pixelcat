# HTTP contract

All routes except the webhook require an authenticated session (Fortify; no email
verification, see the spec's Assumptions). Routes are named and exposed to Vue through
Wayfinder.

| Route file | Middleware | Contents |
|------------|------------|----------|
| routes/generations.php | `web`, `auth` | generate, estimate, history |
| routes/images.php | `web`, `auth` | generation image |
| routes/credits.php | `web`, `auth` | credits page and purchase |
| routes/operators.php | `web`, `auth`, `can:admin` | admin report and deliveries |
| routes/webhooks.php | none (no session, no CSRF) | Pixelforge webhook |
| routes/fake-pixelforge.php | none; only when `PIXELFORGE_FAKE=true` outside production | local fake API |

## Pages (Inertia)

| Method | Path | Name | Page | Auth |
|--------|------|------|------|------|
| GET | `/generate` | `generations.create` | `generations/Create` | user |
| GET | `/generations` | `generations.index` | `generations/Index` | user |
| GET | `/credits` | `credits.index` | `credits/Index` | user |
| GET | `/admin/report` | `admin.report` | `admin/Report` | admin |
| GET | `/admin/deliveries` | `admin.deliveries` | `admin/Deliveries` | admin |

`/dashboard` from the starter kit redirects to `/generate`. Props are defined in
[pages.md](pages.md).

## Actions

### `POST /generations/estimate` → JSON

Request (same validation as submit, prompt may be empty while typing):

```json
{ "prompt": "string", "size": "1024x1024", "quality": "medium", "reference_images": ["https://…"] }
```

Response `200`:

```json
{
  "min": { "display": "$0.0582", "exact": "0.058200000" },
  "likely": { "display": "$0.0723", "exact": "0.072275000" },
  "max": { "display": "$0.0864", "exact": "0.086356250" },
  "breakdown": [
    { "type": "output_tokens", "tokens": "1,100", "cost": "$0.0440" },
    { "type": "reasoning_tokens", "tokens": "200–2,000", "cost": "$0.0025–$0.0250" }
  ],
  "pricing_version": "sha256 of the rates",
  "rates_fetched_at": "2026-11-17T14:03:11Z",
  "affordable": true,
  "balance": { "display": "$1.9418", "exact": "1.941800000" }
}
```

Amounts use the `Money` shape from [pages.md](pages.md) (display rounded half-up to 4
decimals, exact to 9); Vue never does arithmetic on them. `422` on invalid input.

### `POST /generations` → redirect

Request: estimate fields plus `id` (uuid from the form) and `pricing_version`.

| Case | Response |
|------|----------|
| Accepted | `303` → `/generations`, flash "Generating…" |
| Same `id`, same params (resubmit) | `303` → `/generations`, nothing new created |
| Same `id`, different params | `409` with error `id_reused`; the form makes a new id |
| `pricing_version` differs from current | `409` with error `prices_changed` and the new estimate |
| Balance below estimate max | `422` with error `insufficient_balance` and the missing amount |
| Daily cap reached | `429` with error `daily_cap` and the reset time (UTC midnight) |
| Validation | `422` field errors |

### `GET /generations/{generation}/image` (`generations.image`)

Owner only (`404` otherwise). Redirects to a 5-minute signed URL of the stored copy, or to the
Pixelforge URL while it is still valid, else `404` (the page shows "Image expired").

### `POST /credits/purchase` → redirect

Request `{ "pack": 5 | 10 | 25 }`. Appends a `purchase` ledger entry. `303` → back, flash
"Added $10.00 of credits".

## Webhook

### `POST /webhooks/pixelforge`

Outside the `web` middleware group (no session, no CSRF). Rate-limited by IP (generous).

| Step | Result |
|------|--------|
| Signature header missing or invalid, or `|now - t| > 300 s` | `400`, delivery `rejected` (headers only) |
| `ping` | `204`, delivery `ping` |
| Event id already in `webhook_events` | `204`, delivery `duplicate` |
| No generation matches `metadata.local_id` or `pixelforge_id` | `204`, delivery `unknown_generation` |
| Snapshot rank ≤ current | `204`, delivery `out_of_order`, missing data filled |
| Applied | `204`, delivery `applied` |
| Exception during the transaction | `500`, delivery `error`; Pixelforge retries |

Signature: `hash_hmac('sha256', "{t}.{raw_body}", PIXELFORGE_WEBHOOK_SECRET)`, compared with
`hash_equals`.

## Local fake (only when `PIXELFORGE_FAKE=true`, never in production)

`/_fake/pixelforge/v1/generations` (POST, GET), `/_fake/pixelforge/v1/generations/{id}`,
`/_fake/pixelforge/v1/usage`, `/_fake/pixelforge/v1/pricing`: same shapes and errors as the
real API, including keyword behaviour.
