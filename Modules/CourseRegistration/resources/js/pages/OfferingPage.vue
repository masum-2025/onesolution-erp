<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Printer, SearchX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { registrationApi } from '../api';
import { useRegistrationSetup } from '../setup';
import { credits, outcomeTone } from '../lib';
import SeatMeter from '../components/SeatMeter.vue';

/**
 * One offered subject's students: holding a seat (by name), the waiting
 * list in turn, and who withdrew; outcomes recorded here by people who may
 * (completed, failed, incomplete). Prints as a class list.
 */
const org = currentOrganization();
const api = registrationApi(org.id);
const setup = useRegistrationSetup();
const route = useRoute();

const record = useResource(() => api.roster(route.params.id));
const data = computed(() => record.data.value?.data ?? null);
const offering = computed(() => data.value?.offering ?? null);
const holding = computed(() => (data.value?.students ?? []).filter((row) => row.status === 'registered'));
const waiting = computed(() => (data.value?.students ?? []).filter((row) => row.status === 'waitlisted'));
const withdrawn = computed(() => (data.value?.students ?? []).filter((row) => row.status === 'withdrawn'));

async function setOutcome(row, outcome) {
    if (!outcome) return;
    try {
        await api.outcome(row.id, outcome);
        row.outcome = outcome;
        toast.success(t('course_registration.roster.outcome_saved'));
    } catch (error) {
        toast.error(error.message);
    }
}

function printPage() {
    window.print();
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'crs-offerings' }" :icon="ArrowLeft" class="mb-3 print:hidden">{{ t('course_registration.roster.back') }}</AppButton>

        <SkeletonRows v-if="record.loading.value && !data" :rows="8" avatar />
        <section v-else-if="record.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('course_registration.roster.not_found')" /></section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="offering">
            <PageHeader :title="`${offering.subject?.code ?? ''} · ${textIn(offering.subject?.name)}`"
                :description="`${t('course_registration.offerings.group', { name: offering.group_name })} · ${setup.sessionName(offering.session_id)}${offering.level_id ? ` · ${setup.levelText(offering.level_id)}` : ''}`">
                <template #actions>
                    <AppButton :icon="Printer" class="print:hidden" @click="printPage">{{ t('course_registration.roster.print') }}</AppButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="card p-4 sm:col-span-2"><SeatMeter :offering="offering" /></div>
                <div class="card flex flex-wrap content-center gap-1.5 p-4">
                    <AppBadge tone="brand">{{ t(`course_registration.kinds.${offering.kind}`) }}</AppBadge>
                    <AppBadge>{{ t('course_registration.credits_text', { credits: credits(offering.credits_centi) }) }}</AppBadge>
                    <AppBadge v-if="offering.teacher_id && setup.teacherName(offering.teacher_id)" tone="outline">{{ setup.teacherName(offering.teacher_id) }}</AppBadge>
                </div>
            </div>

            <section class="card mb-5">
                <header class="border-b border-line px-5 py-3 text-[13.5px] font-semibold text-fg">{{ t('course_registration.roster.holding') }} ({{ holding.length }})</header>
                <p v-if="!holding.length" class="px-5 py-5 text-[13px] text-muted">{{ t('course_registration.roster.empty') }}</p>
                <ol v-else class="divide-y divide-line">
                    <li v-for="(row, index) in holding" :key="row.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5">
                        <span class="tabular w-8 text-[13px] text-faint">{{ index + 1 }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium text-fg">{{ row.student?.name }}<span v-if="row.student?.name_local" class="font-normal text-muted" lang="bn"> · {{ row.student.name_local }}</span></span>
                            <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ row.student?.code }}</span>
                        </span>
                        <label v-if="setup.can('record_outcome')" class="print:hidden">
                            <span class="sr-only">{{ t('course_registration.roster.outcome') }} · {{ row.student?.name }}</span>
                            <select class="field-input h-8 w-40 text-[12.5px]" :value="row.outcome ?? ''" @change="setOutcome(row, $event.target.value)">
                                <option value="" disabled>{{ t('course_registration.roster.no_outcome') }}</option>
                                <option v-for="outcome in ['completed', 'failed', 'incomplete']" :key="outcome" :value="outcome">{{ t(`course_registration.outcomes.${outcome}`) }}</option>
                            </select>
                        </label>
                        <AppBadge v-else-if="row.outcome" :tone="outcomeTone(row.outcome)">{{ t(`course_registration.outcomes.${row.outcome}`) }}</AppBadge>
                    </li>
                </ol>
            </section>

            <section v-if="waiting.length" class="card mb-5 print:hidden">
                <header class="border-b border-line px-5 py-3 text-[13.5px] font-semibold text-fg">{{ t('course_registration.roster.waiting') }} ({{ waiting.length }})</header>
                <ol class="divide-y divide-line">
                    <li v-for="(row, index) in waiting" :key="row.id" class="flex items-center gap-3 px-5 py-2.5">
                        <AppBadge tone="warn">{{ t('course_registration.roster.place', { count: index + 1 }) }}</AppBadge>
                        <span class="min-w-0 flex-1 truncate text-[14px] text-fg">{{ row.student?.name }}</span>
                        <span class="font-mono text-[12px] text-muted" dir="ltr">{{ row.student?.code }}</span>
                    </li>
                </ol>
            </section>

            <section v-if="withdrawn.length" class="card print:hidden">
                <header class="border-b border-line px-5 py-3 text-[13.5px] font-semibold text-fg">{{ t('course_registration.roster.withdrawn') }} ({{ withdrawn.length }})</header>
                <ul class="divide-y divide-line">
                    <li v-for="row in withdrawn" :key="row.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-[13.5px]">
                        <span class="min-w-0 flex-1 truncate text-fg">{{ row.student?.name }}</span>
                        <span class="truncate text-[12.5px] text-muted">{{ row.reason }}</span>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
