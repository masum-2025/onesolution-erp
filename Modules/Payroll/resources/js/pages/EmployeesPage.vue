<script setup>
import { computed, ref, watch } from 'vue';
import { Layers, Search, Tags, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';

/** Who is paid what today: basic salary, structure and how they are paid (no account numbers here). */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const search = ref('');
const list = useResource(() => payroll.employees(search.value.trim()));
const employees = computed(() => list.data.value?.data ?? []);
const currency = computed(() => list.data.value?.meta?.currency);
const missing = computed(() => employees.value.filter((employee) => employee.basic_minor === null).length);

let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => list.reload(), 300);
});
</script>

<template>
    <div>
        <PageHeader :title="t('payroll.employees.title')" :description="t('payroll.employees.text')">
            <template #actions>
                <AppButton v-if="can('payroll.view')" variant="ghost" :icon="Tags" :to="{ name: 'payroll-components' }">{{ t('payroll.components.title') }}</AppButton>
                <AppButton v-if="can('payroll.view')" variant="ghost" :icon="Layers" :to="{ name: 'payroll-structures' }">{{ t('payroll.structures.title') }}</AppButton>
            </template>
        </PageHeader>

        <div class="relative mb-4 max-w-sm">
            <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
            <input v-model="search" type="search" class="field-input ps-9" :placeholder="t('payroll.employees.search')" :aria-label="t('payroll.employees.search')" />
        </div>

        <p v-if="missing" class="mb-4 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="status">{{ t('payroll.employees.missing', { count: formatNumber(missing) }) }}</p>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!employees.length" :icon="Users" :title="t(search ? 'payroll.employees.none_found' : 'payroll.employees.empty')" :text="t('payroll.employees.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="employee in employees" :key="employee.id">
                    <RouterLink :to="{ name: 'payroll-employee', params: { id: employee.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ employee.name }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ employee.code }}</span></span>
                            <span class="block text-[12.5px] text-muted">
                                {{ employee.structure ?? t('payroll.employees.no_structure') }}
                                · {{ employee.payment_method ? t(`payroll.methods.${employee.payment_method}`) : t('payroll.employees.no_payment') }}
                            </span>
                        </span>
                        <AppBadge v-if="employee.status !== 'active'" tone="neutral">{{ t(`payroll.employees.status.${employee.status}`) }}</AppBadge>
                        <span v-if="employee.basic_minor !== null" class="tabular w-32 text-end text-[14px] font-semibold">{{ formatMoney({ amount: employee.basic_minor, currency }) }}</span>
                        <AppBadge v-else tone="warn" dot>{{ t('payroll.employees.no_salary') }}</AppBadge>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
