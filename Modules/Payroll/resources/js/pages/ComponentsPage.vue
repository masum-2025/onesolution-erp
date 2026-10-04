<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowLeft, Minus, PenLine, Plus, Tags } from 'lucide-vue-next';
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
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';

/**
 * The parts a salary is made of (house rent, medical, provident fund …):
 * an earning or a deduction, taxed or not, cut for days not worked or not.
 * Switched off, never removed, once used.
 */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const list = useResource(() => payroll.components());
const components = computed(() => list.data.value?.data ?? []);

const editing = ref(null);
const saving = ref(false);
const errors = ref({});
const form = reactive({ code: '', name: { en: '', bn: '' }, kind: 'earning', taxable: true, prorated: true, sort: 0, is_active: true });

function edit(component = null) {
    Object.assign(form, {
        code: component?.code ?? '',
        name: { en: component?.names.en ?? '', bn: component?.names.bn ?? '' },
        kind: component?.kind ?? 'earning',
        taxable: component?.taxable ?? true,
        prorated: component?.prorated ?? true,
        sort: component?.sort ?? (components.value.length + 1) * 10,
        is_active: component?.is_active ?? true,
    });
    errors.value = {};
    editing.value = component ?? 'new';
}

async function save() {
    const body = {
        code: form.code.trim(),
        name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())),
        taxable: form.kind === 'earning' && form.taxable,
        prorated: form.prorated,
        sort: Number(form.sort) || 0,
    };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await payroll.createComponent({ ...body, kind: form.kind });
        else await payroll.updateComponent(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('payroll.components.saved'));
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

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t('payroll.components.title')" :description="t('payroll.components.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'payroll-employees' }" :icon="ArrowLeft">{{ t('payroll.common.back') }}</AppButton>
                <AppButton v-if="can('payroll.run')" variant="primary" :icon="Plus" @click="edit()">{{ t('payroll.components.add') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!components.length" :icon="Tags" :title="t('payroll.components.empty')" :text="t('payroll.components.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="component in components" :key="component.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="component.kind === 'earning' ? 'bg-ok-soft text-ok' : 'bg-bad-soft text-bad'">
                        <component :is="component.kind === 'earning' ? Plus : Minus" class="size-5" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ component.name }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ component.code }}</span></span>
                        <span class="block text-[12.5px] text-muted">
                            {{ t(`payroll.kinds.${component.kind}`) }}
                            <template v-if="component.kind === 'earning'"> · {{ t(component.taxable ? 'payroll.components.taxed' : 'payroll.components.not_taxed') }}</template>
                            · {{ t(component.prorated ? 'payroll.components.prorated' : 'payroll.components.full') }}
                        </span>
                    </span>
                    <AppBadge v-if="!component.is_active" tone="neutral">{{ t('payroll.common.off') }}</AppBadge>
                    <AppButton v-if="can('payroll.run')" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('payroll.components.edit')" @click="edit(component)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('payroll.components.add') : t('payroll.components.edit')" @close="editing = null">
            <form id="payroll-component" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('payroll.common.code')" :error="fieldError('code')">
                    <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="20" />
                </AppField>
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.name" :label="t('payroll.common.name')" :errors="errors" required :maxlength="80" />
                </div>
                <AppField v-slot="{ id }" :label="t('payroll.run.kind')" :hint="editing !== 'new' ? t('payroll.components.kind_fixed') : ''">
                    <select :id="id" v-model="form.kind" class="field-input" :disabled="editing !== 'new'">
                        <option value="earning">{{ t('payroll.kinds.earning') }}</option>
                        <option value="deduction">{{ t('payroll.kinds.deduction') }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.components.sort')" :error="fieldError('sort')">
                    <input :id="id" v-model.number="form.sort" type="number" min="0" max="999" class="field-input tabular text-end" />
                </AppField>
                <div class="grid gap-3 sm:col-span-3">
                    <AppSwitch v-if="form.kind === 'earning'" v-model="form.taxable" :label="t('payroll.components.taxable')" show-label />
                    <AppSwitch v-model="form.prorated" :label="t('payroll.components.prorate')" show-label />
                    <AppSwitch v-if="editing !== 'new'" v-model="form.is_active" :label="t('payroll.common.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-component" :loading="saving" :disabled="!form.code.trim() || !form.name.en?.trim()">{{ t('payroll.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
