<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { FileUp, ListChecks, Plus, ShieldCheck, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDate, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { complianceApi } from '../api';
import { STATES, counts, grid, renewalMark, stateTone } from '../lib';
import AuditDialog from '../components/AuditDialog.vue';
import AuditDrawer from '../components/AuditDrawer.vue';

/**
 * The audit register: every factory and audit service with its days left,
 * worked out from the official expiry day. A grid on wide screens, a list on
 * phones; the most urgent first. Filters stay in the address.
 */
const org = currentOrganization();
const calls = complianceApi(org.id);
const route = useRoute();
const router = useRouter();

const unit = ref(route.query.unit_id ?? '');
const service = ref(route.query.service_id ?? '');
const state = ref(route.query.state ?? '');
const options = useResource(() => calls.options());
const list = useResource(() => calls.register({ unit_id: unit.value || undefined, service_id: service.value || undefined, state: state.value || undefined }));
const opts = computed(() => options.data.value?.data ?? null);
const lines = computed(() => list.data.value?.data ?? []);
const summary = computed(() => counts(list.data.value?.meta?.summary));
const filtering = computed(() => !!(unit.value || service.value || state.value));
const view = computed(() => grid(lines.value, (opts.value?.factories ?? []).filter((factory) => !unit.value || factory.id === unit.value || factory.parent_id === unit.value), opts.value?.services ?? [], !filtering.value));

watch([unit, service, state], () => {
    router.replace({ query: { ...(unit.value ? { unit_id: unit.value } : {}), ...(service.value ? { service_id: service.value } : {}), ...(state.value ? { state: state.value } : {}) } });
    list.reload();
});

const openId = ref(route.query.audit ?? null);
const adding = ref(false);
const preset = ref({});
function add(factoryId = '', serviceId = '') {
    preset.value = { unit_id: factoryId, service_id: serviceId };
    adding.value = true;
}
function added(line) {
    adding.value = false;
    list.reload();
    openId.value = line.id;
}

const daysText = (days) => (days === null ? '—' : days < 0 ? t('compliance.days.ago_short', { count: -days }) : t('compliance.days.left_short', { count: days }));
// Renewal under way (CMP-2): results waiting first, else the open request with its planned day.
const markText = (mark) => (mark.key === 'results'
    ? t('compliance.register.results_waiting', { count: mark.count, results: formatNumber(mark.count) })
    : mark.planned ? t('compliance.renewal.planned_value', { date: formatDate(mark.planned) }) : t(`compliance.renewal_statuses.${mark.key}`));
const indent = (factory) => Math.max(0, factory.depth - (opts.value?.factories[0]?.depth ?? 0));
</script>

<template>
    <div>
        <PageHeader :title="t('compliance.register.title')" :description="t('compliance.register.text')">
            <template #actions>
                <AppButton v-if="opts?.can.lists" variant="ghost" :icon="ListChecks" :to="{ name: 'compliance-lists' }">{{ t('compliance.lists.title') }}</AppButton>
                <AppButton v-if="opts?.can.import" :icon="FileUp" :to="{ name: 'compliance-import' }">{{ t('compliance.import.title') }}</AppButton>
                <AppButton v-if="opts?.can.add" variant="primary" :icon="Plus" @click="add()">{{ t('compliance.add.title') }}</AppButton>
            </template>
        </PageHeader>

        <p v-if="opts?.reads_only" class="mb-4 rounded-xl border border-line bg-subtle px-4 py-3 text-[13px] text-fg-2">{{ t('compliance.register.group_reads') }}</p>

        <!-- How it stands: each count opens its own lines. -->
        <section class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4" :aria-label="t('compliance.register.summary')">
            <button v-for="key in STATES" :key="key" type="button" class="card px-4 py-3 text-start transition hover:bg-subtle/60"
                :class="state === key ? 'ring-2 ring-brand' : ''" :aria-pressed="state === key" @click="state = state === key ? '' : key">
                <AppBadge :tone="stateTone(key)">{{ t(`compliance.states.${key}`) }}</AppBadge>
                <span class="mt-2 block text-[24px] font-semibold tabular">{{ formatNumber(summary[key]) }}</span>
            </button>
        </section>

        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            <AppField v-slot="{ id }" :label="t('compliance.fields.factory')" sr-only-label class="sm:w-64">
                <select :id="id" v-model="unit" class="field-input">
                    <option value="">{{ t('compliance.register.all_factories') }}</option>
                    <option v-for="factory in opts?.factories ?? []" :key="factory.id" :value="factory.id">{{ '— '.repeat(indent(factory)) }}{{ factory.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('compliance.fields.service')" sr-only-label class="sm:w-56">
                <select :id="id" v-model="service" class="field-input">
                    <option value="">{{ t('compliance.register.all_services') }}</option>
                    <option v-for="item in opts?.services ?? []" :key="item.id" :value="item.id">{{ item.name }}</option>
                </select>
            </AppField>
            <AppButton v-if="filtering" variant="ghost" :icon="X" @click="unit = ''; service = ''; state = ''">{{ t('compliance.register.clear') }}</AppButton>
        </div>

        <SkeletonRows v-if="(list.loading.value && !list.data.value) || !opts" :rows="6" />
        <ErrorState v-else-if="list.error.value || options.error.value" :error="list.error.value ?? options.error.value" @retry="list.reload(); options.reload()" />
        <section v-else-if="!lines.length" class="card">
            <EmptyState :icon="ShieldCheck" :title="filtering ? t('compliance.register.none_title') : t('compliance.register.empty_title')"
                :text="filtering ? t('compliance.register.none_text') : opts.services.length ? t('compliance.register.empty_text') : t('compliance.register.no_services')">
                <AppButton v-if="!filtering && !opts.services.length && opts.can.lists" variant="primary" :icon="ListChecks" :to="{ name: 'compliance-lists' }">{{ t('compliance.lists.title') }}</AppButton>
                <AppButton v-else-if="!filtering && opts.can.add" variant="primary" :icon="Plus" @click="add()">{{ t('compliance.add.title') }}</AppButton>
            </EmptyState>
        </section>
        <template v-else>
            <!-- Wide screens: factories down, services across. -->
            <section class="card hidden overflow-x-auto md:block">
                <table class="w-full min-w-max text-[13px]">
                    <thead>
                        <tr class="border-b border-line text-start text-[12px] text-muted">
                            <th scope="col" class="sticky start-0 bg-raised px-4 py-2.5 text-start font-medium">{{ t('compliance.fields.factory') }}</th>
                            <th v-for="column in view.columns" :key="column.id" scope="col" class="px-3 py-2.5 text-start font-medium">{{ column.name }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in view.rows" :key="row.factory.id">
                            <th scope="row" class="sticky start-0 bg-raised px-4 py-2 text-start font-medium" :style="{ paddingInlineStart: `${1 + indent(row.factory) * 1}rem` }">{{ row.factory.name }}</th>
                            <td v-for="(cell, index) in row.cells" :key="index" class="px-3 py-2">
                                <button v-if="cell" type="button" class="flex flex-col items-start gap-0.5 rounded-lg px-2 py-1 text-start transition hover:bg-subtle" @click="openId = cell.id">
                                    <AppBadge :tone="stateTone(cell.state)">{{ t(`compliance.states.${cell.state}`) }}</AppBadge>
                                    <span class="tabular text-[12px] text-muted">{{ daysText(cell.days_left) }}</span>
                                    <span v-if="renewalMark(cell)" class="text-[11.5px]" :class="renewalMark(cell).key === 'results' ? 'font-medium text-warn' : 'text-brand-text'">{{ markText(renewalMark(cell)) }}</span>
                                </button>
                                <button v-else-if="opts.can.add" type="button" class="rounded-lg px-2 py-1 text-[12px] text-faint transition hover:bg-subtle hover:text-fg"
                                    :aria-label="t('compliance.register.add_here', { factory: row.factory.name, service: view.columns[index].name })" @click="add(row.factory.id, view.columns[index].id)">+</button>
                                <span v-else class="px-2 text-faint" aria-hidden="true">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Phones: the most urgent first. -->
            <ul class="card divide-y divide-line md:hidden">
                <li v-for="line in lines" :key="line.id">
                    <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-start" @click="openId = line.id">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium">{{ line.service?.name }}</span>
                            <span class="block truncate text-[12.5px] text-muted">{{ line.factory.name }}<template v-if="line.expires_on"> · {{ formatDate(line.expires_on) }}</template></span>
                            <span v-if="renewalMark(line)" class="block truncate text-[12px]" :class="renewalMark(line).key === 'results' ? 'font-medium text-warn' : 'text-brand-text'">{{ markText(renewalMark(line)) }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-0.5">
                            <AppBadge :tone="stateTone(line.state)">{{ t(`compliance.states.${line.state}`) }}</AppBadge>
                            <span class="tabular text-[12px] text-muted">{{ daysText(line.days_left) }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </template>

        <AuditDrawer :open="openId !== null" :audit-id="openId" :options="opts" :calls="calls" @close="openId = null" @changed="list.reload()" />
        <AuditDialog :open="adding" :options="opts" :preset="preset" :calls="calls" @close="adding = false" @saved="added" />
    </div>
</template>
