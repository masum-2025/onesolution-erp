<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, CheckCheck, Sparkles, UserSearch, Users } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { zktecoApi } from '../api';

/**
 * Matching a device's users to employees: each user (the number and name the
 * device keeps) and the employee they are. Matched by code on their own when
 * the device number is the employee code; otherwise chosen here, helped by a
 * suggestion from the name ("PARVEJ" → Parvej Hossain), one by one or all
 * suggestions at once. A user's waiting punches go to attendance as soon as
 * they are matched.
 */
const route = useRoute();
const api = zktecoApi(currentOrganization().id);
const setup = useResource(() => api.setup());
const devices = useResource(() => api.devices());
const users = useResource(() => api.users(route.params.id));
const device = computed(() => (devices.data.value?.data ?? []).find((item) => item.id === route.params.id) ?? null);
const rows = computed(() => users.data.value?.data ?? []);
const employees = computed(() => users.data.value?.meta?.employees ?? []);
const byId = computed(() => Object.fromEntries(employees.value.map((employee) => [employee.id, employee])));
const canManage = computed(() => setup.data.value?.data?.can?.manage === true);

const show = ref('waiting');
const search = ref('');
const saving = ref({});
const filters = computed(() => [
    { value: 'all', label: t('zkteco.match.all', { count: formatNumber(rows.value.length) }) },
    { value: 'waiting', label: t('zkteco.match.unmatched', { count: formatNumber(rows.value.filter((row) => !row.employee).length) }) },
    { value: 'suggested', label: t('zkteco.match.suggested', { count: formatNumber(suggested.value.length) }) },
]);
const suggested = computed(() => rows.value.filter((row) => row.suggestion && byId.value[row.suggestion]));
const shown = computed(() => {
    const term = search.value.trim().toLowerCase();
    return rows.value
        .filter((row) => (show.value === 'all' ? true : show.value === 'suggested' ? row.suggestion : !row.employee))
        .filter((row) => !term || row.user_id.toLowerCase().includes(term) || (row.name ?? '').toLowerCase().includes(term) || (row.employee?.name ?? '').toLowerCase().includes(term));
});
const matchedCount = computed(() => rows.value.filter((row) => row.employee).length);
const waitingCount = computed(() => rows.value.reduce((sum, row) => sum + row.waiting, 0));
const employeeText = (employee) => `${employee.code} · ${employee.name}`;

async function choose(row, employeeId) {
    saving.value = { ...saving.value, [row.id]: true };
    try {
        const response = await api.match(route.params.id, row.id, employeeId || null);
        toast.success(employeeId
            ? t('zkteco.match.matched', { name: byId.value[employeeId]?.name ?? '', recorded: formatNumber(response.data.recorded) })
            : t('zkteco.match.unlinked'));
        await Promise.all([users.reload(), devices.reload()]);
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = { ...saving.value, [row.id]: false };
    }
}

