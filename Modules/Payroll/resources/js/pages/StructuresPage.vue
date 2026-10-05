<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowLeft, Layers, PenLine, Plus, Trash2 } from 'lucide-vue-next';
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
import { useResource } from '@/lib/useResource';
import { formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { amountToMinor, bpToPercent, minorToText, percentText, percentToBp } from '../lib';

/**
 * Salary structures ("Officer", "Worker"): which components go on top of the
 * basic, each a fixed amount or a percentage of the basic. An employee's
 * salary names one structure and a basic.
 */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const list = useResource(() => payroll.structures());
const componentList = useResource(() => payroll.components());
const structures = computed(() => list.data.value?.data ?? []);
const currency = computed(() => list.data.value?.meta?.currency);
const components = computed(() => componentList.data.value?.data ?? []);
const activeComponents = computed(() => components.value.filter((component) => component.is_active));
const componentOf = (id) => components.value.find((component) => component.id === id);

function describe(item) {
    const value = item.calc === 'fixed' ? formatMoney({ amount: item.amount_minor ?? 0, currency: currency.value }) : t('payroll.structures.of_basic', { percent: percentText(item.rate_bp ?? 0) });
    return `${componentOf(item.component_id)?.name ?? '—'} ${value}`;
}

const editing = ref(null);
const saving = ref(false);
const errors = ref({});
const form = reactive({ code: '', name: { en: '', bn: '' }, is_active: true, items: [] });

function edit(structure = null) {
    Object.assign(form, {
        code: structure?.code ?? '',
        name: { en: structure?.names.en ?? '', bn: structure?.names.bn ?? '' },
        is_active: structure?.is_active ?? true,
        items: (structure?.items ?? []).map((item) => ({
            component_id: item.component_id,
            calc: item.calc,
            value: item.calc === 'fixed' ? minorToText(item.amount_minor, currency.value) : bpToPercent(item.rate_bp ?? 0),
        })),
    });
    errors.value = {};
    editing.value = structure ?? 'new';
}

function addItem() {
    const used = new Set(form.items.map((item) => item.component_id));
    form.items.push({ component_id: activeComponents.value.find((component) => !used.has(component.id))?.id ?? '', calc: 'percent_of_basic', value: '' });
}

async function save() {
    const items = [];
    const bad = {};
    form.items.forEach((item, index) => {
        const amount = item.calc === 'fixed' ? amountToMinor(item.value, currency.value) : percentToBp(item.value);
        if (amount === null) bad[`items.${index}`] = [t('payroll.structures.bad_value')];
        items.push({ component_id: item.component_id, calc: item.calc, amount_minor: item.calc === 'fixed' ? amount : null, rate_bp: item.calc === 'fixed' ? null : amount });
    });
    if (Object.keys(bad).length) {
        errors.value = bad;
        return;
    }
    const body = { code: form.code.trim(), name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())), items };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await payroll.createStructure(body);
        else await payroll.updateStructure(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('payroll.structures.saved'));
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

const itemError = (index) => errors.value[`items.${index}`]?.[0] ?? errors.value[`items.${index}.component_id`]?.[0] ?? errors.value[`items.${index}.amount_minor`]?.[0] ?? errors.value[`items.${index}.rate_bp`]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t('payroll.structures.title')" :description="t('payroll.structures.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'payroll-employees' }" :icon="ArrowLeft">{{ t('payroll.common.back') }}</AppButton>
                <AppButton v-if="can('payroll.run')" variant="primary" :icon="Plus" @click="edit()">{{ t('payroll.structures.add') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!structures.length" :icon="Layers" :title="t('payroll.structures.empty')" :text="t('payroll.structures.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="structure in structures" :key="structure.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><Layers class="size-5" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ structure.name }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ structure.code }}</span></span>
                        <span class="block text-[12.5px] text-muted">
                            {{ structure.items.length ? [t('payroll.structures.basic'), ...structure.items.map(describe)].join(' · ') : t('payroll.structures.basic_only') }}
                        </span>
                    </span>
                    <AppBadge v-if="!structure.is_active" tone="neutral">{{ t('payroll.common.off') }}</AppBadge>
                    <AppButton v-if="can('payroll.run')" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('payroll.structures.edit')" @click="edit(structure)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('payroll.structures.add') : t('payroll.structures.edit')" size="lg" @close="editing = null">
            <form id="payroll-structure" class="grid grid-cols-1 gap-4 sm:grid-cols-3" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('payroll.common.code')" :error="errors.code?.[0]">
                    <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="20" />
                </AppField>
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.name" :label="t('payroll.common.name')" :errors="errors" required :maxlength="80" />
                </div>

                <fieldset class="grid grid-cols-1 gap-2 sm:col-span-3">
                    <legend class="mb-1 text-[13px] font-medium">{{ t('payroll.structures.items') }}</legend>
                    <p class="text-[12.5px] text-muted">{{ t('payroll.structures.items_hint') }}</p>
                    <div v-for="(item, index) in form.items" :key="index" class="grid grid-cols-1 gap-2 rounded-xl border border-line p-3 sm:grid-cols-[1fr_11rem_8rem_auto] sm:items-start">
                        <select v-model="item.component_id" class="field-input" :aria-label="t('payroll.structures.component')">
                            <option v-for="component in activeComponents" :key="component.id" :value="component.id">{{ component.name }}</option>
                        </select>
                        <select v-model="item.calc" class="field-input" :aria-label="t('payroll.structures.calc')">
                            <option value="percent_of_basic">{{ t('payroll.structures.calcs.percent_of_basic') }}</option>
                            <option value="fixed">{{ t('payroll.structures.calcs.fixed') }}</option>
                        </select>
                        <div>
                            <input v-model="item.value" inputmode="decimal" class="field-input tabular text-end" dir="ltr" :placeholder="item.calc === 'fixed' ? '0.00' : '%'" :aria-label="t('payroll.structures.value')" :aria-invalid="itemError(index) ? true : undefined" />
                        </div>
                        <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('payroll.structures.remove_item')" @click="form.items.splice(index, 1)" />
                        <p v-if="itemError(index)" class="text-[12px] text-bad sm:col-span-4">{{ itemError(index) }}</p>
                    </div>
                    <div>
                        <AppButton size="sm" variant="ghost" :icon="Plus" :disabled="!activeComponents.length" @click="addItem">{{ t('payroll.structures.add_item') }}</AppButton>
                        <p v-if="!activeComponents.length" class="mt-1 text-[12.5px] text-muted">
                            {{ t('payroll.structures.need_component') }}
                            <RouterLink :to="{ name: 'payroll-components' }" class="font-medium text-brand-text hover:underline">{{ t('payroll.components.title') }}</RouterLink>
                        </p>
                    </div>
                </fieldset>
                <div v-if="editing !== 'new'" class="sm:col-span-3">
                    <AppSwitch v-model="form.is_active" :label="t('payroll.common.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-structure" :loading="saving" :disabled="!form.code.trim() || !form.name.en?.trim() || form.items.some((item) => !item.component_id || !String(item.value).trim())">
                    {{ t('payroll.common.save') }}
                </AppButton>
            </template>
        </AppDialog>
    </div>
</template>
