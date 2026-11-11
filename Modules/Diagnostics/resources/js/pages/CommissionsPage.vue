<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { HandCoins } from 'lucide-vue-next';
import { AppButton, AppField, DateInput, EmptyState, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { labApi } from '../api';

/**
 * Referring doctors' commission for a period (this month at first): what
 * each earned in it and what each is owed now, largest owed first. A row
 * opens the doctor's account to pay or take a line back.
 */
const lab = labApi(currentOrganization().id);
const router = useRouter();
const from = ref('');
const to = ref('');
const list = useResource(() => lab.commissions({ from: from.value || undefined, to: to.value || undefined }));
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});
const money = (amount) => formatMoney({ amount, currency: meta.value.currency });
const apply = () => list.reload();
const open = (row) => router.push({ name: 'diagnostics-referrer-account', params: { id: row.id }, query: { from: meta.value.from, to: meta.value.to } });

const exported = computed(() => ({
    module: 'diagnostics', page: 'diagnostics.commissions', rows: rows.value,
    columns: [
        { key: 'name', label: t('diagnostics.commissions.doctor') },
        { key: 'registration_no', label: t('diagnostics.commissions.registration') },
        { key: 'commission_percent', label: t('diagnostics.commissions.percent'), value: (row) => row.commission_percent ?? meta.value.percent },
        { key: 'earned_minor', label: t('diagnostics.commissions.earned'), type: 'money', currency: meta.value.currency },
        { key: 'owed_minor', label: t('diagnostics.commissions.owed'), type: 'money', currency: meta.value.currency },
    ],
}));
</script>

<template>
    <PageFrame :title="t('diagnostics.commissions.title')" :description="t('diagnostics.commissions.text')" width="lg" :resource="list" :export="exported" guide="diagnostics.guide.commission"
               :guide-settings="{ name: 'module-settings', params: { module: 'diagnostics' } }">
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <AppField v-slot="{ id }" :label="t('diagnostics.commissions.from')"><DateInput :id="id" v-model="from" /></AppField>
            <AppField v-slot="{ id }" :label="t('diagnostics.commissions.to')"><DateInput :id="id" v-model="to" :min="from" /></AppField>
            <AppButton type="submit">{{ t('diagnostics.commissions.show') }}</AppButton>
        </form>
        <div class="mb-4 grid grid-cols-2 gap-3 sm:max-w-md">
            <div class="card px-4 py-3"><p class="text-[12px] text-muted">{{ t('diagnostics.commissions.earned') }}</p><p class="tabular text-[17px] font-semibold">{{ money(meta.earned_minor ?? 0) }}</p></div>
            <div class="card px-4 py-3"><p class="text-[12px] text-muted">{{ t('diagnostics.commissions.owed') }}</p><p class="tabular text-[17px] font-semibold">{{ money(meta.owed_minor ?? 0) }}</p></div>
        </div>
        <EmptyState v-if="!rows.length" :icon="HandCoins" :title="t('diagnostics.commissions.empty')" :text="t('diagnostics.commissions.empty_text', { percent: meta.percent ?? 0 })" compact />
        <div v-else class="card overflow-x-auto">
            <table class="w-full text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-[12px] text-muted">
                        <th class="px-4 py-2 text-start font-medium">{{ t('diagnostics.commissions.doctor') }}</th>
                        <th class="px-4 py-2 text-end font-medium">{{ t('diagnostics.commissions.percent') }}</th>
                        <th class="px-4 py-2 text-end font-medium">{{ t('diagnostics.commissions.earned') }}</th>
                        <th class="px-4 py-2 text-end font-medium">{{ t('diagnostics.commissions.owed') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="row in rows" :key="row.id" class="cursor-pointer hover:bg-subtle" tabindex="0" @click="open(row)" @keydown.enter="open(row)">
                        <td class="px-4 py-2.5"><span class="font-medium">{{ row.name }}</span><span v-if="row.registration_no" class="ms-2 font-mono text-[12px] text-muted" dir="ltr">{{ row.registration_no }}</span></td>
                        <td class="px-4 py-2.5 text-end tabular">{{ row.commission_percent ?? meta.percent }}%<span v-if="row.commission_percent === null" class="ms-1 text-[11px] text-muted">({{ t('diagnostics.commissions.usual') }})</span></td>
                        <td class="px-4 py-2.5 text-end tabular">{{ money(row.earned_minor) }}</td>
                        <td class="px-4 py-2.5 text-end tabular font-semibold">{{ money(row.owed_minor) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PageFrame>
</template>
