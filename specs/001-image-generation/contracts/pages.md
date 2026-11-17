# Inertia page props

These TypeScript types are the contract between backend and frontend. They live in
`resources/js/types/pixelcat.ts`; Laravel builds them with API Resources. Money is always a
pre-formatted string; the exact value comes alongside for tooltips.

```ts
type Money = { display: string; exact: string } // "$0.3884", "0.388350000"

type GenerationStatus =
  | 'waiting' | 'submitting' | 'queued' | 'processing' | 'succeeded' | 'failed'

type GenerationEvent = {
  type: string
  message: string // "Delivered again, ignored"
  at: string // ISO 8601
}

type Generation = {
  id: string
  prompt: string
  size: '1024x1024' | '1536x1024' | '1024x1536'
  quality: 'low' | 'medium' | 'high'
  reference_images: string[]
  status: GenerationStatus
  status_label: string // "Waiting for a free slot"
  is_final: boolean
  estimate: { min: Money; likely: Money; max: Money }
  charged: Money | null
  estimate_position: number | null // 0..1, where the actual price landed in the range
  image_src: string | null // route to /generations/{id}/image, null if none
  image_expired: boolean
  error: { code: string; message: string } | null // message is user-facing
  events: GenerationEvent[]
  created_at: string
  completed_at: string | null
}

type SharedProps = {
  auth: { user: { id: number; name: string; email: string; is_admin: boolean } }
  balance: Money | null
  pixelforge_busy: boolean
  flash: { success?: string; error?: string }
}
```

## `generations/Create`

```ts
{
  defaults: { size: '1024x1024'; quality: 'medium' }
  prefill: { prompt: string; size: string; quality: string; reference_images: string[] } | null // "Try again"
  keywords: string[] // ['[succeed]', '[fail]', '[slow]', '[duplicate]', '[overloaded]', '[blocked]']
  cat_prompts: string[] // "Surprise me with a cat"
  daily: { used: number; cap: number; resets_at: string }
}
```

## `generations/Index`

```ts
{
  generations: { data: Generation[]; next_cursor: string | null } // newest first, 20 per page
  has_unfinished: boolean // drives usePoll(3000)
}
```

Polling reloads only `generations`, `has_unfinished`, `balance` and `pixelforge_busy`.

## `credits/Index`

```ts
{
  packs: { amount: 5 | 10 | 25; label: string }[]
  entries: { type: string; description: string; amount: Money; at: string }[] // latest 50
}
```

## `admin/Report`

```ts
{
  days: {
    date: string
    charged: Money; provider_cost: Money; profit: Money
    generations: number
    reconciliation: {
      status: 'match' | 'mismatch' | 'in_progress' | 'not_checked'
      pixelforge_cost: Money | null; difference: Money | null; checked_at: string | null
    }
    users: { user: string; charged: Money; provider_cost: Money; profit: Money; generations: number }[]
  }[]
  totals: { charged: Money; provider_cost: Money; profit: Money }
  anomalies: { generation_id: string; user: string; provider_cost: Money; at: string }[]
}
```

## `admin/Deliveries`

```ts
{
  deliveries: {
    data: {
      event_id: string | null; event_type: string | null; attempt: number | null
      outcome: string; generation_id: string | null; received_at: string
    }[]
    next_cursor: string | null
  }
}
```
