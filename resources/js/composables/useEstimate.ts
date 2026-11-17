import { onScopeDispose, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type { Estimate, Quality, Size } from '@/types';

export type EstimateInput = {
    prompt: string;
    size: Size;
    quality: Quality;
    reference_images: string[];
};

export type UseEstimateReturn = {
    estimate: Ref<Estimate | null>;
    /** True from the first keystroke until the matching estimate has arrived. */
    pending: Ref<boolean>;
    /** Field errors from a 422, keyed like the request ("prompt", "reference_images.0"). */
    errors: Ref<Record<string, string>>;
    /** Set when the estimate could not be loaded at all. */
    failed: Ref<boolean>;
    refresh: () => void;
    replace: (estimate: Estimate) => void;
};

const ESTIMATE_URL = '/generations/estimate';

/** Laravel sets XSRF-TOKEN as a readable cookie and accepts it back as X-XSRF-TOKEN. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Live price estimate for the generate form: debounced, and a newer request always
 * cancels the one in flight so a slow, stale answer can never overwrite a fresh one.
 */
export function useEstimate(
    input: () => EstimateInput,
    delay = 300,
): UseEstimateReturn {
    const estimate = ref<Estimate | null>(null);
    const pending = ref(true);
    const errors = ref<Record<string, string>>({});
    const failed = ref(false);

    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | null = null;

    async function load(): Promise<void> {
        controller?.abort();
        const current = new AbortController();
        controller = current;

        try {
            const response = await fetch(ESTIMATE_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify(input()),
                signal: current.signal,
            });

            if (response.status === 422) {
                const body = (await response.json()) as {
                    errors?: Record<string, string[]>;
                };
                errors.value = firstMessages(body.errors ?? {});
                estimate.value = null;
                failed.value = false;
            } else if (response.ok) {
                estimate.value = (await response.json()) as Estimate;
                errors.value = {};
                failed.value = false;
            } else {
                failed.value = true;
            }
        } catch (error) {
            if (current.signal.aborted) {
                return;
            }

            failed.value = true;
            console.error(error);
        }

        if (controller === current) {
            pending.value = false;
            controller = null;
        }
    }

    function schedule(): void {
        pending.value = true;
        clearTimeout(timer);
        timer = setTimeout(() => void load(), delay);
    }

    function refresh(): void {
        pending.value = true;
        clearTimeout(timer);
        void load();
    }

    /** Use an estimate the server sent with another response (prices changed). */
    function replace(next: Estimate): void {
        clearTimeout(timer);
        controller?.abort();
        controller = null;
        estimate.value = next;
        errors.value = {};
        failed.value = false;
        pending.value = false;
    }

    watch(input, schedule, { deep: true });

    if (typeof window !== 'undefined') {
        refresh();
    }

    onScopeDispose(() => {
        clearTimeout(timer);
        controller?.abort();
    });

    return { estimate, pending, errors, failed, refresh, replace };
}

function firstMessages(
    errors: Record<string, string[]>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors).map(([field, messages]) => [field, messages[0]]),
    );
}
