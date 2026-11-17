<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import type { HttpExceptionResponse } from '@inertiajs/core';
import { CircleAlert, ImagePlus, Sparkles, X } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import BusyNotice from '@/components/pixelcat/BusyNotice.vue';
import CatMascot from '@/components/pixelcat/CatMascot.vue';
import EstimatePanel from '@/components/pixelcat/EstimatePanel.vue';
import MoneyText from '@/components/pixelcat/MoneyText.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useEstimate } from '@/composables/useEstimate';
import type { Estimate, Money, Quality, Size } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: '/generate' }],
    },
});

const props = defineProps<{
    defaults: { size: Size; quality: Quality };
    prefill: {
        prompt: string;
        size: string;
        quality: string;
        reference_images: string[];
    } | null;
    keywords: string[];
    cat_prompts: string[];
    daily: { used: number; cap: number; resets_at: string };
}>();

const MAX_PROMPT = 4000;
const MAX_REFERENCES = 4;
// Bound rather than static: vue-tsc mis-parses "//" in a static attribute on a component.
const REFERENCE_PLACEHOLDER = 'https://example.com/cat.png';

const SIZES: { value: Size; label: string; hint: string }[] = [
    { value: '1024x1024', label: 'Square', hint: '1024×1024' },
    { value: '1536x1024', label: 'Landscape', hint: '1536×1024' },
    { value: '1024x1536', label: 'Portrait', hint: '1024×1536' },
];

const QUALITIES: { value: Quality; label: string; hint: string }[] = [
    { value: 'low', label: 'Low', hint: 'Fastest, cheapest' },
    { value: 'medium', label: 'Medium', hint: 'Good balance' },
    { value: 'high', label: 'High', hint: 'Most detail' },
];

function asSize(value: string | undefined): Size {
    return (
        SIZES.find((size) => size.value === value)?.value ?? props.defaults.size
    );
}

function asQuality(value: string | undefined): Quality {
    return (
        QUALITIES.find((quality) => quality.value === value)?.value ??
        props.defaults.quality
    );
}

// The id is the generation's id and Pixelforge Idempotency-Key (FR-004): a double click or a
// browser resubmit sends the same id, so the server creates one generation.
const form = useForm({
    id: crypto.randomUUID(),
    prompt: props.prefill?.prompt ?? '',
    size: asSize(props.prefill?.size),
    quality: asQuality(props.prefill?.quality),
    reference_images: [...(props.prefill?.reference_images ?? [])].slice(
        0,
        MAX_REFERENCES,
    ),
    pricing_version: '',
});

function isHttpUrl(value: string): boolean {
    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

// Half-typed URLs are left out of the estimate instead of turning it into an error.
const {
    estimate,
    pending,
    errors: estimateErrors,
    failed: estimateFailed,
    refresh,
    replace,
} = useEstimate(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    reference_images: form.reference_images
        .map((url) => url.trim())
        .filter(isHttpUrl),
}));

function fieldError(field: string): string | undefined {
    return (
        (form.errors as Record<string, string | undefined>)[field] ??
        estimateErrors.value[field]
    );
}

const promptLength = computed(() => [...form.prompt].length);

// Keyword chips (Pixelforge test mode)
function hasKeyword(keyword: string): boolean {
    return form.prompt.includes(keyword);
}

function toggleKeyword(keyword: string): void {
    if (hasKeyword(keyword)) {
        form.prompt = form.prompt
            .replace(keyword, '')
            .replace(/\s{2,}/g, ' ')
            .trim();
    } else {
        form.prompt =
            form.prompt.trim() === ''
                ? keyword
                : `${form.prompt.trimEnd()} ${keyword}`;
    }
}

// "Surprise me with a cat"
const promptField = useTemplateRef<HTMLTextAreaElement>('promptField');

function surpriseMe(): void {
    const others = props.cat_prompts.filter((prompt) => prompt !== form.prompt);

    if (others.length === 0) {
        return;
    }

    form.prompt = others[Math.floor(Math.random() * others.length)];
    form.clearErrors('prompt');
    promptField.value?.focus();
}

// Reference images
function addReference(): void {
    if (form.reference_images.length < MAX_REFERENCES) {
        form.reference_images.push('');
        void nextTick(() =>
            document
                .getElementById(`reference-${form.reference_images.length - 1}`)
                ?.focus(),
        );
    }
}

function removeReference(index: number): void {
    form.reference_images.splice(index, 1);
}

