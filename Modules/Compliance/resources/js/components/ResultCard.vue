<script setup>
import { ref } from 'vue';
import { CheckCircle2, FileText, XCircle } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * A result a provider sent: what it says next to what the register says now,
 * its files (short-lived links), and accepting it into the register or
 * rejecting it with a reason. Accepting changes the official dates.
 */
const props = defineProps({
    result: { type: Object, required: true },
    calls: { type: Object, required: true },
    showFactory: Boolean,
});
const emit = defineEmits(['decided']);

const busy = ref(false);
const FIELDS = ['last_audit_on', 'expires_on', 'result', 'certificate_no'];
const shown = (key, value) => (value ? (key.endsWith('_on') ? formatDate(value) : value) : '—');
const differs = (key) => props.result.current && (props.result.current[key] ?? null) !== (props.result[key] ?? null);

async function decide(step) {
    const answer = await confirmAction({
        title: t(`compliance.results.${step}_title`),
        message: t(`compliance.results.${step}_text`, { from: props.result.from }),
        confirmLabel: t(`compliance.results.${step}`),
        danger: step === 'reject',
        reason: step === 'reject' ? 'required' : 'none',
    });
    if (!answer) return;
    busy.value = true;
    try {
        const { data } = await props.calls.decideResult(props.result.id, step, props.result.version, answer.reason);
        toast.success(t(`compliance.results.${step}_done`));
        emit('decided', data);
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <article class="rounded-xl border border-line">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-2.5">
            <p class="min-w-0 text-[13.5px]">
                <span class="font-semibold">{{ result.service.name }}</span>
                <span v-if="showFactory" class="text-muted"> · {{ result.factory.name }}</span>
                <span class="block text-[12.5px] text-muted">{{ t('compliance.results.from', { from: result.from, date: formatDateTime(result.created_at) }) }}</span>
            </p>
            <AppBadge :tone="result.status === 'pending' ? 'warn' : result.status === 'applied' ? 'ok' : 'bad'">{{ t(`compliance.results.status_${result.status}`) }}</AppBadge>
        </header>
        <table class="w-full text-[13px]">
            <thead>
                <tr class="text-[12px] text-muted">
                    <th scope="col" class="px-4 py-1.5 text-start font-medium"><span class="sr-only">{{ t('compliance.results.field') }}</span></th>
                    <th v-if="result.current" scope="col" class="px-2 py-1.5 text-start font-medium">{{ t('compliance.results.now') }}</th>
                    <th scope="col" class="px-2 py-1.5 text-start font-medium">{{ t('compliance.results.sent') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="key in FIELDS" :key="key">
                    <th scope="row" class="px-4 py-1.5 text-start font-normal text-muted">{{ t(`compliance.fields.${key}`) }}</th>
                    <td v-if="result.current" class="px-2 py-1.5">{{ shown(key, result.current[key]) }}</td>
                    <td class="px-2 py-1.5" :class="differs(key) ? 'font-semibold text-fg' : ''">{{ shown(key, result[key]) }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="!result.current" class="px-4 pb-2 text-[12.5px] text-muted">{{ t('compliance.results.new_line') }}</p>
        <p v-if="result.note" class="px-4 pb-2 text-[13px]">“{{ result.note }}”</p>
        <ul v-if="result.files.length" class="flex flex-wrap gap-2 px-4 pb-3">
            <li v-for="file in result.files" :key="file.url">
                <a :href="file.url" class="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1 text-[12.5px] hover:bg-subtle" rel="noopener">
                    <FileText class="size-3.5" aria-hidden="true" />{{ file.name }}
                </a>
            </li>
        </ul>
        <p v-if="result.reject_reason" class="px-4 pb-3 text-[12.5px] text-muted">{{ t('compliance.results.rejected_because', { reason: result.reject_reason }) }}</p>
        <footer v-if="result.can.decide" class="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-2.5">
            <AppButton size="sm" variant="ghost" class="text-bad" :icon="XCircle" :disabled="busy" @click="decide('reject')">{{ t('compliance.results.reject') }}</AppButton>
            <AppButton size="sm" variant="primary" :icon="CheckCircle2" :loading="busy" @click="decide('apply')">{{ t('compliance.results.apply') }}</AppButton>
        </footer>
    </article>
</template>
