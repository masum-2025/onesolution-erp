<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Pencil, Plus, SearchX, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { currentOrganization } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { displayValue } from '@/lib/generatedFields';
import GeneratedEntityDialog from './GeneratedEntityDialog.vue';

/**
 * The list of one entity of a generated module (make:erp-module): search,
 * filters, pages, the chosen columns, and making, changing and removing
 * records (only with the module's manage permission, from its setup).
 * Everything it shows comes from the module's schema.js and its texts.
 */
const props = defineProps({
    schema: { type: Object, required: true },
    entity: { type: String, required: true },
});
const ns = computed(() => props.schema.key);
const meta = computed(() => props.schema.entities.find((item) => item.name === props.entity));
const base = computed(() => `/api/organizations/${currentOrganization().id}/${props.schema.slug}`);
const listBase = computed(() => `${base.value}/${meta.value.path}`);

const setup = ref({});
const state = reactive({ loading: true, error: null, items: [], total: 0, page: 1, last: 1 });
const columns = computed(() => meta.value.columns.map((name) => meta.value.fields.find((field) => field.name === name)).filter(Boolean));
const filters = computed(() => meta.value.filters.map((name) => meta.value.fields.find((field) => field.name === name)).filter(Boolean));
// Every filter starts at '' so its select shows "…: All" rather than a blank box.
const query = reactive({ q: '', page: 1, filters: Object.fromEntries(filters.value.map((field) => [field.column, ''])) });
const ctx = computed(() => ({ t, ns: ns.value, entity: meta.value.name, currency: setup.value.currency, options: setup.value.options, employees: setup.value.employees }));

async function loadSetup() {
    try {
        setup.value = (await api(`${base.value}/setup`)).data;
    } catch {
        setup.value = {};
    }
}
async function load() {
    state.loading = true;
    state.error = null;
    try {
        const params = { page: query.page, ...(query.q.trim() ? { q: query.q.trim() } : {}), ...Object.fromEntries(Object.entries(query.filters).filter(([, value]) => value !== '')) };
        const { data, meta: info } = await api(listBase.value, { query: params });
        Object.assign(state, { items: data, total: info.total, page: info.page, last: info.last_page });
    } catch (error) {
        state.error = error;
    } finally {
        state.loading = false;
    }
}
loadSetup();
load();
let timer = null;
watch(() => query.q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => { query.page = 1; load(); }, 300);
});
watch(() => query.filters, () => { query.page = 1; load(); }, { deep: true });

