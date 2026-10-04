<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowLeft, Percent, PenLine, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDecimal } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { bpToPercent, percentToBp } from '../lib';
import BooksGate from '../components/BooksGate.vue';

/**
 * The company's tax codes (from its country's profile, then its own): rate,
 * kind and side. People who look after tax add, rename, change and switch
 * them off; documents keep the rate they were written with.
 */
const books = accountingApi(currentOrganization().id);
const list = useResource(() => books.taxCodes());
const codes = computed(() => list.data.value?.data ?? []);
const kinds = ['standard', 'reduced', 'zero', 'exempt'];
const sides = ['both', 'sales', 'purchases'];

const editing = ref(null);
const saving = ref(false);
const errors = ref({});
const form = reactive({ code: '', name_en: '', name_bn: '', rate: '', kind: 'standard', applies_to: 'both', is_active: true });

function edit(code = null) {
    Object.assign(form, {
        code: code?.code ?? '',
        name_en: code?.names.en ?? '',
        name_bn: code?.names.bn ?? '',
        rate: code ? bpToPercent(code.rate_bp) : '',
        kind: code?.kind ?? 'standard',
        applies_to: code?.applies_to ?? 'both',
        is_active: code?.is_active ?? true,
    });
    errors.value = {};
    editing.value = code ?? 'new';
}

async function save() {
    const rate = percentToBp(form.rate);
    if (rate === null) {
        errors.value = { rate_bp: [t('accounting.tax.invalid_rate')] };
        return;
    }
    const body = {
        code: form.code.trim(),
        name: { en: form.name_en.trim(), ...(form.name_bn.trim() ? { bn: form.name_bn.trim() } : {}) },
        rate_bp: rate,
        kind: form.kind,
        applies_to: form.applies_to,
    };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await books.createTaxCode(body);
        else await books.updateTaxCode(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('accounting.tax.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('accounting.common.conflict'));
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
        <PageHeader :title="t('accounting.tax.title')" :description="t('accounting.tax.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'accounting' }" :icon="ArrowLeft">{{ t('accounting.journal.back') }}</AppButton>
                <AppButton v-if="can('accounting.tax')" variant="primary" :icon="Plus" @click="edit()">{{ t('accounting.tax.add') }}</AppButton>
            </template>
        </PageHeader>

        <BooksGate>
            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!codes.length" :icon="Percent" :title="t('accounting.tax.empty_title')" :text="t('accounting.tax.empty_text')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="code in codes" :key="code.id" class="flex items-center gap-3 px-5 py-3">
                        <span class="w-16 shrink-0 text-end text-[15px] font-semibold tabular">{{ formatDecimal(bpToPercent(code.rate_bp)) }}%</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium">{{ code.name }}</span>
                            <span class="block text-[12px] text-muted"><span class="font-mono" dir="ltr">{{ code.code }}</span> · {{ t(`accounting.tax.kinds.${code.kind}`) }} · {{ t(`accounting.tax.sides.${code.applies_to}`) }}</span>
                        </span>
                        <AppBadge v-if="!code.is_active" tone="neutral">{{ t('accounting.tax.off') }}</AppBadge>
                        <AppButton v-if="can('accounting.tax')" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('accounting.tax.edit')" @click="edit(code)" />
                    </li>
                </ul>
            </section>
            <p class="mt-3 text-[12.5px] text-muted">{{ t('accounting.tax.review_note') }}</p>
        </BooksGate>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('accounting.tax.add') : t('accounting.tax.edit')" @close="editing = null">
            <form id="accounting-tax-code" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('accounting.tax.code')" :error="fieldError('code')">
                    <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="20" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.tax.rate')" :error="fieldError('rate_bp')">
                    <div class="relative">
                        <input :id="id" v-model="form.rate" inputmode="decimal" class="field-input tabular pe-8 text-end" dir="ltr" autocomplete="off" />
                        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted">%</span>
                    </div>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.accounts.name_en')" :error="fieldError('name.en') ?? fieldError('name')">
                    <input :id="id" v-model="form.name_en" class="field-input" lang="en" maxlength="80" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.accounts.name_bn')" :error="fieldError('name.bn')" optional>
                    <input :id="id" v-model="form.name_bn" class="field-input" lang="bn" maxlength="80" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.tax.kind')" :error="fieldError('kind')">
                    <select :id="id" v-model="form.kind" class="field-input">
                        <option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`accounting.tax.kinds.${kind}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.tax.applies_to')" :error="fieldError('applies_to')">
                    <select :id="id" v-model="form.applies_to" class="field-input">
                        <option v-for="value in sides" :key="value" :value="value">{{ t(`accounting.tax.sides.${value}`) }}</option>
                    </select>
                </AppField>
                <div v-if="editing !== 'new'" class="sm:col-span-2">
                    <AppSwitch v-model="form.is_active" :label="t('accounting.tax.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('accounting.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="accounting-tax-code" :loading="saving" :disabled="!form.code.trim() || !form.name_en.trim() || form.rate === ''">{{ t('accounting.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
