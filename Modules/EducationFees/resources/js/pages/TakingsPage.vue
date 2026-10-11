<script setup>
import { computed, ref, watch } from 'vue';
import { Banknote, Globe, Landmark, Printer, ReceiptText, Smartphone } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { day } from '../labels';

/**
 * What came in over a day or several: totals by how (cash, bank, mobile,
 * online) and by whom, voided receipts apart, and every receipt; printable
 * to close the counter.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();

const from = ref('');
const to = ref('');
watch(() => setup.today.value, (today) => {
    if (today && !from.value) {
        from.value = today;
        to.value = today;
    }
}, { immediate: true });
const takings = useResource(() => api.takings({ from: from.value || undefined, to: to.value || undefined }), { immediate: false });
watch([from, to], () => from.value && to.value && from.value <= to.value && takings.reload(), { immediate: true });
const data = computed(() => takings.data.value?.data ?? null);
const icons = { cash: Banknote, bank: Landmark, mobile: Smartphone, online: Globe };
const people = computed(() => data.value?.people ?? {});
const printPage = () => window.print();
</script>

<template>
    <div>
        <PageHeader :title="t('education_fees.takings.title')" :description="t('education_fees.takings.text')">
            <template #actions>
                <AppButton class="print:hidden" :icon="Printer" :disabled="!data" @click="printPage">{{ t('education_fees.print') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-5 flex flex-wrap items-end gap-3 print:hidden">
            <AppField v-slot="{ id }" :label="t('education_fees.takings.from')">
                <input :id="id" v-model="from" type="date" class="field-input" :max="to || undefined" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('education_fees.takings.to')">
                <input :id="id" v-model="to" type="date" class="field-input" :min="from || undefined" />
            </AppField>
        </div>

        <SkeletonRows v-if="takings.loading.value && !data" :rows="6" />
        <ErrorState v-else-if="takings.error.value" :error="takings.error.value" @retry="takings.reload()" />
        <template v-else-if="data">
            <p class="mb-3 hidden text-[13px] print:block">{{ day(data.from) }} – {{ day(data.to) }}</p>
            <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="card bg-gradient-to-br from-brand-soft to-transparent p-4 sm:col-span-2 lg:col-span-1">
                    <p class="text-[12px] text-muted">{{ t('education_fees.takings.total') }}</p>
                    <p class="tabular text-[26px] font-semibold text-fg">{{ setup.money(data.total_minor) }}</p>
                    <p class="text-[12px] text-muted">{{ t('education_fees.takings.count', { count: data.receipts.filter((receipt) => receipt.status === 'valid').length }) }}</p>
                </div>
                <div v-for="(amount, method) in data.by_method" :key="method" class="card flex items-center gap-3 p-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-subtle text-fg-2" aria-hidden="true"><component :is="icons[method]" class="size-5" /></span>
                    <div>
                        <p class="text-[12px] text-muted">{{ t(`education_fees.methods.${method}`) }}</p>
                        <p class="tabular text-[17px] font-semibold text-fg">{{ setup.money(amount) }}</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
                <section class="card h-fit">
                    <h2 class="border-b border-line px-5 py-3 text-[13.5px] font-semibold text-fg">{{ t('education_fees.takings.by_person') }}</h2>
                    <p v-if="!Object.keys(data.by_collector).length" class="px-5 py-4 text-[13px] text-muted">{{ t('education_fees.takings.none') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="(amount, person) in data.by_collector" :key="person" class="flex items-center justify-between gap-3 px-5 py-2.5 text-[13.5px]">
                            <span class="truncate">{{ person === 'online' ? t('education_fees.takings.online') : people[person] ?? '—' }}</span>
                            <span class="tabular font-semibold">{{ setup.money(amount) }}</span>
                        </li>
                    </ul>
                    <p v-if="data.voided_minor" class="border-t border-line px-5 py-2.5 text-[12.5px] text-muted">{{ t('education_fees.takings.voided') }}: <span class="tabular">{{ setup.money(data.voided_minor) }}</span></p>
                </section>

                <section class="card min-w-0">
                    <h2 class="border-b border-line px-5 py-3 text-[13.5px] font-semibold text-fg">{{ t('education_fees.takings.receipts') }}</h2>
                    <EmptyState v-if="!data.receipts.length" :icon="ReceiptText" :title="t('education_fees.takings.none')" compact />
                    <ul v-else class="divide-y divide-line">
                        <li v-for="receipt in data.receipts" :key="receipt.id">
                            <RouterLink :to="{ name: 'fees-receipt', params: { id: receipt.id } }" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 transition hover:bg-subtle/60">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13.5px] font-medium text-fg">{{ receipt.student?.name ?? '—' }}</span>
                                    <span class="block text-[12px] text-muted"><span class="font-mono" dir="ltr">{{ receipt.number }}</span> · {{ t(`education_fees.methods.${receipt.method}`) }} · {{ people[receipt.collected_by] ?? t('education_fees.receipt.online') }}</span>
                                </span>
                                <AppBadge v-if="receipt.status === 'voided'" tone="bad">{{ t('education_fees.receipt.voided') }}</AppBadge>
                                <span class="tabular text-[13.5px] font-semibold" :class="receipt.status === 'voided' ? 'text-faint line-through' : 'text-fg'">{{ setup.money(receipt.amount_minor) }}</span>
                            </RouterLink>
                        </li>
                    </ul>
                </section>
            </div>
        </template>
    </div>
</template>
