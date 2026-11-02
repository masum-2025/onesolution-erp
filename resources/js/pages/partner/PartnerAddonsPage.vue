<script setup>
import { computed, ref, watch } from 'vue';
import { PackagePlus } from 'lucide-vue-next';
import { PageFrame, AppButton, AppField, MoneyInput, StatusBadge, EmptyState, confirmAction, newOpId, toast } from '@/ui';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Partner console: add-on prices for the partner's clients (its own, or the
 * platform's), what a wholesale partner pays us, and one client's add-ons,
 * started or cancelled for it (when clients do not buy themselves).
 */
const prices = useResource(() => api('/api/partner/addons'));
const rows = computed(() => prices.data.value?.data ?? []);
const canEdit = computed(() => !!prices.data.value?.can_edit);
const drafts = ref({});
const saving = ref(null);

const money = (amount, currency) => formatMoney({ amount, currency });
const draftKey = (module, currency) => `${module}:${currency}`;

watch(rows, (list) => {
    drafts.value = Object.fromEntries(list.flatMap((row) => row.prices.map((price) => [draftKey(row.key, price.currency), price.own_minor])));
});

async function savePrice(row, price, amount) {
    const answer = await confirmAction({
        title: t('addons.partner.save_title', { name: row.name, currency: price.currency }),
        message: amount === null ? t('addons.partner.reset_text', { price: money(price.platform_minor, price.currency) }) : t('addons.partner.save_text', { price: money(amount, price.currency) }),
        reason: 'required',
        confirmLabel: t('addons.partner.save'),
    });
    if (!answer) return;
    saving.value = draftKey(row.key, price.currency);
    try {
        const response = await api(`/api/partner/addons/${row.key}`, { method: 'PUT', body: { currency: price.currency, amount_minor: amount, reason: answer.reason } });
        toast.success(response.message);
        prices.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = null;
    }
}

// One client's add-ons.
const clients = useResource(() => api('/api/partner/organizations', { query: { per_page: 100 } }));
const clientId = ref('');
const clientStore = ref(null);
const clientBusy = ref(null);

watch(clientId, async (id) => {
    clientStore.value = null;
    if (!id) return;
    try {
        clientStore.value = (await api(`/api/partner/clients/${id}/addons`)).data;
    } catch (error) {
        toast.error(error.message);
    }
});

const clientItems = computed(() => (clientStore.value?.items ?? []).filter((item) => ['for_sale', 'trial', 'active'].includes(item.status)));
const tones = { trial: 'brand', active: 'ok', for_sale: 'outline' };

async function clientAct(item, action) {
    const name = (clients.data.value?.data ?? []).find((org) => org.id === clientId.value)?.display_name ?? '';
    const answer = await confirmAction({
        title: t(action === 'buy' ? 'addons.partner.buy_title' : 'addons.confirm.cancel_title', { name: item.name, client: name }),
        message: action === 'buy' ? t('addons.partner.buy_text', { client: name }) : t('addons.confirm.cancel_text', { date: formatDate(item.period_end) }),
        danger: action === 'cancel',
        confirmLabel: t(action === 'buy' ? 'addons.actions.buy' : 'addons.actions.cancel'),
    });
    if (!answer) return;
    clientBusy.value = item.key;
    try {
        const response = await api(`/api/partner/clients/${clientId.value}/addons/${item.key}/${action}`, { method: 'POST', body: action === 'buy' ? { op_id: newOpId() } : {} });
        clientStore.value = response.data;
        toast.success(response.message);
    } catch (error) {
        toast.error(error.message);
    } finally {
        clientBusy.value = null;
    }
}
</script>

