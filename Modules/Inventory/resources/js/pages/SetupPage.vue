<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Boxes, PenLine, Plus, Ruler, Warehouse } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';

/**
 * Warehouses, units or categories (the address says which): a code, a name
 * in each language, and what the kind needs (a warehouse's branch, a unit's
 * decimals, a category's parent). Switched off, never removed.
 */
const route = useRoute();
const kind = computed(() => route.meta.kind);
const inventory = inventoryApi(currentOrganization().id);
const list = useResource(() => inventory.list(kind.value));
watch(kind, () => list.reload());
const rows = computed(() => list.data.value?.data ?? []);
const icon = computed(() => ({ warehouses: Warehouse, units: Ruler, categories: Boxes })[kind.value]);

const units = useResource(() => api('/api/organizations', { query: { per_page: 100 } }), { immediate: false });
const workUnits = computed(() => (units.data.value?.data ?? []).filter((unit) => ['company', 'branch', 'department', 'personal'].includes(unit.type)));
const unitName = (id) => workUnits.value.find((unit) => unit.id === id)?.display_name ?? '';
watch(kind, (value) => value === 'warehouses' && !units.data.value && units.reload(), { immediate: true });

const editing = ref(null);
const saving = ref(false);
const errors = ref({});
const form = reactive({ code: '', name: { en: '', bn: '' }, unit_id: '', decimals: 0, parent_id: '', is_active: true });

function edit(row = null) {
    Object.assign(form, {
        code: row?.code ?? '', name: { en: row?.names.en ?? '', bn: row?.names.bn ?? '' }, unit_id: row?.unit_id ?? workUnits.value[0]?.id ?? '',
        decimals: row?.decimals ?? 0, parent_id: row?.parent_id ?? '', is_active: row?.is_active ?? true,
    });
    errors.value = {};
    editing.value = row ?? 'new';
}

async function save() {
    const body = { code: form.code.trim(), name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())) };
    if (kind.value === 'warehouses') body.unit_id = form.unit_id;
    if (kind.value === 'units') body.decimals = Number(form.decimals) || 0;
    if (kind.value === 'categories') body.parent_id = form.parent_id || null;
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await inventory.create(kind.value, body);
        else await inventory.update(kind.value, editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('inventory.setup.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(error.message);
            editing.value = null;
            list.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const detail = (row) => {
    if (kind.value === 'warehouses') return unitName(row.unit_id);
    if (kind.value === 'units') return t('inventory.setup.decimals_of', { count: formatNumber(row.decimals) });
    return row.parent_id ? t('inventory.setup.under', { name: rows.value.find((item) => item.id === row.parent_id)?.name ?? '' }) : '';
};
</script>

<template>
    <div>
        <PageHeader :title="t(`inventory.setup.${kind}.title`)" :description="t(`inventory.setup.${kind}.text`)">
            <template #actions>
                <AppButton v-if="can('inventory.manage')" variant="primary" :icon="Plus" @click="edit()">{{ t(`inventory.setup.${kind}.add`) }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="icon" :title="t(`inventory.setup.${kind}.empty`)" :text="t(`inventory.setup.${kind}.empty_text`)" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><component :is="icon" class="size-5" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ row.name }} <span class="whitespace-nowrap font-mono text-[12px] text-muted" dir="ltr">{{ row.code }}</span></span>
                        <span v-if="detail(row)" class="block text-[12.5px] text-muted">{{ detail(row) }}</span>
                    </span>
                    <AppBadge v-if="!row.is_active" tone="neutral">{{ t('inventory.common.off') }}</AppBadge>
                    <AppButton v-if="can('inventory.manage')" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('inventory.common.edit')" @click="edit(row)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t(`inventory.setup.${kind}.add`) : t('inventory.common.edit')" @close="editing = null">
            <form id="inventory-setup" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('inventory.common.code')" :error="errors.code?.[0]">
                    <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="20" />
                </AppField>
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.name" :label="t('inventory.common.name')" :errors="errors" required :maxlength="120" />
                </div>
                <AppField v-if="kind === 'warehouses'" v-slot="{ id }" :label="t('inventory.setup.branch')" :error="errors.unit_id?.[0]" class="sm:col-span-3">
                    <select :id="id" v-model="form.unit_id" class="field-input">
                        <option v-for="unit in workUnits" :key="unit.id" :value="unit.id">{{ '— '.repeat(Math.max(0, unit.depth - (workUnits[0]?.depth ?? 0))) }}{{ unit.display_name }}</option>
                    </select>
                </AppField>
                <AppField v-if="kind === 'units'" v-slot="{ id }" :label="t('inventory.setup.decimals')" :hint="t('inventory.setup.decimals_hint')" :error="errors.decimals?.[0]">
                    <select :id="id" v-model.number="form.decimals" class="field-input">
                        <option v-for="value in [0, 1, 2, 3]" :key="value" :value="value">{{ formatNumber(value) }}</option>
                    </select>
                </AppField>
                <AppField v-if="kind === 'categories'" v-slot="{ id }" :label="t('inventory.setup.parent')" :error="errors.parent_id?.[0]" optional class="sm:col-span-2">
                    <select :id="id" v-model="form.parent_id" class="field-input">
                        <option value="">—</option>
                        <option v-for="row in rows.filter((item) => item.id !== editing?.id)" :key="row.id" :value="row.id">{{ row.name }}</option>
                    </select>
                </AppField>
                <div v-if="editing !== 'new'" class="sm:col-span-3">
                    <AppSwitch v-model="form.is_active" :label="t('inventory.common.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('inventory.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="inventory-setup" :loading="saving" :disabled="!form.code.trim() || !form.name.en?.trim()">{{ t('inventory.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
