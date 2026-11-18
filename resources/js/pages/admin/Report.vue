<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report as reportRoute } from '@/routes/admin';
import type { UsageReport } from '@/types';

const props = defineProps<{
    report: UsageReport;
    range: { from: string; to: string; max_days: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Report', href: reportRoute() }],
    },
});

const form = useForm({ from: props.range.from, to: props.range.to });

function apply() {
    form.get(reportRoute().url, { preserveState: true });
}

// "$0.00" means the two sides agree. Anything else is a real mismatch and is shown as one.
const isZero = (money: string | null) => money === '$0.00';
const differenceText = (money: string) =>
    money.startsWith('-')
        ? 'Pixelforge billed less than we recorded'
        : 'Pixelforge billed more than we recorded';
</script>

<template>
    <Head title="Report" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
        <Heading
            title="Revenue and cost report"
            description="Per UTC day, by the day a generation completed. Provider cost is shown here and nowhere else."
        />

        <form class="flex flex-wrap items-end gap-4" @submit.prevent="apply">
            <div class="grid gap-2">
                <Label for="from">From (UTC)</Label>
                <Input id="from" v-model="form.from" type="date" required />
            </div>
            <div class="grid gap-2">
                <Label for="to">To (UTC)</Label>
                <Input id="to" v-model="form.to" type="date" required />
            </div>
            <Button type="submit" :disabled="form.processing">Show</Button>
            <p class="text-sm text-muted-foreground">
                At most {{ range.max_days }} days. Default: the last 30 days.
            </p>
        </form>
        <InputError :message="form.errors.from" />
        <InputError :message="form.errors.to" />

        <Alert
            v-if="report.pixelforge_error"
            variant="destructive"
            role="alert"
        >
            <AlertTitle>{{ report.pixelforge_error }}</AlertTitle>
            <AlertDescription>
                Our own numbers are shown below. The comparison with Pixelforge
                is missing until its usage can be read again.
            </AlertDescription>
        </Alert>

        <section
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"
            aria-label="Totals"
            data-test="totals"
        >
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">
                    Charged to users
                </div>
                <div class="text-xl font-semibold tabular-nums">
                    {{ report.totals.ours.charged }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">
                    Provider cost (ours)
                </div>
                <div class="text-xl font-semibold tabular-nums">
                    {{ report.totals.ours.provider_cost }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">Profit</div>
                <div class="text-xl font-semibold tabular-nums">
                    {{ report.totals.ours.profit }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">
                    Pixelforge billed
                </div>
                <div class="text-xl font-semibold tabular-nums">
                    {{ report.totals.pixelforge?.cost ?? 'unavailable' }}
                </div>
            </div>
            <div
                class="rounded-lg border p-4"
                :class="
                    report.totals.difference !== null &&
                    !isZero(report.totals.difference)
                        ? 'border-amber-500 bg-amber-50 dark:bg-amber-950/30'
                        : ''
                "
            >
                <div class="text-sm text-muted-foreground">
                    Difference (Pixelforge minus ours)
                </div>
                <div
                    class="text-xl font-semibold tabular-nums"
                    data-test="total-difference"
                >
                    {{ report.totals.difference ?? 'unavailable' }}
                </div>
                <div
                    v-if="
                        report.totals.difference !== null &&
                        !isZero(report.totals.difference)
                    "
                    class="text-xs text-amber-800 dark:text-amber-300"
                >
                    {{ differenceText(report.totals.difference) }}
                </div>
                <div
                    v-else-if="report.totals.difference !== null"
                    class="text-xs text-muted-foreground"
                >
                    Matches Pixelforge
                </div>
            </div>
        </section>

        <p
            v-if="report.totals.pending || report.totals.unknown_outcome"
            class="text-sm text-muted-foreground"
        >
            Gaps that can be explained: {{ report.totals.pending }}
            generation(s) not finished yet,
            {{ report.totals.unknown_outcome }} with an unknown outcome
            (Pixelforge may have created them).
        </p>

        <p v-if="report.days.length === 0" class="text-muted-foreground">
            Nothing was charged or billed in this range.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm" data-test="days">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-2">Day (UTC)</th>
                        <th class="p-2 text-right">
                            Charges (ours / Pixelforge)
                        </th>
                        <th class="p-2 text-right">Charged</th>
                        <th class="p-2 text-right">Provider cost</th>
                        <th class="p-2 text-right">Profit</th>
                        <th class="p-2 text-right">Pixelforge billed</th>
                        <th class="p-2 text-right">Difference</th>
                        <th class="p-2 text-right">Not finished</th>
                        <th class="p-2 text-right">Unknown outcome</th>
                    </tr>
                </thead>
                <tbody
                    v-for="day in report.days"
                    :key="day.date"
                    class="border-t"
                >
                    <tr class="font-medium" :data-day="day.date">
                        <td class="p-2">{{ day.date }}</td>
                        <td class="p-2 text-right tabular-nums">
                            {{ day.ours.charges }}
                            <span
                                v-if="day.pixelforge"
                                class="text-muted-foreground"
                            >
                                / {{ day.pixelforge.generations }}
                            </span>
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ day.ours.charged }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ day.ours.provider_cost }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ day.ours.profit }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ day.pixelforge?.cost ?? 'unavailable' }}
                        </td>
                        <td
                            class="p-2 text-right tabular-nums"
                            :class="
                                day.difference !== null &&
                                !isZero(day.difference)
                                    ? 'bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-200'
                                    : ''
                            "
                        >
                            {{ day.difference ?? 'unavailable' }}
                            <div
                                v-if="
                                    day.difference !== null &&
                                    !isZero(day.difference)
                                "
                                class="text-xs font-normal"
                            >
                                {{ differenceText(day.difference) }}
                            </div>
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ day.pending }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ day.unknown_outcome }}
                        </td>
                    </tr>
                    <tr
                        v-for="user in day.users"
                        :key="`${day.date}-${user.user_id ?? 'deleted'}`"
                        class="text-muted-foreground"
                    >
                        <td class="p-2 pl-6">{{ user.label }}</td>
                        <td class="p-2 text-right tabular-nums">
                            {{ user.charges }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ user.charged }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ user.provider_cost }}
                        </td>
                        <td class="p-2 text-right tabular-nums">
                            {{ user.profit }}
                        </td>
                        <td colspan="4"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
