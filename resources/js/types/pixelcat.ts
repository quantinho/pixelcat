// Contract between Laravel and the Vue pages (specs/001-image-generation/contracts/pages.md).
// Money is always pre-formatted by the backend; the frontend never does arithmetic on it.

export type Money = {
    display: string; // "$0.3884"
    exact: string; // "0.388350000"
};

export type GenerationStatus =
    | 'waiting'
    | 'submitting'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Size = '1024x1024' | '1536x1024' | '1024x1536';

export type Quality = 'low' | 'medium' | 'high';

export type GenerationEvent = {
    type: string;
    message: string;
    at: string | null;
};

export type Generation = {
    id: string;
    prompt: string;
    size: Size;
    quality: Quality;
    reference_images: string[];
    status: GenerationStatus;
    status_label: string;
    is_final: boolean;
    estimate: { min: Money; likely: Money; max: Money };
    charged: Money | null;
    estimate_position: number | null; // 0..1
    image_src: string | null;
    image_expired: boolean;
    error: { code: string; message: string } | null;
    events: GenerationEvent[];
    created_at: string | null;
    completed_at: string | null;
};

export type EstimateBreakdownLine = {
    type: string;
    tokens: string;
    cost: string;
};

export type Estimate = {
    min: Money;
    likely: Money;
    max: Money;
    breakdown: EstimateBreakdownLine[];
    pricing_version: string;
    rates_fetched_at: string | null;
    affordable: boolean;
    balance: Money | null;
};

export type CursorPage<T> = {
    data: T[];
    next_cursor: string | null;
};