<template>
    <PageFrame :title="t('addons.partner.title')" :description="t('addons.partner.text')" width="xl" :resource="prices"
               :empty="{ when: !rows.length, icon: PackagePlus, title: t('addons.partner.empty_title'), text: t('addons.partner.empty_text') }">
        <div class="space-y-6">
            <div class="overflow-x-auto rounded-xl border border-line bg-surface">
                <table class="w-full text-[14px]">
                    <thead class="bg-surface-2 text-[12px] text-muted">
                        <tr>
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('addons.partner.columns.module') }}</th>
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('addons.partner.columns.platform') }}</th>
                            <th class="px-4 py-2.5 text-start font-medium">{{ t('addons.partner.columns.own') }}</th>
                            <th v-if="rows.some((row) => row.wholesale)" class="px-4 py-2.5 text-start font-medium">{{ t('addons.partner.columns.wholesale') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.key" class="border-t border-line align-top">
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ row.name }}</span>
                                <span class="block text-[12px] text-muted">{{ row.description }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-for="price in row.prices" :key="price.currency" class="block">{{ money(price.platform_minor, price.currency) }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-for="price in row.prices" :key="price.currency" class="mb-2 flex flex-wrap items-end gap-2">
                                    <AppField :label="price.currency" class="w-36">
                                        <template #default="{ id }">
                                            <MoneyInput :id="id" v-model="drafts[draftKey(row.key, price.currency)]" :currency="price.currency" :placeholder="t('addons.partner.uses_platform')" :disabled="!canEdit" />
                                        </template>
                                    </AppField>
                                    <template v-if="canEdit">
                                        <AppButton size="sm" :loading="saving === draftKey(row.key, price.currency)"
                                                   :disabled="drafts[draftKey(row.key, price.currency)] === null || drafts[draftKey(row.key, price.currency)] === price.own_minor"
                                                   @click="savePrice(row, price, drafts[draftKey(row.key, price.currency)])">{{ t('addons.partner.save') }}</AppButton>
                                        <AppButton v-if="price.own_minor !== null" size="sm" variant="ghost" @click="savePrice(row, price, null)">{{ t('addons.partner.reset') }}</AppButton>
                                    </template>
                                </div>
                            </td>
                            <td v-if="rows.some((item) => item.wholesale)" class="px-4 py-3">
                                {{ row.wholesale?.amount_minor == null ? t('addons.partner.no_wholesale') : money(row.wholesale.amount_minor, row.wholesale.currency) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <section class="rounded-xl border border-line bg-surface p-4">
                <h2 class="text-[15px] font-semibold">{{ t('addons.partner.client_title') }}</h2>
                <p class="mt-1 text-[13px] text-muted">{{ t('addons.partner.client_text') }}</p>
                <AppField :label="t('addons.partner.client')" class="mt-3 max-w-sm">
                    <template #default="{ id }">
                        <select :id="id" v-model="clientId" class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-[14px]">
                            <option value="">{{ t('addons.partner.pick_client') }}</option>
                            <option v-for="org in clients.data.value?.data ?? []" :key="org.id" :value="org.id">{{ org.display_name }}</option>
                        </select>
                    </template>
                </AppField>
                <ul v-if="clientStore" class="mt-4 divide-y divide-line">
                    <li v-for="item in clientItems" :key="item.key" class="flex flex-wrap items-center gap-3 py-2.5">
                        <span class="font-medium">{{ item.name }}</span>
                        <StatusBadge :status="item.status" :tones="tones">{{ t(`addons.status.${item.status}`) }}</StatusBadge>
                        <span v-if="item.cancel_at_period_end" class="text-[13px] text-muted">{{ t('addons.ends_on', { date: formatDate(item.period_end) }) }}</span>
                        <span class="ms-auto" />
                        <AppButton v-if="canEdit && item.status !== 'active'" size="sm" :loading="clientBusy === item.key" @click="clientAct(item, 'buy')">{{ t('addons.actions.buy') }}</AppButton>
                        <AppButton v-else-if="canEdit && !item.cancel_at_period_end" size="sm" variant="ghost" :loading="clientBusy === item.key" @click="clientAct(item, 'cancel')">{{ t('addons.actions.cancel') }}</AppButton>
                    </li>
                    <li v-if="!clientItems.length" class="py-3 text-[13px] text-muted">{{ t('addons.partner.client_nothing') }}</li>
                </ul>
            </section>
        </div>
    </PageFrame>
</template>
