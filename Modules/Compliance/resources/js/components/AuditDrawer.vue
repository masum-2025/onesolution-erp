<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { CalendarClock, Globe, Save, Send, XCircle } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { renewalTone, stateTone } from '../lib';
import DatesDialog from './DatesDialog.vue';
import RenewalDialog from './RenewalDialog.vue';
import QuoteCard from './QuoteCard.vue';
import ResultCard from './ResultCard.vue';

/**
 * One register line: where it stands (days left from the official expiry
 * day), its details (people who keep the register change them), its
 * official dates (people allowed, with a reason) and every change.
 */
const props = defineProps({
    open: Boolean,
    auditId: { type: String, default: null },
    options: { type: Object, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'changed']);

const line = ref(null);
const error = ref(null);
const form = reactive({ provider_id: '', audit_type_id: '', result: '', certificate_no: '', notes: '' });
const saving = ref(false);
const datesOpen = ref(false);

async function load() {
    error.value = null;
    line.value = null;
    try {
        set((await props.calls.audit(props.auditId)).data);
    } catch (caught) {
        error.value = caught;
    }
}
function set(data) {
    line.value = data;
    Object.assign(form, {
        provider_id: data.provider?.id ?? '', audit_type_id: data.audit_type?.id ?? '',
        result: data.result ?? '', certificate_no: data.certificate_no ?? '', notes: data.notes ?? '',
    });
}
watch(() => [props.open, props.auditId], ([open]) => open && props.auditId && load(), { immediate: true });

const changed = computed(() => line.value && (
    form.provider_id !== (line.value.provider?.id ?? '') || form.audit_type_id !== (line.value.audit_type?.id ?? '')
    || form.result !== (line.value.result ?? '') || form.certificate_no !== (line.value.certificate_no ?? '') || form.notes !== (line.value.notes ?? '')));
const months = computed(() => props.options?.services.find((service) => service.id === line.value?.service?.id)?.validity_months ?? null);

async function save() {
    saving.value = true;
    try {
        set((await props.calls.update(line.value.id, {
            base_version: line.value.version,
            provider_id: form.provider_id || null, audit_type_id: form.audit_type_id || null,
            result: form.result || null, certificate_no: form.certificate_no || null, notes: form.notes || null,
        })).data);
        toast.success(t('compliance.drawer.saved'));
        emit('changed');
    } catch (caught) {
        toast.error(caught.message);
        if (caught.code === 'version_conflict') load();
    } finally {
        saving.value = false;
    }
}
// Renewal with the provider (CMP-2).
const renewalOpen = ref(false);
const cancelling = ref(false);
async function cancelRenewal() {
    const answer = await confirmAction({
        title: t('compliance.renewal.cancel_title'),
        message: t('compliance.renewal.cancel_text', { provider: line.value.renewal.provider?.name ?? '' }),
        confirmLabel: t('compliance.renewal.cancel'),
        danger: true,
        reason: 'optional',
    });
    if (!answer) return;
    cancelling.value = true;
    try {
        await props.calls.cancelRenewal(line.value.renewal.id, line.value.renewal.version, answer.reason);
        toast.success(t('compliance.renewal.cancelled'));
        await load();
        emit('changed');
    } catch (error) {
        toast.error(error.message);
    } finally {
        cancelling.value = false;
    }
}

function dated(data) {
    datesOpen.value = false;
    set(data);
    emit('changed');
}

const daysText = (days) => (days === null ? t('compliance.days.none') : days < 0 ? t('compliance.days.ago', { count: -days }) : days === 0 ? t('compliance.days.today') : t('compliance.days.left', { count: days }));
// Dates and texts with their value; a provider or kind (ids) only as "changed".
const SHOWN = ['last_audit_on', 'expires_on', 'result', 'certificate_no'];
const changeText = (change) => Object.entries(change.new ?? {})
    .filter(([key]) => change.kind !== 'added' || SHOWN.includes(key))
    .map(([key, value]) => (SHOWN.includes(key)
        ? `${t(`compliance.fields.${key}`)}: ${key.endsWith('_on') && value ? formatDate(value) : value ?? '—'}`
        : t('compliance.drawer.changed', { field: t(`compliance.fields.${key.replace('_id', '')}`) })))
    .join(' · ');
</script>

<template>
    <AppDrawer :open="open" :title="line ? `${line.service?.name} · ${line.factory.name}` : t('compliance.drawer.title')" width="sm:max-w-[600px]" @close="emit('close')">
        <ErrorState v-if="error" compact class="m-5" :error="error" @retry="load" />
        <SkeletonRows v-else-if="!line" class="px-5 py-5 sm:px-6" :rows="5" />
        <div v-else class="space-y-5 px-5 py-5 sm:px-6">
            <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <p class="rounded-xl bg-subtle px-4 py-3 sm:col-span-1">
                    <span class="block text-[12.5px] text-muted">{{ t('compliance.fields.state') }}</span>
                    <AppBadge :tone="stateTone(line.state)" class="mt-1">{{ t(`compliance.states.${line.state}`) }}</AppBadge>
                    <span class="mt-1 block text-[13px] font-medium">{{ daysText(line.days_left) }}</span>
                </p>
                <p class="rounded-xl bg-subtle px-4 py-3">
                    <span class="block text-[12.5px] text-muted">{{ t('compliance.fields.last_audit_on') }}</span>
                    <span class="text-[15px] font-semibold">{{ line.last_audit_on ? formatDate(line.last_audit_on) : '—' }}</span>
                </p>
                <p class="rounded-xl bg-subtle px-4 py-3">
                    <span class="block text-[12.5px] text-muted">{{ t('compliance.fields.expires_on') }}</span>
                    <span class="text-[15px] font-semibold">{{ line.expires_on ? formatDate(line.expires_on) : '—' }}</span>
                </p>
            </section>
            <AppButton v-if="line.can.dates" :icon="CalendarClock" @click="datesOpen = true">{{ t('compliance.dates.title') }}</AppButton>
            <p v-else class="text-[12.5px] text-muted">{{ t('compliance.drawer.dates_by') }}</p>

            <!-- Renewal with the provider (CMP-2): the latest request, its answer, and results waiting. -->
            <section class="space-y-3 rounded-xl border border-line px-4 py-3" :aria-label="t('compliance.renewal.section')">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-[13.5px] font-semibold">{{ t('compliance.renewal.section') }}</h3>
                    <AppButton v-if="line.can.request && !line.renewal?.open" size="sm" :icon="Send" @click="renewalOpen = true">{{ t('compliance.renewal.button') }}</AppButton>
                </div>
                <div v-if="line.renewal" class="space-y-1 text-[13px]">
                    <p class="flex flex-wrap items-center gap-2">
                        <AppBadge :tone="renewalTone(line.renewal.status)">{{ t(`compliance.renewal_statuses.${line.renewal.status}`) }}</AppBadge>
                        <span class="text-muted">{{ t('compliance.renewal.asked_on', { date: formatDate(line.renewal.created_at) }) }}</span>
                    </p>
                    <p v-if="line.renewal.delivery === 'failed'" class="text-bad">{{ t('compliance.renewal.not_delivered') }}</p>
                    <p v-if="line.renewal.wanted_by" class="text-fg-2">{{ t('compliance.renewal.wanted_by_value', { date: formatDate(line.renewal.wanted_by) }) }}</p>
                    <p v-if="line.renewal.planned_on" class="font-medium">{{ t('compliance.renewal.planned_value', { date: formatDate(line.renewal.planned_on) }) }}</p>
                    <p v-if="line.renewal.reply_note" class="text-fg-2">“{{ line.renewal.reply_note }}”</p>
                    <p v-if="line.renewal.cancel_reason" class="text-muted">{{ t('compliance.renewal.cancelled_because', { reason: line.renewal.cancel_reason }) }}</p>
                    <AppButton v-if="line.renewal.can.cancel" size="sm" variant="ghost" class="text-bad" :icon="XCircle" :disabled="cancelling" @click="cancelRenewal">{{ t('compliance.renewal.cancel') }}</AppButton>
                </div>
                <p v-else class="text-[12.5px] text-muted">{{ t(line.provider ? 'compliance.renewal.none_yet' : 'compliance.renewal.needs_provider') }}</p>
                <QuoteCard v-for="quote in line.quotes ?? []" :key="quote.id" :quote="quote" :calls="calls" @decided="load(); emit('changed')" />
                <ResultCard v-for="result in line.results" :key="result.id" :result="result" :calls="calls" @decided="load(); emit('changed')" />
            </section>

            <section v-if="line.can.edit && options" class="grid gap-4 sm:grid-cols-2" :aria-label="t('compliance.drawer.details')">
                <AppField v-slot="{ id }" :label="t('compliance.fields.provider')" optional>
                    <select :id="id" v-model="form.provider_id" class="field-input">
                        <option value="">{{ t('compliance.common.none') }}</option>
                        <option v-for="provider in options.providers" :key="provider.id" :value="provider.id">{{ provider.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('compliance.fields.audit_type')" optional>
                    <select :id="id" v-model="form.audit_type_id" class="field-input">
                        <option value="">{{ t('compliance.common.none') }}</option>
                        <option v-for="type in options.types" :key="type.id" :value="type.id">{{ type.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('compliance.fields.result')" optional>
                    <input :id="id" v-model="form.result" class="field-input" maxlength="60" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('compliance.fields.certificate_no')" optional>
                    <input :id="id" v-model="form.certificate_no" class="field-input" dir="ltr" maxlength="100" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('compliance.fields.notes')" optional class="sm:col-span-2">
                    <textarea :id="id" v-model="form.notes" rows="2" class="field-input" maxlength="2000"></textarea>
                </AppField>
                <div class="sm:col-span-2">
                    <AppButton :icon="Save" :loading="saving" :disabled="!changed" @click="save">{{ t('compliance.drawer.save') }}</AppButton>
                </div>
            </section>
            <dl v-else class="grid grid-cols-1 gap-x-6 gap-y-3 text-[13.5px] sm:grid-cols-2">
                <div><dt class="text-muted">{{ t('compliance.fields.provider') }}</dt><dd class="flex items-center gap-1.5 font-medium">{{ line.provider?.name ?? '—' }}<Globe v-if="line.provider?.on_platform" class="size-3.5 text-brand" :aria-label="t('compliance.lists.on_platform')" /></dd></div>
                <div><dt class="text-muted">{{ t('compliance.fields.audit_type') }}</dt><dd class="font-medium">{{ line.audit_type?.name ?? '—' }}</dd></div>
                <div><dt class="text-muted">{{ t('compliance.fields.result') }}</dt><dd class="font-medium">{{ line.result ?? '—' }}</dd></div>
                <div><dt class="text-muted">{{ t('compliance.fields.certificate_no') }}</dt><dd class="font-mono font-medium" dir="ltr">{{ line.certificate_no ?? '—' }}</dd></div>
                <div v-if="line.notes" class="sm:col-span-2"><dt class="text-muted">{{ t('compliance.fields.notes') }}</dt><dd>{{ line.notes }}</dd></div>
            </dl>

            <section :aria-label="t('compliance.drawer.history')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('compliance.drawer.history') }}</h3>
                <ol class="space-y-1.5 border-s border-line ps-4 text-[13px]">
                    <li v-for="(change, index) in line.history" :key="index">
                        <span class="font-medium">{{ t(`compliance.changes.${change.kind}`) }}</span>
                        <span class="text-muted"> · {{ change.by ?? '—' }} · {{ formatDateTime(change.at) }}</span>
                        <p v-if="change.new" class="text-[12.5px] text-fg-2">{{ changeText(change) }}</p>
                        <p v-if="change.reason" class="text-[12.5px] text-muted">“{{ change.reason }}”</p>
                    </li>
                </ol>
            </section>
        </div>
        <DatesDialog :open="datesOpen" :line="line" :months="months" :calls="calls" @close="datesOpen = false" @saved="dated" />
        <RenewalDialog :open="renewalOpen" :line="line" :calls="calls" @close="renewalOpen = false" @sent="load(); emit('changed')" />
    </AppDrawer>
</template>