const acceptingAll = ref(false);
async function acceptAll() {
    acceptingAll.value = true;
    try {
        const response = await api.matchMany(route.params.id, suggested.value.map((row) => ({ user: row.id, employee_id: row.suggestion })));
        toast.success(t('zkteco.match.all_matched', { count: formatNumber(response.data.matched), recorded: formatNumber(response.data.recorded) }));
        await Promise.all([users.reload(), devices.reload()]);
    } catch (error) {
        toast.error(error.message);
    } finally {
        acceptingAll.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mb-5 flex items-start gap-2">
            <AppButton size="icon" variant="ghost" :icon="ArrowLeft" :to="{ name: 'zkteco-devices' }" :aria-label="t('zkteco.match.back')" />
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-[22px] font-semibold tracking-tight text-fg">{{ device?.name ?? t('zkteco.match.title') }}</h1>
                <p class="text-[13px] text-muted">{{ t('zkteco.match.text') }}</p>
            </div>
        </div>

        <SkeletonRows v-if="users.loading.value && !users.data.value" :rows="8" />
        <ErrorState v-else-if="users.error.value" :error="users.error.value" @retry="users.reload()" />
        <section v-else-if="!rows.length" class="card">
            <EmptyState :icon="Users" :title="t('zkteco.match.empty_title')" :text="t('zkteco.match.empty_text')" />
        </section>
        <template v-else>
            <dl class="mb-3 grid grid-cols-3 gap-2">
                <div class="card p-2.5 sm:p-3"><dt class="text-[11.5px] text-muted sm:text-[12px]">{{ t('zkteco.match.matched_count') }}</dt><dd class="tabular text-[17px] font-semibold text-fg sm:text-[20px]">{{ formatNumber(matchedCount) }}/{{ formatNumber(rows.length) }}</dd></div>
                <div class="card p-2.5 sm:p-3"><dt class="text-[11.5px] text-muted sm:text-[12px]">{{ t('zkteco.match.waiting_punches') }}</dt><dd class="tabular text-[17px] font-semibold sm:text-[20px]" :class="waitingCount ? 'text-warn' : 'text-fg'">{{ formatNumber(waitingCount) }}</dd></div>
                <div class="card p-2.5 sm:p-3"><dt class="text-[11.5px] text-muted sm:text-[12px]">{{ t('zkteco.match.suggestions') }}</dt><dd class="tabular text-[17px] font-semibold text-brand-text sm:text-[20px]">{{ formatNumber(suggested.length) }}</dd></div>
            </dl>
            <AppButton v-if="canManage && suggested.length" class="mb-3" variant="primary" :icon="CheckCheck" :loading="acceptingAll" @click="acceptAll">
                {{ t('zkteco.match.accept_all_count', { count: formatNumber(suggested.length) }) }}
            </AppButton>

            <div class="mb-3 flex flex-wrap items-center gap-2">
                <AppSegmented v-model="show" :options="filters" :label="t('zkteco.match.filter')" size="sm" />
                <input v-model="search" type="search" class="field-input w-full sm:ms-auto sm:w-64" :placeholder="t('zkteco.match.search')" :aria-label="t('zkteco.match.search')" />
            </div>

            <section v-if="!shown.length" class="card">
                <EmptyState :icon="UserSearch" compact :title="t('zkteco.match.nothing')" />
            </section>
            <ul v-else class="card divide-y divide-line">
                <li v-for="row in shown" :key="row.id" class="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)] sm:items-center">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="tabular grid h-9 min-w-9 shrink-0 place-items-center rounded-lg bg-subtle px-2 text-[13px] font-semibold text-fg-2">{{ row.user_id }}</span>
                        <div class="min-w-0">
                            <p class="truncate font-medium text-fg">{{ row.name || t('zkteco.match.no_name') }}</p>
                            <p class="flex flex-wrap gap-1 text-[12px]">
                                <AppBadge v-if="row.matched_by === 'code'" tone="ok">{{ t('zkteco.match.by_code') }}</AppBadge>
                                <AppBadge v-else-if="row.matched_by === 'hand' && row.employee" tone="brand">{{ t('zkteco.match.by_hand') }}</AppBadge>
                                <AppBadge v-if="row.waiting" tone="warn">{{ t('zkteco.match.waiting', { count: formatNumber(row.waiting) }) }}</AppBadge>
                            </p>
                        </div>
                    </div>
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <select
                            class="field-input min-w-0 flex-1"
                            :value="row.employee?.id ?? ''"
                            :disabled="!canManage || saving[row.id]"
                            :aria-label="t('zkteco.match.employee_for', { user: row.user_id })"
                            @change="choose(row, $event.target.value)"
                        >
                            <option value="">{{ t('zkteco.match.nobody') }}</option>
                            <option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employeeText(employee) }}</option>
                        </select>
                        <AppButton
                            v-if="canManage && row.suggestion && byId[row.suggestion]"
                            size="sm"
                            :icon="Sparkles"
                            :loading="saving[row.id]"
                            :aria-label="t('zkteco.match.accept', { name: byId[row.suggestion].name })"
                            @click="choose(row, row.suggestion)"
                        >
                            {{ byId[row.suggestion].name }}
                        </AppButton>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>
