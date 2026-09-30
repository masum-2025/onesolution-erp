<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Download, FileSpreadsheet, Loader2 } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { AUDIT_AREAS, auditQuery, lastDays } from '@/lib/auditPeriod';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * advanced_audit exports (Phase 9-1): the log of a period as a CSV file for
 * a spreadsheet, prepared in the background, downloadable for a few days.
 */
const org = currentOrganization();
// Calendar days (no time): shown as written, whatever the viewer's time zone.
const DAY = { dateStyle: 'medium', timeZone: 'UTC' };
const form = reactive({ ...lastDays(30), action: 'all' });
const errors = ref({});
const sending = ref(false);

const exports = useResource(() => api(`/api/organizations/${org.id}/audit-log/exports`).then((response) => response.data));
const list = computed(() => exports.data.value ?? []);
const working = computed(() => list.value.some((item) => ['queued', 'running'].includes(item.status)));

let poll;
watch(working, (value) => {
    clearInterval(poll);
    if (value) poll = setInterval(() => exports.reload(), 4000);
}, { immediate: true });
onBeforeUnmount(() => clearInterval(poll));

const STATUS_TONES = { queued: 'neutral', running: 'brand', ready: 'ok', failed: 'bad', expired: 'outline' };

async function request() {
    sending.value = true;
    errors.value = {};
    try {
        await api(`/api/organizations/${org.id}/audit-log/exports`, { method: 'POST', body: auditQuery(form) });
        toast.success(t('trust.audit.exports.requested'));
        exports.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        sending.value = false;
    }
}

async function download(item) {
    try {
        const { data } = await api(`/api/organizations/${org.id}/audit-log/exports/${item.id}/link`);
        window.location.assign(data.url);
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div class="space-y-5">
        <form class="card grid gap-4 px-5 py-4 sm:grid-cols-4 sm:items-end" @submit.prevent="request">
            <AppField v-slot="{ id, describedby }" :label="t('trust.audit.filters.from')" :error="errors.from?.[0]">
                <input :id="id" v-model="form.from" type="date" class="field-input" :aria-describedby="describedby" required />
            </AppField>
            <AppField v-slot="{ id, describedby }" :label="t('trust.audit.filters.to')" :error="errors.to?.[0]">
                <input :id="id" v-model="form.to" type="date" class="field-input" :aria-describedby="describedby" required />
            </AppField>
            <AppField v-slot="{ id }" :label="t('trust.audit.filters.area')">
                <select :id="id" v-model="form.action" class="field-input">
                    <option value="all">{{ t('trust.audit.filters.all_areas') }}</option>
                    <option v-for="area in AUDIT_AREAS" :key="area" :value="area">{{ t(`trust.audit.areas.${area}`) }}</option>
                </select>
            </AppField>
            <AppButton type="submit" variant="primary" :icon="FileSpreadsheet" :loading="sending" :disabled="working">{{ t('trust.audit.exports.request') }}</AppButton>
        </form>

        <section class="card">
            <SkeletonRows v-if="exports.loading.value && !exports.data.value" :rows="3" />
            <ErrorState v-else-if="exports.error.value" compact :error="exports.error.value" @retry="exports.reload()" />
            <EmptyState v-else-if="!list.length" :icon="FileSpreadsheet" :title="t('trust.audit.exports.empty_title')" :text="t('trust.audit.exports.empty_text')" compact />

            <ul v-else class="divide-y divide-line">
                <li v-for="item in list" :key="item.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                            {{ t('trust.audit.exports.period', { from: formatDate(item.filters?.from_date, DAY), to: formatDate(item.filters?.to_date, DAY) }) }}
                            <AppBadge :tone="STATUS_TONES[item.status]" :icon="['queued', 'running'].includes(item.status) ? Loader2 : null">{{ t(`trust.export.status.${item.status}`) }}</AppBadge>
                        </p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            <template v-if="item.status === 'ready'">
                                {{ t('trust.audit.exports.rows', { rows: formatNumber(item.rows ?? 0) }) }} · {{ t('trust.export.until', { date: formatDateTime(item.expires_at) }) }}
                            </template>
                            <template v-else-if="item.status === 'failed'">{{ t('trust.export.failed') }}</template>
                            <template v-else-if="item.status === 'expired'">{{ t('trust.export.expired') }}</template>
                            <template v-else>{{ t('trust.export.preparing') }}</template>
                        </p>
                    </div>
                    <AppButton v-if="item.status === 'ready'" :icon="Download" @click="download(item)">{{ t('trust.export.download') }}</AppButton>
                </li>
            </ul>
        </section>

        <p class="text-[12.5px] leading-relaxed text-muted">{{ t('trust.audit.exports.note') }}</p>
    </div>
</template>
