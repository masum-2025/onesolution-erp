<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Check, PenLine, Printer, Send, Trash2, Undo2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization, session } from '@/lib/session';
import { visibleOrganizations } from '@/lib/organizations';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { statusTone, todayIn } from '../lib';

/**
 * One journal entry with its lines and the steps the reader may take now
 * (from the server's "can"; the server checks again). Approving asks once
 * more; rejecting and reversing need a reason.
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();
const router = useRouter();

const entry = useResource(() => books.journal(route.params.id));
const journal = computed(() => entry.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: journal.value?.currency });
watch(() => route.params.id, (id) => id && entry.reload());

const units = ref(new Map());
visibleOrganizations()
    .then((list) => (units.value = new Map(list.map((unit) => [unit.id, unit.display_name]))))
    .catch(() => {});
const unitName = (id) => (id === org.id ? '' : (units.value.get(id) ?? ''));

const busy = ref(null);
const reverseOpen = ref(false);
const reverse = ref({ entry_date: '', reason: '' });
const reverseErrors = ref({});

async function run(step, body = {}) {
    busy.value = step;
    try {
        const { data } = await books.step(journal.value.id, step, { base_version: journal.value.version, ...body });
        toast.success(t(`accounting.journal.done.${step}`));
        if (data.id !== journal.value.id) router.push({ name: 'accounting-journal', params: { id: data.id } });
        else entry.data.value = { data };
        return true;
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('accounting.journal.conflict'));
            entry.reload();
            return true;
        }
        if (error.errors) return error.errors;
        toast.error(error.message);
        return true;
    } finally {
        busy.value = null;
    }
}

async function approve() {
    const confirmed = await confirmAction({
        title: t('accounting.journal.approve_title'),
        message: t('accounting.journal.approve_text', { number: journal.value.number ?? '', amount: money(journal.value.total_minor) }),
        confirmLabel: t('accounting.journal.steps.approve'),
    });
    if (confirmed) run('approve');
}

async function reject() {
    const confirmed = await confirmAction({
        title: t('accounting.journal.reject_title'),
        message: t('accounting.journal.reject_text'),
        confirmLabel: t('accounting.journal.steps.reject'),
        reason: 'required',
        danger: true,
    });
    if (confirmed) run('reject', { reason: confirmed.reason });
}

function openReverse() {
    reverse.value = { entry_date: todayIn(session.me?.context?.settings?.timezone), reason: '' };
    reverseErrors.value = {};
    reverseOpen.value = true;
}

async function submitReverse() {
    const result = await run('reverse', { entry_date: reverse.value.entry_date, reason: reverse.value.reason.trim() });
    if (result === true) reverseOpen.value = false;
    else reverseErrors.value = result;
}

/** The browser's print dialog (the page has a print layout). */
function printPage() {
    window.print();
}

async function remove() {
    const confirmed = await confirmAction({
        title: t('accounting.journal.delete_title'),
        message: t('accounting.journal.delete_text'),
        confirmLabel: t('accounting.journal.delete'),
        danger: true,
    });
    if (!confirmed) return;
    try {
        await books.deleteJournal(journal.value.id, journal.value.version);
        toast.success(t('accounting.journal.deleted'));
        router.push({ name: 'accounting' });
    } catch (error) {
        toast.error(error.message);
        entry.reload();
    }
}
</script>

