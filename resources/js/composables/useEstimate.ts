import { onScopeDispose, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type { Estimate, Quality, Size } from '@/types';

export type EstimateInput = {
    prompt: string;
    size: Size;
    quality: Quality;
    reference_images: string[];
};

/**
 * Why there is no estimate: Pixelforge can't be reached (503), the user is asking too often
 * (429), or anything else went wrong.
 */
export type EstimateProblem = {
    kind: 'unavailable' | 'throttled' | 'error';
    message: string | null;
};

export type UseEstimateReturn = {
    estimate: Ref<Estimate | null>;
    /** True from the first keystroke until the matching estimate has arrived. */
    pending: Ref<boolean>;
    /** Field errors from a 422, keyed like the request ("prompt", "reference_images.0"). */
    errors: Ref<Record<string, string>>;
    problem: Ref<EstimateProblem | null>;
    refresh: () => void;
};

const ESTIMATE_URL = '/generations/estimate';

/** When throttled without a Retry-After header, try again after this many seconds. */
const THROTTLE_RETRY_SECONDS = 10;

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
    const problem = ref<EstimateProblem | null>(null);

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

            if (response.ok) {
                estimate.value = (await response.json()) as Estimate;
                errors.value = {};
                problem.value = null;
            } else if (response.status === 422) {
                const body = (await response.json()) as {
                    errors?: Record<string, string[]>;
                };
                estimate.value = null;
                errors.value = firstMessages(body.errors ?? {});
                problem.value = null;
            } else {
                // An old estimate would show a price for other settings or old rates.
                estimate.value = null;
                problem.value = await problemFrom(response);

                if (response.status === 429) {
                    retryLater(response);
                }
            }
        } catch (error) {
            if (current.signal.aborted) {
                return;
            }

            estimate.value = null;
            problem.value = { kind: 'error', message: null };
            console.error(error);
        }

        if (controller === current) {
            pending.value = false;
            controller = null;
        }
    }

    function retryLater(response: Response): void {
        const seconds = Number(response.headers.get('Retry-After'));
        const wait =
            Number.isFinite(seconds) && seconds > 0
                ? Math.min(seconds, 60)
                : THROTTLE_RETRY_SECONDS;

        clearTimeout(timer);
        timer = setTimeout(() => void load(), wait * 1000);
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

    watch(input, schedule, { deep: true });

    if (typeof window !== 'undefined') {
        refresh();
    }

    onScopeDispose(() => {
        clearTimeout(timer);
        controller?.abort();
    });

    return { estimate, pending, errors, problem, refresh };
}

async function problemFrom(response: Response): Promise<EstimateProblem> {
    const kind =
        response.status === 503
            ? 'unavailable'
            : response.status === 429
              ? 'throttled'
              : 'error';

    try {
        const body = (await response.json()) as { message?: unknown };

        return {
            kind,
            message: typeof body.message === 'string' ? body.message : null,
        };
    } catch {
        return { kind, message: null };
    }
}

function firstMessages(
    errors: Record<string, string[]>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors).map(([field, messages]) => [field, messages[0]]),
    );
}