// Daily cap and balance
function localTime(iso: string | null | undefined): string | null {
    if (!iso) {
        return null;
    }

    return new Date(iso).toLocaleString([], {
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const dailyCapReached = computed(() => props.daily.used >= props.daily.cap);
const cantAfford = computed(() => estimate.value?.affordable === false);

// Submitting
type Problem =
    | { kind: 'prices_changed' }
    | { kind: 'id_reused' }
    | {
          kind: 'insufficient_balance';
          missing: Money | null;
          message: string | null;
      }
    | { kind: 'daily_cap'; resetsAt: string | null; message: string | null }
    | { kind: 'other'; message: string };

const problem = ref<Problem | null>(null);
const submitWhenReady = ref(false);
const formElement = useTemplateRef<HTMLFormElement>('formElement');

// Reasons the button is off no matter what the estimate says.
const blocked = computed(
    () => form.processing || dailyCapReached.value || cantAfford.value,
);

const canSubmit = computed(() => !blocked.value && estimate.value !== null);

function renewId(): void {
    form.id = crypto.randomUUID();
}

function isMoney(value: unknown): value is Money {
    return (
        typeof value === 'object' &&
        value !== null &&
        'display' in value &&
        'exact' in value
    );
}

function isEstimate(value: unknown): value is Estimate {
    return (
        typeof value === 'object' &&
        value !== null &&
        'pricing_version' in value &&
        isMoney((value as { max?: unknown }).max)
    );
}

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * contracts/http.md: 409 id_reused / prices_changed, 422 insufficient_balance, 429 daily_cap,
 * each a JSON body with an `error` code.
 */
function handleHttpException(response: HttpExceptionResponse): boolean | void {
    const body: Record<string, unknown> =
        typeof response.data === 'object' && response.data !== null
            ? response.data
            : {};
    const code = stringOrNull(body.error) ?? stringOrNull(body.code);
    const message = stringOrNull(body.message);

    switch (code) {
        case 'prices_changed': {
            const next = body.estimate ?? body;

            if (isEstimate(next) && Array.isArray(next.breakdown)) {
                replace(next);
            } else {
                refresh();
            }

            problem.value = { kind: 'prices_changed' };

            return false;
        }

        case 'id_reused':
            renewId();
            problem.value = { kind: 'id_reused' };

            return false;

        case 'insufficient_balance': {
            const missing = body.missing ?? body.amount_missing;
            problem.value = {
                kind: 'insufficient_balance',
                missing: isMoney(missing) ? missing : null,
                message,
            };

            return false;
        }

        case 'daily_cap':
            problem.value = {
                kind: 'daily_cap',
                resetsAt: stringOrNull(body.resets_at),
                message,
            };

            return false;
    }

    if (response.status === 419) {
        problem.value = {
            kind: 'other',
            message: 'Your session expired. Reload the page and try again.',
        };

        return false;
    }

    if (response.status === 422 && typeof body.errors === 'object') {
        // A plain validation response sent as JSON: show it on the fields.
        const errors = body.errors as Record<string, string[] | string>;
        form.setError(
            Object.fromEntries(
                Object.entries(errors).map(([field, messages]) => [
                    field,
                    Array.isArray(messages) ? messages[0] : messages,
                ]),
            ),
        );

        return false;
    }

    problem.value = {
        kind: 'other',
        message:
            "Something went wrong and nothing was started. You weren't charged. Try again in a moment.",
    };

    return false;
}

/** The same cases, if the server sends them as Inertia validation errors instead. */
function handleErrors(errors: Record<string, string>): void {
    const message = (key: string) => errors[key] ?? null;

    if ('prices_changed' in errors || errors.pricing_version) {
        refresh();
        problem.value = { kind: 'prices_changed' };
    } else if ('id_reused' in errors || errors.id) {
        renewId();
        problem.value = { kind: 'id_reused' };
    } else if ('insufficient_balance' in errors || errors.balance) {
        problem.value = {
            kind: 'insufficient_balance',
            missing: null,
            message: message('insufficient_balance') ?? message('balance'),
        };
    } else if ('daily_cap' in errors) {
        problem.value = {
            kind: 'daily_cap',
            resetsAt: null,
            message: message('daily_cap'),
        };
    }
}

function submit(): void {
    if (pending.value) {
        // The estimate for the current input is on its way; send once it's there.
        submitWhenReady.value = true;

        return;
    }

    if (!canSubmit.value || !estimate.value) {
        return;
    }

    problem.value = null;

    // Drop empty rows so error keys ("reference_images.1") match the rows on screen.
    const references = form.reference_images
        .map((url) => url.trim())
        .filter((url) => url !== '');

    if (references.join('\n') !== form.reference_images.join('\n')) {
        form.reference_images = references;
    }

    form.pricing_version = estimate.value.pricing_version;

    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => renewId(),
        onError: handleErrors,
        onHttpException: handleHttpException,
        onNetworkError: () => {
            problem.value = {
                kind: 'other',
                message:
                    "We couldn't reach Pixelcat. Check your connection and try again.",
            };

            return false;
        },
    });
}

watch(pending, (isPending) => {
    if (!isPending && submitWhenReady.value) {
        submitWhenReady.value = false;
        submit();
    }
});

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        // requestSubmit runs the browser's own checks (required, maxlength) first.
        formElement.value?.requestSubmit();
    }
}

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                Generate an image
            </h1>
            <p class="text-sm text-muted-foreground">
                Describe what you want. You see the price before you start and
                pay only for images that finish.
            </p>
        </div>

        <BusyNotice />

        <Alert v-if="prefill">
            <CircleAlert />
            <AlertTitle>Settings copied from the failed image</AlertTitle>
            <AlertDescription>
                Nothing is sent until you press Generate.
            </AlertDescription>
        </Alert>

        <div class="grid items-start gap-6 lg:grid-cols-[1fr_20rem]">
            <form
                ref="formElement"
                class="flex flex-col gap-6"
                @submit.prevent="submit"
                @keydown="onKeydown"
            >
                <!-- Prompt -->
                <div class="grid gap-2">
                    <div class="flex items-end justify-between gap-2">
                        <Label for="prompt">Prompt</Label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="cat_prompts.length === 0"
                            @click="surpriseMe"
                        >
                            <CatMascot :size="18" />
                            Surprise me with a cat
                        </Button>
                    </div>
                    <textarea
                        id="prompt"
                        ref="promptField"
                        v-model="form.prompt"
                        name="prompt"
                        rows="5"
                        required
                        :maxlength="MAX_PROMPT"
                        :aria-invalid="fieldError('prompt') ? true : undefined"
                        aria-describedby="prompt-help prompt-error"
                        placeholder="A ginger cat reading the morning paper, oil painting"
                        class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30"
                    />
                    <div
                        id="prompt-help"
                        class="flex justify-between gap-2 text-xs text-muted-foreground"
                    >
                        <span class="hidden sm:inline">
                            Press
                            <kbd class="rounded border px-1 font-sans">{{
                                isMac ? '⌘' : 'Ctrl'
                            }}</kbd>
                            +
                            <kbd class="rounded border px-1 font-sans"
                                >Enter</kbd
                            >
                            to generate.
                        </span>
                        <span class="ml-auto tabular-nums"
                            >{{ promptLength.toLocaleString() }} /
                            {{ MAX_PROMPT.toLocaleString() }}</span
                        >
                    </div>
                    <InputError
                        id="prompt-error"
                        :message="fieldError('prompt')"
                    />
                </div>

                <!-- Test keywords -->
                <fieldset v-if="keywords.length > 0" class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        Test keywords
                    </legend>
                    <p class="-mt-1 text-xs text-muted-foreground">
                        Pixelforge's test mode reacts to these words in the
                        prompt, for example to fail or to run slowly.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="keyword in keywords"
                            :key="keyword"
                            type="button"
                            :aria-pressed="hasKeyword(keyword)"
                            class="rounded-full border px-3 py-1 font-mono text-xs transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-pressed:border-primary aria-pressed:bg-primary aria-pressed:text-primary-foreground"
                            @click="toggleKeyword(keyword)"
                        >
                            {{ keyword }}
                        </button>
                    </div>
                </fieldset>

                <!-- Size -->
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="size in SIZES"
                            :key="size.value"
                            class="flex cursor-pointer flex-col rounded-md border p-3 text-sm transition-colors hover:bg-accent has-checked:border-primary has-checked:ring-1 has-checked:ring-primary has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="form.size"
                                type="radio"
                                name="size"
                                :value="size.value"
                                class="sr-only"
                            />
                            <span class="font-medium">{{ size.label }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                size.hint
                            }}</span>
                        </label>
                    </div>
                    <InputError :message="fieldError('size')" />
                </fieldset>

                <!-- Quality -->
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="quality in QUALITIES"
                            :key="quality.value"
                            class="flex cursor-pointer flex-col rounded-md border p-3 text-sm transition-colors hover:bg-accent has-checked:border-primary has-checked:ring-1 has-checked:ring-primary has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="form.quality"
                                type="radio"
                                name="quality"
                                :value="quality.value"
                                class="sr-only"
                            />
                            <span class="font-medium">{{ quality.label }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                quality.hint
                            }}</span>
                        </label>
                    </div>
                    <InputError :message="fieldError('quality')" />
                </fieldset>

                <!-- Reference images -->
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Reference images
                        <span class="font-normal text-muted-foreground"
                            >(optional, up to {{ MAX_REFERENCES }})</span
                        >
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        Links to images the model should take inspiration from.
                        Each one adds a little to the price.
                    </p>
                    <div
                        v-for="(_url, index) in form.reference_images"
                        :key="index"
                        class="grid gap-1"
                    >
                        <div class="flex gap-2">
                            <Input
                                :id="`reference-${index}`"
                                :aria-label="`Reference image ${index + 1} link`"
                                v-model="form.reference_images[index]"
                                type="url"
                                inputmode="url"
                                :placeholder="REFERENCE_PLACEHOLDER"
                                :aria-invalid="
                                    fieldError(`reference_images.${index}`)
                                        ? true
                                        : undefined
                                "
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="removeReference(index)"
                            >
                                <X />
                            </Button>
                        </div>
                        <InputError
                            :message="fieldError(`reference_images.${index}`)"
                        />
                    </div>
                    <InputError :message="fieldError('reference_images')" />
                    <div>
                        <Button
                            v-if="form.reference_images.length < MAX_REFERENCES"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addReference"
                        >
                            <ImagePlus />
                            Add a reference image
                        </Button>
                    </div>
                </fieldset>

                <!-- What went wrong with the last submit -->
                <div aria-live="assertive">
                    <Alert
                        v-if="problem"
                        :variant="
                            problem.kind === 'prices_changed'
                                ? 'default'
                                : 'destructive'
                        "
                    >
                        <CircleAlert />
                        <template v-if="problem.kind === 'prices_changed'">
                            <AlertTitle>Pixelforge's prices changed</AlertTitle>
                            <AlertDescription>
                                Check the updated estimate, then press Generate
                                to confirm. Nothing was started.
                            </AlertDescription>
                        </template>
                        <template v-else-if="problem.kind === 'id_reused'">
                            <AlertTitle>This form was already used</AlertTitle>
                            <AlertDescription>
                                It was sent before with different settings, so
                                it's now ready as a new request. Press Generate
                                to send it.
                            </AlertDescription>
                        </template>
                        <template
                            v-else-if="problem.kind === 'insufficient_balance'"
                        >
                            <AlertTitle>Not enough credits</AlertTitle>
                            <AlertDescription>
                                <p v-if="problem.missing">
                                    You need {{ problem.missing.display }} more
                                    to start this image.
                                </p>
                                <p v-else>
                                    {{
                                        problem.message ??
                                        "Your balance doesn't cover the top of the estimate."
                                    }}
                                </p>
                                <Link
                                    href="/credits"
                                    class="font-medium underline underline-offset-4"
                                    >Buy credits</Link
                                >
                            </AlertDescription>
                        </template>
                        <template v-else-if="problem.kind === 'daily_cap'">
                            <AlertTitle>Daily limit reached</AlertTitle>
                            <AlertDescription>
                                <template v-if="problem.resetsAt">
                                    You can start {{ daily.cap }} images a day.
                                    It resets {{ localTime(problem.resetsAt) }}.
                                </template>
                                <template v-else>
                                    {{
                                        problem.message ??
                                        `You can start ${daily.cap} images a day. It resets at midnight UTC.`
                                    }}
                                </template>
                            </AlertDescription>
                        </template>
                        <template v-else>
                            <AlertTitle>Couldn't start the image</AlertTitle>
                            <AlertDescription>{{
                                problem.message
                            }}</AlertDescription>
                        </template>
                    </Alert>
                </div>

                <!-- On narrow screens the estimate panel sits below the form. -->
                <p
                    v-if="estimate"
                    class="-mb-3 text-sm text-muted-foreground lg:hidden"
                >
                    Estimated price
                    <MoneyText
                        :money="estimate.likely"
                        class="font-medium text-foreground"
                    />, between <MoneyText :money="estimate.min" /> and
                    <MoneyText :money="estimate.max" />. Details below.
                </p>

                <!-- Submit -->
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <Button
                        type="submit"
                        size="lg"
                        :disabled="blocked || (!pending && estimate === null)"
                    >
                        <Spinner v-if="form.processing || submitWhenReady" />
                        <Sparkles v-else />
                        Generate
                    </Button>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="dailyCapReached">
                            You've used all {{ daily.cap }} images for today.
                            More from {{ localTime(daily.resets_at) }}.
                        </template>
                        <template v-else-if="cantAfford">
                            Your balance is below this image's maximum price.
                            <Link
                                href="/credits"
                                class="font-medium text-foreground underline underline-offset-4"
                                >Buy credits</Link
                            >
                        </template>
                        <template v-else>
                            {{ daily.used }} of {{ daily.cap }} images used
                            today.
                        </template>
                    </p>
                </div>
            </form>

            <div class="lg:sticky lg:top-4">
                <EstimatePanel
                    :estimate="estimate"
                    :pending="pending"
                    :failed="estimateFailed"
                />
            </div>
        </div>
    </div>
</template>
