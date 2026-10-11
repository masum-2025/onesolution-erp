<script setup>
import { computed, ref, watch } from 'vue';
import { Download, PartyPopper } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { downloadText, toCsv } from '@/lib/csv';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { amountText } from '../lib';

/**
 * Who owes in a session (a class), most overdue first: what each owes, how
 * much is overdue and for how long; saved as CSV for calls or letters.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();

const sessionId = ref('');
const levelId = ref('');
const overdueOnly = ref(false);
watch(() => setup.data.value, (data) => {
    if (data && !sessionId.value) sessionId.value = data.sessions.find((session) => session.status === 'open')?.id ?? data.sessions[0]?.id ?? '';
}, { immediate: true });
const dues = useResource(() => api.dues({ session_id: sessionId.value, level_id: levelId.value || undefined, overdue_only: overdueOnly.value ? 1 : undefined }), { immediate: false });
watch([sessionId, levelId, overdueOnly], () => sessionId.value && dues.reload(), { immediate: true });
const rows = computed(() => dues.data.value?.data ?? []);
const meta = computed(() => dues.data.value?.meta ?? null);
const levelName = (id) => {
    const level = setup.data.value?.levels.find((item) => item.id === id);
    return level ? `${textIn(level.program?.name)} · ${textIn(level.name)}` : '';
};

function download() {
    const currency = setup.currency.value;
    const header = ['code', 'name', 'class', 'bills', 'owed', 'overdue', 'oldest', 'days'].map((key) => t(`education_fees.dues.csv_columns.`));
    const lines = rows.value.map((row) => [row.student.code, row.student.name, levelName(row.level_id), row.bills, amountText(row.owed_minor, currency) || '0', amountText(row.overdue_minor, currency) || '0', row.oldest_due ?? '', row.days_overdue]);
    downloadText(`dues-${new Date().toISOString().slice(0, 10)}.csv`, toCsv([header, ...lines]));
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_fees.dues.title')" :description="t('education_fees.dues.text')">
            <template #actions>
                <AppButton :icon="Download" :disabled="!rows.length" @click="download">{{ t('education_fees.dues.csv') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="5" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <template v-else-if="setup.data.value">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <select v-model="sessionId" class="field-input w-full sm:w-56" :aria-label="t('education_fees.dues.session')">
                    <option v-for="session in setup.data.value.sessions" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
                </select>
                <select v-model="levelId" class="field-input w-full sm:w-64" :aria-label="t('education_fees.dues.level')">
                    <option value="">{{ t('education_fees.dues.all_levels') }}</option>
                    <option v-for="level in setup.data.value.levels" :key="level.id" :value="level.id">{{ levelName(level.id) }}</option>
                </select>
                <AppSwitch v-model="overdueOnly" :label="t('education_fees.dues.overdue_only')" show-label />
            </div>

            <dl v-if="meta" class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="card p-4"><dt class="text-[12px] text-muted">{{ t('education_fees.dues.students') }}</dt><dd class="tabular text-[22px] font-semibold">{{ meta.students }}</dd></div>
                <div class="card p-4"><dt class="text-[12px] text-muted">{{ t('education_fees.dues.owed') }}</dt><dd class="tabular text-[22px] font-semibold">{{ setup.money(meta.owed_minor) }}</dd></div>
                <div class="card p-4"><dt class="text-[12px] text-muted">{{ t('education_fees.dues.overdue') }}</dt><dd class="tabular text-[22px] font-semibold" :class="meta.overdue_minor ? 'text-bad' : ''">{{ setup.money(meta.overdue_minor) }}</dd></div>
            </dl>

            <section class="card">
                <SkeletonRows v-if="dues.loading.value && !dues.data.value" :rows="6" />
                <ErrorState v-else-if="dues.error.value" compact :error="dues.error.value" @retry="dues.reload()" />
                <EmptyState v-else-if="!rows.length" :icon="PartyPopper" :title="t('education_fees.dues.none')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="row in rows" :key="row.student.id">
                        <RouterLink :to="{ name: 'fees-student', params: { id: row.student.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                            <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ row.student.name }}</span>
                                <span class="block text-[12.5px] text-muted"><span class="font-mono" dir="ltr">{{ row.student.code }}</span> · {{ levelName(row.level_id) }} · {{ t('education_fees.dues.bills', { count: row.bills }) }}</span>
                            </span>
                            <AppBadge v-if="row.days_overdue" :tone="row.days_overdue > 30 ? 'bad' : 'warn'" dot>{{ t('education_fees.dues.days', { count: row.days_overdue }) }}</AppBadge>
                            <AppBadge v-else tone="neutral">{{ t('education_fees.dues.on_time') }}</AppBadge>
                            <span class="ms-auto w-36 text-end">
                                <span class="tabular block text-[14px] font-semibold text-fg">{{ setup.money(row.owed_minor) }}</span>
                                <span v-if="row.overdue_minor" class="tabular block text-[12px] text-bad">{{ setup.money(row.overdue_minor) }}</span>
                            </span>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
