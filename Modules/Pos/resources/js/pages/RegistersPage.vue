<script setup>
import { computed, reactive, ref } from 'vue';
import { PenLine, Plus, Store } from 'lucide-vue-next';
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
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { posApi } from '../api';

/** Counters: a code, a name, the branch, the warehouse it sells from, the payment methods it takes. */
const org = currentOrganization();
const pos = posApi(org.id);
const list = useResource(() => pos.registers());
const registers = computed(() => list.data.value?.data ?? []);
const units = useResource(() => api('/api/organizations', { query: { per_page: 100 } }));
const workUnits = computed(() => (units.data.value?.data ?? []).filter((unit) => ['company', 'branch', 'department', 'personal'].includes(unit.type)));
const warehouses = useResource(() => api(`/api/organizations/${org.id}/inventory/warehouses`));
const warehouseName = (id) => (warehouses.data.value?.data ?? []).find((row) => row.id === id)?.name ?? '—';
const unitName = (id) => workUnits.value.find((unit) => unit.id === id)?.display_name ?? '';

const editing = ref(null);
const saving = ref(false);
const errors = ref({});
const form = reactive({ code: '', name: { en: '', bn: '' }, unit_id: '', warehouse_id: '', payment_methods: ['cash'], is_active: true });
function edit(row = null) {
    Object.assign(form, {
        code: row?.code ?? '', name: { en: row?.names.en ?? '', bn: row?.names.bn ?? '' }, unit_id: row?.unit_id ?? workUnits.value[0]?.id ?? '',
        warehouse_id: row?.warehouse_id ?? (warehouses.data.value?.data ?? [])[0]?.id ?? '', payment_methods: [...(row?.payment_methods ?? ['cash'])], is_active: row?.is_active ?? true,
    });
    errors.value = {};
    editing.value = row ?? 'new';
}
async function save() {
    const body = { code: form.code.trim(), name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())), unit_id: form.unit_id, warehouse_id: form.warehouse_id, payment_methods: form.payment_methods };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await pos.createRegister(body);
        else await pos.updateRegister(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('pos.registers.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
function toggleMethod(method) {
    form.payment_methods = form.payment_methods.includes(method) ? form.payment_methods.filter((value) => value !== method) : [...form.payment_methods, method];
}
</script>

<template>
    <div>
        <PageHeader :title="t('pos.registers.title')" :description="t('pos.registers.text')">
            <template #actions>
                <AppButton v-if="can('pos.manage')" variant="primary" :icon="Plus" @click="edit()">{{ t('pos.registers.add') }}</AppButton>
            </template>
        </PageHeader>
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="3" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!registers.length" :icon="Store" :title="t('pos.registers.empty')" :text="t('pos.registers.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in registers" :key="row.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><Store class="size-5" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ row.name }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ row.code }}</span></span>
                        <span class="block text-[12.5px] text-muted">{{ unitName(row.unit_id) }} · {{ warehouseName(row.warehouse_id) }} · {{ row.payment_methods.map((method) => t(`pos.methods.${method}`)).join(', ') }}</span>
                    </span>
                    <AppBadge v-if="row.session" tone="ok" dot>{{ t('pos.till.shift_open') }}</AppBadge>
                    <AppBadge v-if="!row.is_active" tone="neutral">{{ t('pos.common.off') }}</AppBadge>
                    <AppButton v-if="can('pos.manage')" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('pos.common.edit')" @click="edit(row)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('pos.registers.add') : t('pos.common.edit')" :icon="Store" @close="editing = null">
            <form id="pos-register" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('pos.common.code')" :error="errors.code?.[0]">
                    <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="12" />
                </AppField>
                <div class="sm:col-span-2"><TranslatedFields v-model="form.name" :label="t('pos.common.name')" :errors="errors" required :maxlength="80" /></div>
                <AppField v-slot="{ id }" :label="t('pos.registers.branch')" :error="errors.unit_id?.[0]" class="sm:col-span-3">
                    <select :id="id" v-model="form.unit_id" class="field-input">
                        <option v-for="unit in workUnits" :key="unit.id" :value="unit.id">{{ '— '.repeat(Math.max(0, unit.depth - (workUnits[0]?.depth ?? 0))) }}{{ unit.display_name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('pos.registers.warehouse')" :hint="t('pos.registers.warehouse_hint')" :error="errors.warehouse_id?.[0]" class="sm:col-span-3">
                    <select :id="id" v-model="form.warehouse_id" class="field-input">
                        <option v-for="warehouse in (warehouses.data.value?.data ?? []).filter((row) => row.is_active)" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                    </select>
                </AppField>
                <fieldset class="sm:col-span-3">
                    <legend class="mb-2 text-[13px] font-medium">{{ t('pos.registers.methods') }}</legend>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="method in ['cash', 'card', 'mobile']" :key="method" class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-[13.5px]">
                            <input type="checkbox" :checked="form.payment_methods.includes(method)" @change="toggleMethod(method)" /> {{ t(`pos.methods.${method}`) }}
                        </label>
                    </div>
                    <p v-if="errors.payment_methods" class="mt-1 text-[12px] text-bad">{{ errors.payment_methods[0] }}</p>
                </fieldset>
                <div v-if="editing !== 'new'" class="sm:col-span-3"><AppSwitch v-model="form.is_active" :label="t('pos.common.active')" show-label /></div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('pos.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="pos-register" :loading="saving" :disabled="!form.code.trim() || !form.name.en?.trim() || !form.payment_methods.length">{{ t('pos.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
