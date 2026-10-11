<script setup>
import { computed, ref, watch } from 'vue';
import { FilePlus, Files } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { runTitle, day } from '../labels';
import RunDialog from '../components/RunDialog.vue';

/** Billing runs of a session, newest first; a new one is drafted from here. */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();

const sessionId = ref('');
watch(() => setup.data.value, (data) => {
    if (data && !sessionId.value) sessionId.value = data.sessions.find((session) => session.status === 'open')?.id ?? data.sessions[0]?.id ?? '';
}, { immediate: true });
const runs = useResource(() => (sessionId.value ? api.runs({ session_id: sessionId.value }) : Promise.resolve({ data: [] })), { immediate: false });
watch(sessionId, () => runs.reload(), { immediate: true });
const rows = computed(() => runs.data.value?.data ?? []);
const tone = { draft: 'warn', final: 'ok', cancelled: 'outline' };
const making = ref(false);
</script>

<template>
    <div>
        <PageHeader :title="t('education_fees.runs.title')" :description="t('education_fees.runs.text')">
            <template v-if="setup.can('bill')" #actions>
                <AppButton variant="primary" :icon="FilePlus" :disabled="!sessionId" @click="making = true">{{ t('education_fees.runs.new') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="5" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <template v-else-if="setup.data.value">
            <label class="mb-4 block sm:w-64">
                <span class="sr-only">{{ t('education_fees.runs.session') }}</span>
                <select v-model="sessionId" class="field-input">
                    <option v-for="session in setup.data.value.sessions" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
                </select>
            </label>
            <section class="card">
                <SkeletonRows v-if="runs.loading.value && !runs.data.value" :rows="5" />
                <ErrorState v-else-if="runs.error.value" compact :error="runs.error.value" @retry="runs.reload()" />
                <EmptyState v-else-if="!rows.length" :icon="Files" :title="t('education_fees.runs.empty_title')" :text="t('education_fees.runs.empty_text')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="run in rows" :key="run.id">
                        <RouterLink :to="{ name: 'fees-run', params: { id: run.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                            <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                                <span class="block text-[14px] font-medium text-fg">{{ runTitle(run) }}</span>
                                <span class="block text-[12.5px] text-muted">{{ day(run.issue_date) }} · {{ t('education_fees.counter.due', { date: day(run.due_date) }) }} · {{ t('education_fees.runs.bills', { count: run.bills_count }) }}</span>
                            </span>
                            <AppBadge :tone="tone[run.status]" dot>{{ t(`education_fees.run_statuses.${run.status}`) }}</AppBadge>
                            <span class="tabular ms-auto w-32 text-end text-[14px] font-semibold text-fg">{{ setup.money(run.total_minor) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </template>

        <RunDialog :open="making" :session-id="sessionId" @close="making = false" />
    </div>
</template>
