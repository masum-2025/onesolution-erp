<script setup>
import { computed } from 'vue';
import { KeyRound, Sparkles } from 'lucide-vue-next';
import { EmptyState, ErrorState, PageFrame, SkeletonRows } from '@/ui';
import { useResource } from '@/lib/useResource';
import { api } from '@/lib/http';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * How much the AI assistant read for the company: this month against its
 * limit (rule ai_assistant.monthly_calls) and each of the last days, per
 * module. Counts only; the messages read are never kept here.
 */
const usage = useResource(() => api(`/api/organizations/${currentOrganization().id}/ai/usage`));
const data = computed(() => usage.data.value?.data ?? null);
const rows = computed(() => data.value?.days ?? []);
const share = computed(() => (data.value?.limit ? Math.min(100, Math.round((data.value.used / data.value.limit) * 100)) : 100));
const moduleName = (key) => t(`ai_assistant.modules.${key}`);

const exported = computed(() => ({
    module: 'ai_assistant',
    page: 'ai_assistant.usage',
    columns: [
        { key: 'day', label: t('ai_assistant.usage.day'), value: (row) => formatDate(row.day) },
        { key: 'module', label: t('ai_assistant.usage.module'), value: (row) => moduleName(row.module) },
        { key: 'calls', label: t('ai_assistant.usage.calls'), type: 'number' },
        { key: 'failures', label: t('ai_assistant.usage.failures'), type: 'number' },
        { key: 'input_tokens', label: t('ai_assistant.usage.input_tokens'), type: 'number' },
        { key: 'output_tokens', label: t('ai_assistant.usage.output_tokens'), type: 'number' },
    ],
    rows: rows.value,
}));
</script>

<template>
    <PageFrame :title="t('ai_assistant.usage.title')" :description="t('ai_assistant.usage.text')" width="lg" :export="exported">
        <SkeletonRows v-if="usage.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="usage.error.value" :error="usage.error.value" @retry="usage.reload()" />
        <template v-else-if="data">
            <p v-if="!data.key_set" role="status" class="card mb-4 flex items-start gap-3 border-warning p-4 text-[13.5px]">
                <KeyRound class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                {{ t('ai_assistant.usage.no_key') }}
            </p>

            <section class="card mb-4 grid gap-4 p-5 sm:grid-cols-3">
                <div>
                    <p class="text-[12.5px] text-muted">{{ t('ai_assistant.usage.used') }}</p>
                    <p class="text-2xl font-semibold">{{ formatNumber(data.used) }} <span class="text-[13px] font-normal text-muted">/ {{ formatNumber(data.limit) }}</span></p>
                </div>
                <div>
                    <p class="text-[12.5px] text-muted">{{ t('ai_assistant.usage.left') }}</p>
                    <p class="text-2xl font-semibold" :class="data.left === 0 ? 'text-danger' : ''">{{ formatNumber(data.left) }}</p>
                </div>
                <div>
                    <p class="text-[12.5px] text-muted">{{ t('ai_assistant.usage.model') }}</p>
                    <p class="text-lg font-medium">{{ t(`ai_assistant.models.${data.model}`) }}</p>
                </div>
                <div class="sm:col-span-3">
                    <div class="h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" :aria-valuenow="share" aria-valuemin="0" aria-valuemax="100" :aria-label="t('ai_assistant.usage.used')">
                        <div class="h-full rounded-full" :class="share >= 90 ? 'bg-danger' : 'bg-accent'" :style="{ inlineSize: `${share}%` }" />
                    </div>
                    <p class="mt-2 text-[12.5px] text-muted">{{ data.left === 0 ? t('ai_assistant.usage.limit_reached') : t('ai_assistant.usage.limit_text') }}</p>
                </div>
            </section>

            <section class="card">
                <EmptyState v-if="!rows.length" :icon="Sparkles" :title="t('ai_assistant.usage.empty')" :text="t('ai_assistant.usage.empty_text')" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-[13.5px]">
                        <thead class="text-start text-[12px] text-muted">
                            <tr>
                                <th class="px-4 py-2 text-start font-medium">{{ t('ai_assistant.usage.day') }}</th>
                                <th class="px-4 py-2 text-start font-medium">{{ t('ai_assistant.usage.module') }}</th>
                                <th class="px-4 py-2 text-end font-medium">{{ t('ai_assistant.usage.calls') }}</th>
                                <th class="px-4 py-2 text-end font-medium">{{ t('ai_assistant.usage.failures') }}</th>
                                <th class="px-4 py-2 text-end font-medium">{{ t('ai_assistant.usage.tokens') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in rows" :key="`${row.day}-${row.module}`">
                                <td class="px-4 py-2">{{ formatDate(row.day) }}</td>
                                <td class="px-4 py-2">{{ moduleName(row.module) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums">{{ formatNumber(row.calls) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums">{{ formatNumber(row.failures) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums">{{ formatNumber(row.input_tokens + row.output_tokens) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </PageFrame>
</template>
