<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, Receipt, ShieldCheck, Users } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import StatusBadge from '@/components/pixelcat/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';

defineProps<{
    welcomeCredit: string; // e.g. "$2.00", formatted by the backend
    markupPercent: number;
}>();

const features = [
    {
        icon: Receipt,
        title: 'The price before you press Generate',
        text: "A range from Pixelforge's current rates, updated as you type. At those rates, the final price lands inside it.",
    },
    {
        icon: ShieldCheck,
        title: 'Failed images are free',
        text: 'The top of the estimate is held while it runs and released at the end. A webhook sent twice is still charged once.',
    },
    {
        icon: Activity,
        title: 'Live status',
        text: 'Waiting, queued, generating, done: the history updates on its own, with a timeline of every step.',
    },
    {
        icon: Users,
        title: 'A fair queue',
        text: 'Everyone shares one Pixelforge account: 3 images at once, 2 per person. The rest wait their turn, oldest first.',
    },
];
</script>

<template>
    <Head title="Images priced to the nanodollar" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-5 sm:px-6"
        >
            <Link :href="home()" class="flex items-center gap-2 font-semibold">
                <span
                    class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5" />
                </span>
                Pixelcat
            </Link>

            <nav class="flex items-center gap-2" aria-label="Account">
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="dashboard()">Open Pixelcat</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child>
                        <Link :href="register()">Create account</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main class="flex-1">
            <section
                class="mx-auto grid w-full max-w-5xl items-center gap-12 px-4 pt-10 pb-16 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:pt-20"
            >
                <div class="space-y-6">
                    <p
                        class="inline-flex rounded-full border px-3 py-1 text-xs font-medium text-muted-foreground"
                    >
                        Image generation by Pixelforge
                    </p>
                    <h1
                        class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        Generate images. Pay exactly what they cost.
                    </h1>
                    <p
                        class="max-w-prose text-lg text-pretty text-muted-foreground"
                    >
                        Pixelcat shows a price range before you start, charges
                        what Pixelforge billed plus {{ markupPercent }}% when
                        the image is done, and nothing when it fails.
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button v-if="$page.props.auth.user" size="lg" as-child>
                            <Link :href="dashboard()">Generate an image</Link>
                        </Button>
                        <template v-else>
                            <Button size="lg" as-child>
                                <Link :href="register()">
                                    Start with {{ welcomeCredit }} of credit
                                </Link>
                            </Button>
                            <Button size="lg" variant="outline" as-child>
                                <Link :href="login()">Log in</Link>
                            </Button>
                        </template>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        No card needed. "Buy credits" is a demo and takes no
                        payment.
                    </p>
                </div>

                <figure
                    class="rounded-xl border bg-card p-5 text-card-foreground shadow-sm sm:p-6"
                    aria-labelledby="example-caption"
                >
                    <figcaption
                        id="example-caption"
                        class="text-sm font-medium text-muted-foreground"
                    >
                        How a price is made: the worked example from the
                        Pixelforge docs
                    </figcaption>

                    <ol
                        class="mt-4 flex flex-wrap items-center gap-1.5"
                        aria-label="Status as it runs"
                    >
                        <li>
                            <StatusBadge
                                status="waiting"
                                label="Waiting for a free slot"
                            />
                        </li>
                        <li><StatusBadge status="queued" label="Queued" /></li>
                        <li>
                            <StatusBadge
                                status="processing"
                                label="Generating"
                            />
                        </li>
                        <li><StatusBadge status="succeeded" label="Done" /></li>
                    </ol>

                    <dl class="mt-6 space-y-3 text-sm tabular-nums">
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-muted-foreground">
                                Pixelforge's cost
                            </dt>
                            <dd>$0.373875000</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-muted-foreground">
                                Pixelcat's 25%
                            </dt>
                            <dd>$0.093468750</dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between gap-4 border-t pt-3 text-base font-semibold"
                        >
                            <dt>You pay</dt>
                            <dd>$0.467343750</dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-xs text-muted-foreground">
                        Amounts are kept to the nanodollar, a billionth of a
                        dollar, so the totals match Pixelforge's own records
                        exactly.
                    </p>
                </figure>
            </section>

            <section
                class="mx-auto w-full max-w-5xl px-4 pb-20 sm:px-6"
                aria-labelledby="features-heading"
            >
                <h2 id="features-heading" class="sr-only">What you get</h2>
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <li
                        v-for="feature in features"
                        :key="feature.title"
                        class="rounded-xl border p-5"
                    >
                        <component
                            :is="feature.icon"
                            class="size-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <h3 class="mt-3 font-medium">{{ feature.title }}</h3>
                        <p class="mt-1.5 text-sm text-muted-foreground">
                            {{ feature.text }}
                        </p>
                    </li>
                </ul>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-2 px-4 py-6 text-sm text-muted-foreground sm:px-6"
            >
                <p>Pixelcat, a take-home for PromptEdit.</p>
                <a
                    href="https://github.com/quantinho/pixelcat"
                    class="underline-offset-4 hover:underline"
                    >Source on GitHub</a
                >
            </div>
        </footer>
    </div>
</template>
