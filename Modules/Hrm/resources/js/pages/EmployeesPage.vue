<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Briefcase, ChevronDown, ChevronLeft, ChevronRight, FileUp, ListPlus, Network, Search, UserPlus, Users, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppMenu from '@/components/AppMenu.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { hrmApi } from '../api';
import { initials, statusTone } from '../lib';

/**
 * Everyone working in this organization and the units below it: search by
 * name or code, filter by status. Opens a profile; hiring for HR people.
 */
const org = currentOrganization();
const hrm = hrmApi(org.id);
const route = useRoute();
const router = useRouter();

// Set-up screens: positions and extra fields for everyone who reads HRM, importing for HR people.
const more = computed(() => [
    { label: t('hrm.departments_link'), icon: Network, onSelect: () => router.push({ name: 'hrm-departments' }) },
    { label: t('hrm.positions_link'), icon: Briefcase, onSelect: () => router.push({ name: 'hrm-positions' }) },
    { label: t('hrm.fields_link'), icon: ListPlus, onSelect: () => router.push({ name: 'hrm-fields' }) },
    ...(can('hrm.manage') ? [{ label: t('hrm.import_link'), icon: FileUp, onSelect: () => router.push({ name: 'hrm-import' }) }] : []),
]);

const search = ref(route.query.q ?? '');
const status = ref(route.query.status ?? '');
// A branch or a department (a branch takes its departments in too), and a designation.
const unit = ref(route.query.unit_id ?? '');
const position = ref(route.query.position_id ?? '');
const page = ref(Number(route.query.page) || 1);
const statuses = ['probation', 'active', 'on_notice', 'exited'];

const places = useResource(() => hrm.departments());
const positions = useResource(() => hrm.positions());
const list = useResource(() => hrm.employees({ q: search.value.trim(), status: status.value, unit_id: unit.value || undefined, position_id: position.value || undefined, page: page.value, per_page: 25 }));
const employees = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
const filtering = computed(() => search.value.trim() !== '' || status.value !== '' || unit.value !== '' || position.value !== '');
// The place filter is worth showing once there is more than one place to choose.
const placeChoices = computed(() => places.data.value?.data ?? []);
const showPlaces = computed(() => placeChoices.value.length > 1 || placeChoices.value.some((place) => place.departments.length));

// Search after a short pause in typing; keep the filters in the address (shareable, back button).
let timer = null;
watch([search, status, unit, position], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        sync();
    }, 300);
});
onBeforeUnmount(() => clearTimeout(timer));

function sync() {
    router.replace({ query: {
        ...(search.value.trim() ? { q: search.value.trim() } : {}),
        ...(status.value ? { status: status.value } : {}),
        ...(unit.value ? { unit_id: unit.value } : {}),
        ...(position.value ? { position_id: position.value } : {}),
        ...(page.value > 1 ? { page: page.value } : {}),
    } });
    list.reload();
}

function go(to) {
    page.value = to;
    sync();
}

function clear() {
    search.value = '';
    status.value = '';
    unit.value = '';
    position.value = '';
}
</script>