<template>
    <div>
        <PageHeader :title="journal?.narration ?? t('accounting.journal.entry')" :description="journal ? (journal.number ?? t('accounting.list.no_number')) : ''">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'accounting' }" :icon="ArrowLeft">{{ t('accounting.journal.back') }}</AppButton>
                <AppButton v-if="journal?.status === 'posted'" variant="ghost" :icon="Printer" class="print:hidden" @click="printPage">{{ t('accounting.reports.print') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="entry.loading.value && !journal" class="card"><SkeletonRows :rows="6" /></section>
        <section v-else-if="entry.error.value" class="card"><ErrorState compact :error="entry.error.value" @retry="entry.reload()" /></section>

        <div v-else-if="journal" class="grid gap-5">
            <section class="card grid gap-4 p-5 sm:grid-cols-4">
                <div>
                    <div class="text-[12px] text-muted">{{ t('accounting.journal.status') }}</div>
                    <AppBadge :tone="statusTone(journal.status)" dot class="mt-1">{{ t(`accounting.statuses.${journal.status}`) }}</AppBadge>
                </div>
                <div>
                    <div class="text-[12px] text-muted">{{ t('accounting.journal.date') }}</div>
                    <div class="mt-1 text-[14px] font-medium">{{ formatDate(journal.entry_date) }}</div>
                </div>
                <div>
                    <div class="text-[12px] text-muted">{{ t('accounting.journal.total') }}</div>
                    <div class="tabular mt-1 text-[14px] font-semibold">{{ money(journal.total_minor) }}</div>
                </div>
                <div v-if="journal.source">
                    <div class="text-[12px] text-muted">{{ t('accounting.journal.source') }}</div>
                    <div class="mt-1 text-[14px]">{{ journal.source.module }}</div>
                </div>

                <p v-if="journal.posted_at" class="text-[12.5px] text-muted sm:col-span-4">{{ t('accounting.journal.posted_at', { date: formatDateTime(journal.posted_at) }) }}</p>
                <p v-if="journal.status === 'pending_approval'" class="rounded-lg bg-warn-soft px-3 py-2 text-[13px] text-warn sm:col-span-4">{{ t('accounting.journal.waiting_note') }}</p>
                <p v-if="journal.status === 'rejected' && journal.reject_reason" class="rounded-lg bg-bad-soft px-3 py-2 text-[13px] text-bad sm:col-span-4">
                    {{ t('accounting.journal.rejected', { reason: journal.reject_reason }) }}
                </p>
                <p v-if="journal.reverses_id" class="text-[13px] sm:col-span-4">
                    <RouterLink class="font-medium text-brand-strong hover:underline" :to="{ name: 'accounting-journal', params: { id: journal.reverses_id } }">{{ t('accounting.journal.open_original') }}</RouterLink>
                </p>
                <p v-if="journal.reversed_by_id" class="text-[13px] sm:col-span-4">
                    {{ t('accounting.journal.reversed_by') }} ·
                    <RouterLink class="font-medium text-brand-strong hover:underline" :to="{ name: 'accounting-journal', params: { id: journal.reversed_by_id } }">{{ t('accounting.journal.open_reversal') }}</RouterLink>
                </p>
            </section>

            <section class="card overflow-x-auto">
                <table class="w-full min-w-[36rem] text-[13.5px]">
                    <thead class="border-b border-line text-start text-[12px] text-muted">
                        <tr>
                            <th class="px-5 py-2.5 text-start font-medium">{{ t('accounting.form.account') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('accounting.form.cost_centre') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('accounting.form.debit') }}</th>
                            <th class="px-5 py-2.5 text-end font-medium">{{ t('accounting.form.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="line in journal.lines" :key="line.line_no">
                            <td class="px-5 py-2.5">
                                <span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ line.account_code }}</span> {{ line.account_name }}
                                <span v-if="line.memo" class="block text-[12px] text-muted">{{ line.memo }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-muted">{{ unitName(line.cost_centre_id) }}</td>
                            <td class="tabular px-3 py-2.5 text-end">{{ line.debit_minor ? money(line.debit_minor) : '' }}</td>
                            <td class="tabular px-5 py-2.5 text-end">{{ line.credit_minor ? money(line.credit_minor) : '' }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-line font-semibold">
                        <tr>
                            <td class="px-5 py-2.5" colspan="2">{{ t('accounting.form.total') }}</td>
                            <td class="tabular px-3 py-2.5 text-end">{{ money(journal.total_minor) }}</td>
                            <td class="tabular px-5 py-2.5 text-end">{{ money(journal.total_minor) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <div class="flex flex-wrap justify-end gap-2 print:hidden">
                <AppButton v-if="journal.can.edit" variant="danger-soft" :icon="Trash2" @click="remove">{{ t('accounting.journal.delete') }}</AppButton>
                <AppButton v-if="journal.can.edit" :icon="PenLine" :to="{ name: 'accounting-journal-edit', params: { id: journal.id } }">{{ t('accounting.journal.edit') }}</AppButton>
                <AppButton v-if="journal.can.submit" variant="primary" :icon="Send" :loading="busy === 'submit'" @click="run('submit')">{{ t('accounting.journal.steps.submit') }}</AppButton>
                <AppButton v-if="journal.can.withdraw" :icon="Undo2" :loading="busy === 'withdraw'" @click="run('withdraw')">{{ t('accounting.journal.steps.withdraw') }}</AppButton>
                <AppButton v-if="journal.can.reject" variant="danger-soft" :icon="X" :loading="busy === 'reject'" @click="reject">{{ t('accounting.journal.steps.reject') }}</AppButton>
                <AppButton v-if="journal.can.approve" variant="primary" :icon="Check" :loading="busy === 'approve'" @click="approve">{{ t('accounting.journal.steps.approve') }}</AppButton>
                <AppButton v-if="journal.can.reverse" :icon="Undo2" @click="openReverse">{{ t('accounting.journal.steps.reverse') }}</AppButton>
            </div>
        </div>

        <AppDialog :open="reverseOpen" :title="t('accounting.journal.reverse_title')" :description="t('accounting.journal.reverse_text')" tone="warn" @close="reverseOpen = false">
            <form id="accounting-reverse" class="grid gap-4" novalidate @submit.prevent="submitReverse">
                <AppField v-slot="{ id }" :label="t('accounting.journal.reverse_date')" :error="reverseErrors.entry_date?.[0] ?? null">
                    <input :id="id" v-model="reverse.entry_date" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.journal.reason')" :error="reverseErrors.reason?.[0] ?? null">
                    <textarea :id="id" v-model="reverse.reason" class="field-input min-h-20" maxlength="300" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="reverseOpen = false">{{ t('accounting.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="accounting-reverse" :loading="busy === 'reverse'" :disabled="reverse.reason.trim().length < 5">{{ t('accounting.journal.steps.reverse') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
