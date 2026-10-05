<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Banknote, CheckCheck, Lock } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { posApi } from '../api';
import { amountToMinor, shiftTone } from '../lib';

/**
 * One shift and its Z report: takings by method, sales and returns, VAT,
 * the cash the drawer should hold. Close it with the cash counted (the
 * difference shows as it is typed); a supervisor reviews a difference
 * beyond the rule.
 */
const pos = posApi(currentOrganization().id);
const route = useRoute();
const record = useResource(() => pos.shift(route.params.id));
const shift = computed(() => record.data.value?.data ?? null);
const report = computed(() => shift.value?.report ?? null);
const money = (amount) => formatMoney({ amount, currency: shift.value?.currency });
const registers = useResource(() => pos.registers());
const registerName = computed(() => (registers.data.value?.data ?? []).find((row) => row.id === shift.value?.register_id)?.name ?? '');

const closing = ref(false);
const counted = ref('');
const note = ref('');
const countedMinor = computed(() => amountToMinor(counted.value, shift.value?.currency));
const difference = computed(() => (countedMinor.value === null ? null : countedMinor.value - (report.value?.expected_cash_minor ?? 0)));
const busy = ref(false);
async function close() {
    busy.value = true;
    try {
        const { data } = await pos.closeShift(shift.value.id, { base_version: shift.value.version, counted_cash_minor: countedMinor.value, note: note.value.trim() || null });
        toast.success(t(data.status === 'closed' ? 'pos.shift.closed' : 'pos.shift.waiting'));
        closing.value = false;
        record.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}

const reviewing = ref(false);
const reviewNote = ref('');
async function review() {
    busy.value = true;
    try {
        await pos.reviewShift(shift.value.id, { base_version: shift.value.version, note: reviewNote.value.trim() });
        toast.success(t('pos.shift.reviewed'));
        reviewing.value = false;
        record.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <AppButton variant="ghost" size="sm" :to="{ name: 'pos-shifts' }" :icon="ArrowLeft" class="mb-3">{{ t('pos.shifts.title') }}</AppButton>
        <SkeletonRows v-if="record.loading.value && !shift" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="shift">
            <PageHeader :title="registerName" :description="t('pos.shift.text', { from: formatDateTime(shift.opened_at), to: shift.closed_at ? formatDateTime(shift.closed_at) : t('pos.shift.now') })">
                <template #eyebrow><span class="mb-2 flex"><AppBadge :tone="shiftTone(shift.status)" dot>{{ t(`pos.shift_status.${shift.status}`) }}</AppBadge></span></template>
                <template #actions>
                    <AppButton v-if="shift.can.close" variant="primary" :icon="Lock" @click="counted = ''; note = ''; closing = true">{{ t('pos.shift.close') }}</AppButton>
                    <AppButton v-if="shift.can.review" variant="primary" :icon="CheckCheck" @click="reviewNote = ''; reviewing = true">{{ t('pos.shift.review') }}</AppButton>
                </template>
            </PageHeader>

            <section class="card mb-5 grid grid-cols-2 gap-4 p-5 sm:grid-cols-4">
                <div><p class="text-[12.5px] text-muted">{{ t('pos.shift.sales') }}</p><p class="tabular text-[18px] font-semibold">{{ money(report.sales_minor) }}</p><p class="text-[12px] text-muted">{{ t('pos.shift.count', { count: formatNumber(report.sales) }) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('pos.shift.returns') }}</p><p class="tabular text-[18px] font-semibold">{{ money(report.returns_minor) }}</p><p class="text-[12px] text-muted">{{ t('pos.shift.count', { count: formatNumber(report.returns) }) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('pos.till.discounts') }}</p><p class="tabular text-[18px] font-semibold">{{ money(report.discount_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('pos.till.vat') }}</p><p class="tabular text-[18px] font-semibold">{{ money(report.tax_minor) }}</p></div>
            </section>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3 text-[14.5px] font-semibold">{{ t('pos.shift.by_method') }}</h2>
                    <dl class="grid grid-cols-[1fr_auto] gap-y-2 p-5 text-[14px]">
                        <template v-for="(amount, method) in report.methods" :key="method"><dt>{{ t(`pos.methods.${method}`) }}</dt><dd class="tabular text-end font-medium">{{ money(amount) }}</dd></template>
                    </dl>
                </section>
                <section class="card">
                    <h2 class="flex items-center gap-2 border-b border-line px-5 py-3 text-[14.5px] font-semibold"><Banknote class="size-4 text-muted" aria-hidden="true" />{{ t('pos.shift.drawer') }}</h2>
                    <dl class="grid grid-cols-[1fr_auto] gap-y-2 p-5 text-[14px]">
                        <dt class="text-muted">{{ t('pos.shift.float') }}</dt><dd class="tabular text-end">{{ money(shift.opening_float_minor) }}</dd>
                        <dt class="text-muted">{{ t('pos.shift.expected') }}</dt><dd class="tabular text-end font-semibold">{{ money(report.expected_cash_minor) }}</dd>
                        <template v-if="shift.counted_cash_minor !== null">
                            <dt class="text-muted">{{ t('pos.shift.counted') }}</dt><dd class="tabular text-end">{{ money(shift.counted_cash_minor) }}</dd>
                            <dt class="text-muted">{{ t('pos.shift.difference') }}</dt><dd class="tabular text-end font-semibold" :class="shift.variance_minor < 0 ? 'text-bad' : shift.variance_minor > 0 ? 'text-ok' : ''">{{ money(shift.variance_minor) }}</dd>
                        </template>
                    </dl>
                    <p v-if="shift.close_note" class="border-t border-line px-5 py-3 text-[12.5px] text-muted">{{ shift.close_note }}</p>
                    <p v-if="shift.review_note" class="border-t border-line px-5 py-3 text-[12.5px] text-muted">{{ t('pos.shift.review_note', { note: shift.review_note }) }}</p>
                </section>
            </div>
        </template>

        <AppDialog :open="closing" :title="t('pos.shift.close')" :description="t('pos.shift.close_text')" :icon="Lock" @close="closing = false">
            <form id="pos-close" class="grid gap-4" novalidate @submit.prevent="close">
                <AppField v-slot="{ id }" :label="t('pos.shift.counted')">
                    <input :id="id" v-model="counted" autofocus inputmode="decimal" class="field-input h-12 tabular text-end text-[18px]" dir="ltr" placeholder="0.00" />
                </AppField>
                <div class="grid grid-cols-2 gap-3 text-center">
                    <div class="rounded-xl bg-subtle px-3 py-2.5"><p class="text-[12px] text-muted">{{ t('pos.shift.expected') }}</p><p class="tabular text-[16px] font-semibold">{{ money(report?.expected_cash_minor ?? 0) }}</p></div>
                    <div class="rounded-xl px-3 py-2.5" :class="difference === 0 ? 'bg-ok-soft' : difference === null ? 'bg-subtle' : 'bg-warn-soft'">
                        <p class="text-[12px] text-muted">{{ t('pos.shift.difference') }}</p><p class="tabular text-[16px] font-semibold">{{ difference === null ? '—' : money(difference) }}</p>
                    </div>
                </div>
                <AppField v-slot="{ id }" :label="t('pos.shift.note')" optional>
                    <input :id="id" v-model="note" class="field-input" maxlength="500" :placeholder="t('pos.shift.note_hint')" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="closing = false">{{ t('pos.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="pos-close" :loading="busy" :disabled="countedMinor === null">{{ t('pos.shift.close') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="reviewing" :title="t('pos.shift.review')" :description="t('pos.shift.review_text', { amount: shift ? money(shift.variance_minor ?? 0) : '' })" :icon="CheckCheck" @close="reviewing = false">
            <form id="pos-review" novalidate @submit.prevent="review">
                <AppField v-slot="{ id }" :label="t('pos.shift.what_happened')">
                    <input :id="id" v-model="reviewNote" class="field-input" maxlength="500" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="reviewing = false">{{ t('pos.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="pos-review" :loading="busy" :disabled="reviewNote.trim().length < 5">{{ t('pos.shift.review') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