<template>
    <div>
        <PageHeader :title="t('hrm.title')" :description="t('hrm.text')">
            <template #actions>
                <AppMenu :items="more" :label="t('hrm.more')">
                    <template #trigger="{ toggle, attrs }">
                        <AppButton :icon-end="ChevronDown" v-bind="attrs" @click="toggle(false)">{{ t('hrm.more') }}</AppButton>
                    </template>
                </AppMenu>
                <AppButton v-if="can('hrm.manage')" variant="primary" :to="{ name: 'hrm-hire' }" :icon="UserPlus">{{ t('hrm.hire') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end">
            <AppField v-slot="{ id }" :label="t('hrm.search')" sr-only-label class="flex-1">
                <div class="relative">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                    <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('hrm.search')" autocomplete="off" />
                </div>
            </AppField>
            <AppField v-if="showPlaces" v-slot="{ id }" :label="t('hrm.filters.department')" sr-only-label class="lg:w-56">
                <select :id="id" v-model="unit" class="field-input">
                    <option value="">{{ t('hrm.filters.all_departments') }}</option>
                    <optgroup v-for="place in placeChoices" :key="place.id" :label="place.name">
                        <option :value="place.id">{{ t('hrm.filters.whole', { name: place.name }) }}</option>
                        <option v-for="department in place.departments" :key="department.id" :value="department.id">{{ department.name }}</option>
                    </optgroup>
                </select>
            </AppField>
            <AppField v-if="(positions.data.value?.data ?? []).length" v-slot="{ id }" :label="t('hrm.filters.position')" sr-only-label class="lg:w-48">
                <select :id="id" v-model="position" class="field-input">
                    <option value="">{{ t('hrm.filters.all_positions') }}</option>
                    <option v-for="item in positions.data.value.data" :key="item.id" :value="item.id">{{ item.title }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('hrm.filters.status')" sr-only-label class="lg:w-44">
                <select :id="id" v-model="status" class="field-input">
                    <option value="">{{ t('hrm.filters.all_statuses') }}</option>
                    <option v-for="value in statuses" :key="value" :value="value">{{ t(`hrm.statuses.${value}`) }}</option>
                </select>
            </AppField>
            <AppButton v-if="filtering" variant="ghost" :icon="X" @click="clear">{{ t('hrm.filters.clear') }}</AppButton>
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="8" avatar />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState
                v-else-if="!employees.length"
                :icon="Users"
                :title="filtering ? t('hrm.list.none_title') : t('hrm.list.empty_title')"
                :text="filtering ? t('hrm.list.none_text') : t('hrm.list.empty_text')"
                compact
            >
                <AppButton v-if="!filtering && can('hrm.manage')" variant="primary" :to="{ name: 'hrm-hire' }" :icon="UserPlus">{{ t('hrm.hire') }}</AppButton>
            </EmptyState>

            <template v-else>
                <header class="border-b border-line px-4 py-2.5 text-[12.5px] text-muted sm:px-5">
                    {{ t('hrm.list.count', { count: meta?.total ?? employees.length }) }}
                </header>
                <ul class="divide-y divide-line">
                    <li v-for="employee in employees" :key="employee.id">
                        <RouterLink
                            :to="{ name: 'hrm-employee', params: { id: employee.id } }"
                            class="flex items-center gap-3.5 px-4 py-3 transition hover:bg-subtle/60 sm:px-5"
                        >
                            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-soft text-[13px] font-semibold text-brand-text" aria-hidden="true">
                                {{ initials(employee.full_name) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ employee.full_name }}</span>
                                <span class="mt-0.5 line-clamp-2 text-[12.5px] text-muted sm:line-clamp-none sm:block sm:truncate">
                                    <span class="font-mono" dir="ltr">{{ employee.employee_code }}</span>
                                    <template v-if="employee.position"> · {{ employee.position.title }}</template>
                                    <template v-if="employee.department"> · {{ employee.department.name }}</template>
                                    · {{ employee.branch?.name ?? employee.unit.name }}
                                </span>
                            </span>
                            <span class="hidden shrink-0 text-[12px] text-faint md:block">{{ t('hrm.list.joined', { date: formatDate(employee.joined_on) }) }}</span>
                            <AppBadge :tone="statusTone(employee.status)" dot class="shrink-0">{{ t(`hrm.statuses.${employee.status}`) }}</AppBadge>
                        </RouterLink>
                    </li>
                </ul>
            </template>

            <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                <span class="tabular text-[12.5px] text-muted">{{ t('hrm.list.page', { page: formatNumber(meta.page), pages: formatNumber(meta.last_page) }) }}</span>
                <div class="flex gap-2">
                    <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('core.actions.previous') }}</AppButton>
                    <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('core.actions.next') }}</AppButton>
                </div>
            </footer>
        </section>
    </div>
</template>
