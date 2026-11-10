<script setup>
import { computed, ref } from 'vue';
import { ClipboardCheck, Lock, LockOpen, Wallet } from 'lucide-vue-next';
import { AppButton, AppField, EmptyState, MoneyInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { billingApi, money } from '../api';

/**
 * The cashier's day: open it with the float, take cash, then count and
 * close. A difference over the limit waits for a supervisor, who sees every
 * cashier's days here.
 */
const pb = billingApi(currentOrganization().id);
const view = useResource(() => pb.cashDays());
const data = computed(() => view.data.value?.data ?? null);
const current = computed(() => data.value?.current ?? null);
const days = computed(() => data.value?.days ?? []);
const tones = { open: 'info', review: 'warn', closed: 'ok' };
const m = (amount, currency) => formatMoney(money(amount, currency));
const busy = ref(false);

const float = ref(null);
async function openDay() {
    busy.value = true;
    try {
        await pb.openDay({ opening_float_minor: float.value ?? 0 });
        toast.success(t('patient_billing.cash.opened'));
        float.value = null;
        view.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}

const closing = ref(false);
const counted = ref(null);
async function closeDay() {
    busy.value = true;
    try {
        const day = (await pb.closeDay(current.value.id, { counted_minor: counted.value ?? 0, base_version: current.value.version })).data;
        toast.success(day.status === 'closed' ? t('patient_billing.cash.closed') : t('patient_billing.cash.sent_for_review'));
        closing.value = false;
        counted.value = null;
        view.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}

const reviewing = ref(null);
const note = ref('');
async function review() {
    try {
        await pb.reviewDay(reviewing.value.id, { note: note.value.trim() });
        toast.success(t('patient_billing.cash.accepted'));
        reviewing.value = null;
        note.value = '';
        view.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <PageFrame :title="t('patient_billing.cash.title')" :description="t('patient_billing.cash.text')" width="lg" :resource="view" guide="patient_billing.guide.cash"
               :guide-settings="{ name: 'module-settings', params: { module: 'patient_billing' } }">
        <template v-if="data">
            <section class="card mb-4 p-5">
                <template v-if="current">
                    <p class="text-[13px] text-muted">{{ t('patient_billing.cash.open_since', { time: formatDateTime(current.opened_at) }) }}</p>
                    <p class="mt-1 text-[22px] font-semibold tabular">{{ m(current.expected_minor, current.currency) }}</p>
                    <p class="text-[12.5px] text-muted">{{ t('patient_billing.cash.expected_hint', { float: m(current.opening_float_minor, current.currency) }) }}</p>
                    <AppButton class="mt-3" variant="primary" :icon="Lock" @click="closing = true">{{ t('patient_billing.cash.close') }}</AppButton>
                </template>
                <form v-else class="grid gap-3 sm:grid-cols-[minmax(0,16rem)_auto] sm:items-end" @submit.prevent="openDay">
                    <AppField v-slot="{ id }" :label="t('patient_billing.cash.float')" :hint="t('patient_billing.cash.float_hint')"><MoneyInput :id="id" v-model="float" /></AppField>
                    <AppButton type="submit" variant="primary" :icon="LockOpen" :loading="busy">{{ t('patient_billing.cash.open') }}</AppButton>
                </form>
            </section>
            <section class="card">
                <EmptyState v-if="!days.length" :icon="Wallet" :title="t('patient_billing.cash.empty')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="day in days" :key="day.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-[13.5px]">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ day.user_name ?? '—' }} · {{ formatDateTime(day.opened_at) }}</span>
                            <span class="block text-[12.5px] text-muted tabular">
                                {{ t('patient_billing.cash.expected') }} {{ m(day.expected_minor, day.currency) }}<template v-if="day.counted_minor !== null"> · {{ t('patient_billing.cash.counted') }} {{ m(day.counted_minor, day.currency) }}</template><template v-if="day.variance_minor"> · <strong :class="day.variance_minor < 0 ? 'text-bad' : 'text-ok'">{{ t('patient_billing.cash.difference') }} {{ m(day.variance_minor, day.currency) }}</strong></template>
                            </span>
                            <span v-if="day.review_note" class="block text-[12px] text-muted">{{ day.review_note }}</span>
                        </span>
                        <AppBadge :tone="tones[day.status]" dot>{{ t(`patient_billing.cash.status.${day.status}`) }}</AppBadge>
                        <AppButton v-if="day.status === 'review' && data.can_review && !day.mine" size="sm" variant="primary" :icon="ClipboardCheck" @click="reviewing = day">{{ t('patient_billing.cash.review') }}</AppButton>
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="closing" :title="t('patient_billing.cash.close')" :description="current ? t('patient_billing.cash.close_text', { expected: m(current.expected_minor, current.currency), allowed: m(data.variance_allowed_minor, current.currency) }) : ''" :icon="Lock" @close="closing = false">
            <AppField v-slot="{ id }" :label="t('patient_billing.cash.counted')" :hint="t('patient_billing.cash.counted_hint')"><MoneyInput :id="id" v-model="counted" :currency="current?.currency" /></AppField>
            <template #footer>
                <AppButton variant="ghost" @click="closing = false">{{ t('patient_billing.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="counted === null" @click="closeDay">{{ t('patient_billing.cash.close') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="!!reviewing" :title="t('patient_billing.cash.review')" :description="reviewing ? t('patient_billing.cash.review_text', { name: reviewing.user_name ?? '—', difference: m(reviewing.variance_minor, reviewing.currency) }) : ''" :icon="ClipboardCheck" @close="reviewing = null">
            <AppField v-slot="{ id }" :label="t('patient_billing.cash.review_note')"><input :id="id" v-model="note" class="field-input" maxlength="300" /></AppField>
            <template #footer>
                <AppButton variant="ghost" @click="reviewing = null">{{ t('patient_billing.common.cancel') }}</AppButton>
                <AppButton variant="primary" :disabled="note.trim().length < 3" @click="review">{{ t('patient_billing.cash.accept') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
