<script setup>
import { computed, ref } from 'vue';
import { ChevronRight, RotateCw } from 'lucide-vue-next';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

defineOptions({ name: 'OrgChartNode' });

/**
 * One person in the org chart and, when opened, the people who report to
 * them (loaded only then). Keyboard: the toggle is a button with
 * aria-expanded; the tree is a list of lists.
 */
const props = defineProps({
    person: { type: Object, required: true },
    hrm: { type: Object, required: true },
    depth: { type: Number, default: 0 },
});

const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const reports = ref(null);
const total = ref(0);

const initials = computed(() =>
    props.person.full_name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join(''),
);

async function load() {
    loading.value = true;
    failed.value = false;
    try {
        const response = await props.hrm.orgChart(props.person.id);
        reports.value = response.data;
        total.value = response.meta.total;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

async function toggle() {
    open.value = !open.value;
    if (open.value && reports.value === null) await load();
}
</script>

<template>
    <li class="relative">
        <div class="group flex items-center gap-3 rounded-2xl border border-line bg-surface p-3 shadow-card transition hover:border-line-strong hover:shadow-pop">
            <span class="avatar grid size-10 shrink-0 place-items-center rounded-xl text-[13px] font-bold text-brand-fg" aria-hidden="true">{{ initials }}</span>
            <RouterLink :to="`/hrm/employees/${person.id}`" class="min-w-0 flex-1 rounded-lg outline-offset-4">
                <span class="block truncate text-[14px] font-semibold text-fg group-hover:text-brand-text">{{ person.full_name }}</span>
                <span class="block truncate text-[12.5px] text-muted">
                    {{ person.position?.title ?? t('hrm.org_chart.no_position') }} · {{ person.unit.name }}
                </span>
            </RouterLink>
            <button
                v-if="person.reports_count"
                type="button"
                class="tabular flex h-8 shrink-0 items-center gap-1 rounded-lg bg-brand-soft px-2.5 text-[12.5px] font-semibold text-brand-text transition hover:bg-brand hover:text-brand-fg"
                :aria-expanded="open"
                :aria-label="t('hrm.org_chart.toggle', { name: person.full_name, count: person.reports_count, shown: formatNumber(person.reports_count) })"
                @click="toggle"
            >
                {{ formatNumber(person.reports_count) }}
                <ChevronRight class="size-4 transition-transform duration-200 rtl:rotate-180" :class="open && 'rotate-90 rtl:rotate-90'" aria-hidden="true" />
            </button>
        </div>

        <div v-if="open" class="relative ms-5 border-s-2 border-line ps-4 pt-3 sm:ms-6 sm:ps-6">
            <div v-if="loading" class="space-y-2" role="status" :aria-label="t('core.states.loading')">
                <div v-for="n in Math.min(person.reports_count, 3)" :key="n" class="skeleton h-16 rounded-2xl" />
            </div>
            <p v-else-if="failed" class="flex items-center gap-2 text-[13px] text-muted" role="alert">
                {{ t('hrm.org_chart.failed') }}
                <button type="button" class="inline-flex items-center gap-1 font-medium text-brand-text hover:underline" @click="load">
                    <RotateCw class="size-3.5" aria-hidden="true" />{{ t('core.actions.retry') }}
                </button>
            </p>
            <template v-else-if="reports">
                <ul class="space-y-3">
                    <OrgChartNode v-for="report in reports" :key="report.id" :person="report" :hrm="hrm" :depth="depth + 1" />
                </ul>
                <p v-if="total > reports.length" class="mt-2 text-[12.5px] text-muted">{{ t('hrm.org_chart.more', { count: total - reports.length, shown: formatNumber(total - reports.length) }) }}</p>
            </template>
        </div>
    </li>
</template>