const editing = ref(null);
const dialogOpen = ref(false);
function open(record) {
    editing.value = record;
    dialogOpen.value = true;
}
function saved() {
    dialogOpen.value = false;
    loadSetup();
    load();
}
async function remove(record) {
    const yes = await confirmAction({ title: t('generated.remove_title', { name: displayValue(meta.value.fields.find((field) => field.name === meta.value.title), record[meta.value.titleColumn], ctx.value) }), message: t('generated.remove_text'), confirmLabel: t('generated.remove'), danger: true });
    if (!yes) return;
    try {
        await api(`${listBase.value}/${record.id}`, { method: 'DELETE', query: { base_version: record.version } });
        toast.success(t('generated.removed'));
        loadSetup();
        load();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('generated.conflict') : error.message);
        load();
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t(`${ns}.entities.${meta.name}.plural`)" :description="t(`${ns}.module.description`)">
            <template v-if="setup.can?.manage" #actions>
                <AppButton variant="primary" :icon="Plus" @click="open(null)">{{ t('generated.new', { name: t(`${ns}.entities.${meta.name}.label`) }) }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap gap-2">
            <input v-if="meta.search.length" v-model="query.q" type="search" class="field-input w-full sm:w-72" :placeholder="t('generated.search')" :aria-label="t('generated.search')" />
            <select v-for="field in filters" :key="field.name" v-model="query.filters[field.column]" class="field-input w-auto" :aria-label="t(`${ns}.fields.${meta.name}.${field.name}`)">
                <option value="">{{ t(`${ns}.fields.${meta.name}.${field.name}`) }}: {{ t('generated.all') }}</option>
                <template v-if="field.type === 'enum'">
                    <option v-for="value in field.options" :key="value" :value="value">{{ t(`${ns}.options.${meta.name}.${field.name}.${value}`) }}</option>
                </template>
                <template v-else-if="field.type === 'boolean'">
                    <option value="1">{{ t('generated.yes') }}</option>
                    <option value="0">{{ t('generated.no') }}</option>
                </template>
                <template v-else-if="field.type === 'belongs_to'">
                    <option v-for="(title, value) in setup.options?.[field.entity] ?? {}" :key="value" :value="value">{{ title }}</option>
                </template>
                <template v-else>
                    <option v-for="(name, value) in setup.employees ?? {}" :key="value" :value="value">{{ name }}</option>
                </template>
            </select>
        </div>

        <SkeletonRows v-if="state.loading && !state.items.length" :rows="6" />
        <ErrorState v-else-if="state.error" :error="state.error" @retry="load()" />
        <section v-else-if="!state.items.length" class="card">
            <EmptyState :icon="SearchX" :title="query.q || Object.values(query.filters).some(Boolean) ? t('generated.nothing_found') : t('generated.empty', { name: t(`${ns}.entities.${meta.name}.plural`) })" />
        </section>
        <section v-else class="card overflow-x-auto">
            <!-- Phones: one card per record (the title, then label: value), not a squeezed table. -->
            <ul class="divide-y divide-line sm:hidden">
                <li v-for="record in state.items" :key="record.id" class="flex items-start gap-2 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="break-words font-medium text-fg">{{ displayValue(columns[0], record[columns[0].column], ctx) }}</p>
                        <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-[13px]">
                            <template v-for="field in columns.slice(1)" :key="field.name">
                                <dt class="text-muted">{{ t(`${ns}.fields.${meta.name}.${field.name}`) }}</dt>
                                <dd class="min-w-0 break-words" :class="['integer', 'quantity', 'money'].includes(field.type) ? 'tabular' : ''">{{ displayValue(field, record[field.column], ctx) }}</dd>
                            </template>
                        </dl>
                    </div>
                    <div v-if="setup.can?.manage" class="flex shrink-0">
                        <AppButton size="icon-sm" variant="ghost" :icon="Pencil" :aria-label="t('generated.edit', { name: t(`${ns}.entities.${meta.name}.label`) })" @click="open(record)" />
                        <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('generated.remove')" @click="remove(record)" />
                    </div>
                </li>
            </ul>
            <table class="hidden w-full min-w-[560px] text-[13.5px] sm:table">
                <thead class="bg-subtle/60 text-[12px] text-muted">
                    <tr>
                        <th v-for="field in columns" :key="field.name" scope="col" class="px-4 py-2.5 text-start font-medium" :class="['integer', 'quantity', 'money'].includes(field.type) ? 'text-end' : ''">
                            {{ t(`${ns}.fields.${meta.name}.${field.name}`) }}
                        </th>
                        <th v-if="setup.can?.manage" scope="col" class="w-24" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="record in state.items" :key="record.id">
                        <td v-for="(field, index) in columns" :key="field.name" class="px-4 py-2.5" :class="[['integer', 'quantity', 'money'].includes(field.type) ? 'tabular text-end' : '', index === 0 ? 'font-medium text-fg' : '']">
                            {{ displayValue(field, record[field.column], ctx) }}
                        </td>
                        <td v-if="setup.can?.manage" class="px-2 py-1.5 text-end">
                            <AppButton size="icon-sm" variant="ghost" :icon="Pencil" :aria-label="t('generated.edit', { name: t(`${ns}.entities.${meta.name}.label`) })" @click="open(record)" />
                            <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('generated.remove')" @click="remove(record)" />
                        </td>
                    </tr>
                </tbody>
            </table>
            <footer v-if="state.last > 1" class="flex items-center justify-between border-t border-line px-4 py-2 text-[13px] text-muted">
                <span>{{ t('generated.page', { page: formatNumber(state.page), pages: formatNumber(state.last), total: formatNumber(state.total) }) }}</span>
                <span class="flex gap-2">
                    <AppButton size="sm" :disabled="state.page <= 1" @click="query.page = state.page - 1; load()">{{ t('generated.previous') }}</AppButton>
                    <AppButton size="sm" :disabled="state.page >= state.last" @click="query.page = state.page + 1; load()">{{ t('generated.next') }}</AppButton>
                </span>
            </footer>
        </section>

        <GeneratedEntityDialog :open="dialogOpen" :schema="schema" :entity="meta" :record="editing" :base="listBase" :setup="setup" @close="dialogOpen = false" @saved="saved" />
    </div>
</template>
