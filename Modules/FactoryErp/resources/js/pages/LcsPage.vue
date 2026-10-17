<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Landmark, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDate, formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { lcFormToApi, lcToForm, stagePercent } from '../lib';
import LcForm from '../components/LcForm.vue';

/**
 * Buyers' letters of credit: each with its buyer, amount, how much it has
 * paid for (a bar against the amount with its tolerance), the last day to
 * ship and the expiry (soon in amber, past in red); and a new one.
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const router = useRouter();
const status = ref('open');
const statuses = computed(() => [{ value: 'open', label: t('factory_erp.lcs.statuses.open') }, { value: 'closed', label: t('factory_erp.lcs.statuses.closed') }]);
const list = useResource(() => api.lcs({ status: status.value }));
const items = computed(() => list.data.value?.data ?? []);
const buyerName = (id) => list.data.value?.meta?.buyers?.[id]?.name ?? '—';
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const money = (minor, currency) => formatMoney({ amount: minor, currency });
const today = computed(() => setup.data.value?.today ?? '');
const tone = (lc) => (lc.expires_on < today.value ? 'bad' : lc.expires_on <= addDays(today.value, 15) ? 'warn' : 'neutral');
function addDays(date, days) {
    if (!date) return '';
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);
    return value.toISOString().slice(0, 10);
}

const making = ref(false);
const form = ref(lcToForm());
const errors = ref({});
const busy = ref(false);
const documents = ['commercial_invoice', 'packing_list', 'bill_of_lading', 'airway_bill', 'certificate_of_origin', 'beneficiary_certificate', 'bill_of_exchange', 'inspection_certificate', 'insurance'];
function start() {
    form.value = lcToForm();
    errors.value = {};
    making.value = true;
}
const body = computed(() => lcFormToApi(form.value));
async function make() {
    busy.value = true;
    errors.value = {};
    try {
        const { data } = await api.createLc({ buyer_id: form.value.buyer_id, ...body.value, order_ids: [] });
        toast.success(t('factory_erp.lcs.made'));
        router.push({ name: 'fac-lc', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('factory_erp.lcs.title')" :description="t('factory_erp.lcs.text')">
            <template v-if="setup.can('merchandise')" #actions>
                <AppButton variant="primary" :icon="Plus" @click="start">{{ t('factory_erp.lcs.new') }}</AppButton>
            </template>
        </PageHeader>
        <AppSegmented v-model="status" :options="statuses" class="mb-4" @update:model-value="list.reload()" />
        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!items.length" class="card"><EmptyState :icon="Landmark" :title="t('factory_erp.lcs.empty_title')" :text="t('factory_erp.lcs.empty_text')" /></section>
        <ul v-else class="card divide-y divide-line">
            <li v-for="lc in items" :key="lc.id">
                <RouterLink :to="{ name: 'fac-lc', params: { id: lc.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 hover:bg-subtle/60">
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-[14px] font-semibold text-fg" dir="ltr">{{ lc.number }}</p>
                        <p class="text-[12.5px] text-muted">{{ buyerName(lc.buyer_id) }} · {{ lc.issuing_bank }}</p>
                    </div>
                    <div class="w-48">
                        <div class="flex justify-between text-[12px] text-muted"><span>{{ money(lc.usage.shipped, lc.currency) }}</span><span>{{ money(lc.amount_minor, lc.currency) }}</span></div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-subtle" role="presentation"><div class="h-full rounded-full bg-brand" :style="{ inlineSize: `${stagePercent(lc.usage.shipped, lc.amount_minor)}%` }" /></div>
                    </div>
                    <AppBadge :tone="tone(lc)" dot>{{ t('factory_erp.lcs.expires', { date: day(lc.expires_on) }) }}</AppBadge>
                </RouterLink>
            </li>
        </ul>

        <AppDialog :open="making" size="lg" :icon="Landmark" :title="t('factory_erp.lcs.new')" :description="t('factory_erp.lcs.new_text')" @close="making = false">
            <LcForm v-model="form" :documents="documents" making :errors="errors" />
            <template #footer>
                <AppButton variant="ghost" @click="making = false">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!body || !form.buyer_id || !form.number.trim() || !form.issued_on || !form.latest_ship_on || !form.expires_on || !form.issuing_bank.trim()" @click="make">
                    {{ t('factory_erp.save') }}
                </AppButton>
            </template>
        </AppDialog>
    </div>
</template>
