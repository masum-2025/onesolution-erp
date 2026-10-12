<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowRight, Check, FilePen, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatRelative } from '@/lib/format';
import { currentOrganization, session } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { marksShown, partName, subjectName } from '../labels';

/**
 * Changes to published marks: what waits for approval (decided with
 * .publish by someone other than who asked), and what was applied or
 * refused. Applying works the results out again.
 */
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();
const me = computed(() => session.me?.user?.id);

const tab = ref('pending');
const tabs = computed(() => ['pending', 'applied', 'rejected'].map((key) => ({ key, label: t(`education_exams.corrections.statuses.${key}`) })));
const list = useResource(() => api.corrections({ status: tab.value }));
watch(tab, () => list.reload());
const rows = computed(() => list.data.value?.data ?? []);
const people = computed(() => list.data.value?.people ?? {});
const markText = (marks, absent) => (absent ? t('education_exams.entry.absent') : marksShown(marks));
const tone = { pending: 'warn', applied: 'ok', rejected: 'bad' };

const busy = ref(null);
async function decide(row, approve) {
    let note = null;
    if (!approve) {
        const confirmed = await confirmAction({
            title: t('education_exams.corrections.reject_title'), message: t('education_exams.corrections.reject_text'),
            reason: 'required', reasonLabel: t('education_exams.corrections.reject_reason'), confirmLabel: t('education_exams.corrections.reject'), danger: true,
        });
        if (!confirmed) return;
        note = confirmed.reason;
    }
    busy.value = row.id;
    try {
        await api.decideCorrection(row.id, approve, { base_version: row.version, ...(note ? { note } : {}) });
        toast.success(t(approve ? 'education_exams.corrections.applied' : 'education_exams.corrections.rejected'));
        list.reload();
    } catch (error) {
        toast.error(error.errors?.note?.[0] ?? error.message);
        if (error.code === 'version_conflict') list.reload();
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_exams.corrections.title')" :description="t('education_exams.corrections.text')" />
        <div class="mb-4 overflow-x-auto"><AppTabs v-model="tab" :tabs="tabs" :label="t('education_exams.corrections.title')" /></div>
        <p v-if="tab === 'pending' && !setup.can('publish')" class="mb-4 text-[12.5px] text-muted">{{ t('education_exams.corrections.cannot') }}</p>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="FilePen" compact :title="t(`education_exams.corrections.empty_${tab}`)" />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.id" class="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-[14px] font-medium text-fg">
                            {{ row.student?.name ?? '—' }}
                            <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ row.student?.code }}</span>
                        </p>
                        <p class="text-[12.5px] text-muted">
                            {{ textIn(row.exam?.name) }} · {{ subjectName(row.subject) }}<template v-if="row.part && row.part.code !== 'TOTAL'"> · {{ partName(row.part) }}</template> · {{ setup.levelName(row.level_id) }}
                        </p>
                        <p class="mt-1 inline-flex items-center gap-2 text-[14px]">
                            <span class="tabular text-muted line-through">{{ markText(row.old_marks_centi, row.old_absent) }}</span>
                            <ArrowRight class="size-3.5 text-faint rtl:rotate-180" aria-hidden="true" />
                            <span class="tabular font-semibold text-fg">{{ markText(row.new_marks_centi, row.new_absent) }}</span>
                            <span class="sr-only">{{ t('education_exams.corrections.from_to') }}</span>
                        </p>
                        <p class="mt-1 text-[12.5px] text-fg">“{{ row.reason }}”</p>
                        <p class="text-[12px] text-muted">
                            {{ t('education_exams.corrections.asked_by', { name: people[row.requested_by] ?? '—' }) }}<template v-if="row.created_at"> · {{ formatRelative(row.created_at) }}</template>
                            <template v-if="row.decided_by"> · {{ t('education_exams.corrections.decided_by', { name: people[row.decided_by] ?? '—' }) }}</template>
                        </p>
                        <p v-if="row.decision_note" class="text-[12px] text-muted">{{ t('education_exams.corrections.note', { note: row.decision_note }) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <AppBadge :tone="tone[row.status]" dot>{{ t(`education_exams.corrections.statuses.${row.status}`) }}</AppBadge>
                        <template v-if="row.status === 'pending' && setup.can('publish')">
                            <span v-if="row.requested_by === me" class="text-[12px] text-warn">{{ t('education_exams.corrections.own') }}</span>
                            <template v-else>
                                <AppButton size="sm" variant="primary" :icon="Check" :loading="busy === row.id" @click="decide(row, true)">{{ t('education_exams.corrections.approve') }}</AppButton>
                                <AppButton size="sm" variant="danger-soft" :icon="X" :disabled="busy === row.id" @click="decide(row, false)">{{ t('education_exams.corrections.reject') }}</AppButton>
                            </template>
                        </template>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
