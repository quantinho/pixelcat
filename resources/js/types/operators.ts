// Props for the operator and credits pages (specs/001-image-generation/contracts/pages.md).
// Money is always pre-formatted by the backend; the frontend never does arithmetic on it.

import type { CursorPage, Money } from './pixelcat';

export type ReconciliationStatus =
    | 'match'
    | 'mismatch'
    | 'in_progress'
    | 'not_checked';

export type ReportUserRow = {
    user: string;
    charged: Money;
    provider_cost: Money;
    profit: Money;
    generations: number;
};

export type ReportDay = {
    date: string; // UTC, "2026-11-16"
    charged: Money;
    provider_cost: Money;
    profit: Money;
    generations: number;
    reconciliation: {
        status: ReconciliationStatus;
        pixelforge_cost: Money | null;
        difference: Money | null;
        checked_at: string | null;
    };
    users: ReportUserRow[];
};

export type ReportAnomaly = {
    generation_id: string;
    user: string;
    provider_cost: Money;
    at: string;
};

export type ReportPageProps = {
    days: ReportDay[];
    totals: { charged: Money; provider_cost: Money; profit: Money };
    anomalies: ReportAnomaly[];
};

export type WebhookDeliveryRow = {
    event_id: string | null;
    event_type: string | null;
    attempt: number | null;
    outcome: string;
    generation_id: string | null;
    received_at: string;
};

export type DeliveriesPageProps = {
    deliveries: CursorPage<WebhookDeliveryRow>;
};

export type CreditPack = {
    amount: 5 | 10 | 25;
    label: string; // "$10.00"
};

export type CreditEntry = {
    type: string;
    description: string;
    amount: Money;
    at: string | null;
};

export type CreditsPageProps = {
    packs: CreditPack[];
    entries: CreditEntry[];
};
