<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, UserX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { hrmApi } from '../api';
import { availableSteps, initials, statusTone } from '../lib';
import DocumentsPanel from '../components/DocumentsPanel.vue';
import HistoryPanel from '../components/HistoryPanel.vue';
import PersonalPanel from '../components/PersonalPanel.vue';
import StepDialog from '../components/StepDialog.vue';

/**
 * One employee: overview, personal details, history and documents, and the
 * employment steps this person may take now (the server checks again).
 */
const org = currentOrganization();
const hrm = hrmApi(org.id);
const route = useRoute();
const router = useRouter();

const record = useResource(() => hrm.employee(route.params.id));
const employee = computed(() => record.data.value?.data ?? null);
const options = useResource(() => (employee.value ? hrm.formOptions(employee.value.unit.id) : Promise.resolve(null)), { immediate: false });
const positions = useResource(() => hrm.positions(true));
const opts = computed(() => options.data.value?.data ?? null);

// Rules and permissions of the employee's own unit, once it is known (again after a transfer).
watch(
    () => employee.value?.unit.id,
    (unitId) => {
        if (unitId) options.reload();
    },
    { immediate: true },
);

const tabs = computed(() => [
    { key: 'overview', label: t('hrm.profile.tabs.overview') },
    { key: 'personal', label: t('hrm.profile.tabs.personal') },
    { key: 'history', label: t('hrm.profile.tabs.history') },
    { key: 'documents', label: t('hrm.profile.tabs.documents') },
]);
const tab = computed({
    get: () => (tabs.value.some((item) => item.key === route.query.tab) ? route.query.tab : 'overview'),
    set: (value) => router.replace({ query: value === 'overview' ? {} : { tab: value } }),
});

const can = computed(() => opts.value?.can ?? { manage: false, exit: false, view_sensitive: false });
const steps = computed(() => availableSteps(employee.value, can.value));
const names = computed(() => ({
    ...Object.fromEntries((positions.data.value?.data ?? []).map((position) => [position.id, position.title])),
    ...(employee.value ? { [employee.value.unit.id]: employee.value.unit.name } : {}),
}));

const openStep = ref(null);
const history = ref(null);

async function stepDone(data) {
    openStep.value = null;
    record.data.value = { data };
    await nextTick();
    history.value?.reload();
}

function reload() {
    openStep.value = null;
    record.reload();
}

const typeLabel = computed(() => opts.value?.employment_types.find((type) => type.value === employee.value?.employment_type)?.label ?? employee.value?.employment_type);
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'hrm' }" :icon="ArrowLeft" class="mb-3">{{ t('hrm.profile.back') }}</AppButton>

        <SkeletonRows v-if="record.loading.value && !employee" :rows="6" avatar />
        <section v-else-if="record.error.value?.status === 404" class="card">
            <EmptyState :icon="UserX" :title="t('hrm.profile.not_found')" />
        </section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="employee">
            <PageHeader :title="employee.full_name" :description="employee.full_name_local ?? ''">
                <template #eyebrow>
                    <span class="mb-2 flex items-center gap-2.5">
                        <span class="grid size-9 place-items-center rounded-full bg-brand-soft text-[12.5px] font-semibold text-brand-text" aria-hidden="true">{{ initials(employee.full_name) }}</span>
                        <span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ employee.employee_code }}</span>
                        <AppBadge :tone="statusTone(employee.status)" dot>{{ t(`hrm.statuses.${employee.status}`) }}</AppBadge>
                    </span>
                </template>
                <template v-if="steps.length" #actions>
                    <div class="flex flex-wrap gap-2" role="group" :aria-label="t('hrm.profile.steps_title')">
                        <AppButton
                            v-for="step in steps"
                            :key="step"
                            size="sm"
                            :variant="step === 'exit' ? 'danger-soft' : step === 'confirm' || step === 'rehire' ? 'primary' : 'secondary'"
                            @click="openStep = step"
                        >
                            {{ t(`hrm.steps.${step}`) }}
                        </AppButton>
                    </div>
                </template>
            </PageHeader>

            <div class="mb-5">
                <AppTabs v-model="tab" :tabs="tabs" :label="employee.full_name" />
            </div>

            <section v-if="tab === 'overview'" class="card p-5 sm:p-6">
                <dl class="grid gap-x-6 gap-y-4 text-[13.5px] sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-muted">{{ t('hrm.fields.unit') }}</dt><dd class="font-medium text-fg">{{ employee.unit.name }}</dd></div>
                    <div><dt class="text-muted">{{ t('hrm.fields.position') }}</dt><dd class="font-medium text-fg">{{ employee.position?.title ?? '–' }}</dd></div>
                    <div><dt class="text-muted">{{ t('hrm.fields.employment_type') }}</dt><dd class="font-medium text-fg">{{ typeLabel }}</dd></div>
                    <div><dt class="text-muted">{{ t('hrm.fields.manager') }}</dt><dd class="font-medium text-fg">{{ employee.manager?.full_name ?? '–' }}</dd></div>
                    <div><dt class="text-muted">{{ t('hrm.fields.joined_on') }}</dt><dd class="font-medium text-fg">{{ formatDate(employee.joined_on) }}</dd></div>
                    <div v-if="employee.probation_ends_on && employee.status === 'probation'"><dt class="text-muted">{{ t('hrm.fields.probation_ends_on') }}</dt><dd class="font-medium text-fg">{{ formatDate(employee.probation_ends_on) }}</dd></div>
                    <div v-if="employee.confirmed_on"><dt class="text-muted">{{ t('hrm.fields.confirmed_on') }}</dt><dd class="font-medium text-fg">{{ formatDate(employee.confirmed_on) }}</dd></div>
                    <div v-if="employee.exits_on"><dt class="text-muted">{{ t('hrm.fields.exits_on') }}</dt><dd class="font-medium text-fg">{{ formatDate(employee.exits_on) }}</dd></div>
                    <div v-if="employee.exit_reason" class="sm:col-span-2"><dt class="text-muted">{{ t('hrm.fields.exit_reason') }}</dt><dd class="font-medium text-fg">{{ employee.exit_reason }}</dd></div>
                </dl>
                <p v-if="opts && !can.manage" class="mt-5 rounded-xl bg-subtle px-4 py-3 text-[12.5px] text-muted">{{ t('hrm.no_access') }}</p>
            </section>

            <PersonalPanel v-else-if="tab === 'personal'" :employee="employee" :hrm="hrm" :options="opts" :can-edit="can.manage" @saved="(data) => (record.data.value = { data })" @conflict="reload" />
            <HistoryPanel v-else-if="tab === 'history'" ref="history" :employee-id="employee.id" :hrm="hrm" :names="names" />
            <DocumentsPanel v-else :employee-id="employee.id" :hrm="hrm" :options="opts" :can-edit="can.manage" />

            <StepDialog :open="openStep !== null" :step="openStep" :employee="employee" :hrm="hrm" :options="opts" @close="openStep = null" @done="stepDone" @conflict="reload" />
        </template>
    </div>
</template>
