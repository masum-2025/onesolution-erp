<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Lock, Package, Plus, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import { api } from '@/lib/http';
import { amountToMinor, minorToAmount } from '@/lib/billing';
import { formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Create or edit a partner plan: our plan underneath, the partner's own name
 * and prices, and optionally fewer modules. While clients are on it, the
 * base plan and modules are fixed (the server says so too).
 */
const props = defineProps({
    open: Boolean,
    plan: { type: Object, default: null }, // null = new
    basePlans: { type: Array, default: () => [] },
    defaultCurrency: { type: String, default: 'USD' },
});
const emit = defineEmits(['close', 'saved']);

const form = reactive({ base: '', nameEn: '', nameBn: '', descEn: '', descBn: '', allModules: true, modules: [], prices: [] });
const errors = ref({});
const saving = ref(false);

const locked = computed(() => (props.plan?.clients ?? 0) > 0);
const base = computed(() => props.basePlans.find((item) => item.key === form.base) ?? null);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const plan = props.plan;
        errors.value = {};
        Object.assign(form, {
            base: plan?.base_plan.key ?? props.basePlans[0]?.key ?? '',
            nameEn: plan?.name_texts?.en ?? '',
            nameBn: plan?.name_texts?.bn ?? '',
            descEn: plan?.description_texts?.en ?? '',
            descBn: plan?.description_texts?.bn ?? '',
            allModules: !plan?.modules,
            modules: [...(plan?.modules ?? [])],
            prices: (plan?.prices ?? [{ currency: props.defaultCurrency, period: 'monthly', amount_minor: null }]).map((price) => ({
                currency: price.currency,
                period: price.period,
                amount: minorToAmount(price.amount_minor, price.currency),
            })),
        });
    },
);

// Switching the base plan keeps only modules it still has.
watch(
    () => form.base,
    () => {
        const allowed = new Set((base.value?.modules ?? []).map((module) => module.key));
        form.modules = form.modules.filter((key) => allowed.has(key));
    },
);

// Picking a module also picks what it needs; dropping one drops what needs it.
function toggleModule(key, on) {
    const modules = base.value?.modules ?? [];
    const needs = (item) => modules.find((module) => module.key === item)?.requires ?? [];
    const chosen = new Set(form.modules);
    const visit = (item) => {
        if (on ? chosen.has(item) : !chosen.has(item)) return;
        on ? chosen.add(item) : chosen.delete(item);
        on ? needs(item).forEach(visit) : modules.filter((module) => module.requires.includes(item)).forEach((module) => visit(module.key));
    };
    visit(key);
    form.modules = [...chosen];
}

function payload() {
    const texts = (en, bn) => Object.fromEntries(Object.entries({ en: en.trim(), bn: bn.trim() }).filter(([, text]) => text));
    const body = {
        name: texts(form.nameEn, form.nameBn),
        description: form.descEn.trim() || form.descBn.trim() ? texts(form.descEn, form.descBn) : null,
        prices: form.prices.map((price) => ({ currency: price.currency.trim().toUpperCase(), period: price.period, amount_minor: amountToMinor(price.amount, price.currency.trim().toUpperCase()) })),
    };
    if (!locked.value) {
        body.base_plan_key = form.base;
        body.modules = form.allModules ? null : form.modules;
    }
    return body;
}

async function save() {
    errors.value = {};
    const body = payload();
    if (!body.name.en) errors.value['name.en'] = t('billing.plans.dialog.name_required');
    body.prices.forEach((price, index) => {
        if (!/^[A-Z]{3}$/.test(price.currency)) errors.value[`prices.${index}.currency`] = t('billing.plans.dialog.currency_invalid');
        if (price.amount_minor === null) errors.value[`prices.${index}.amount_minor`] = t('billing.plans.dialog.amount_invalid');
    });
    if (!body.prices.length) errors.value.prices = t('billing.plans.dialog.price_required');
    if (Object.keys(errors.value).length) return;

    saving.value = true;
    try {
        const response = props.plan
            ? await api(`/api/partner/plans/${props.plan.id}`, { method: 'PATCH', body })
            : await api('/api/partner/plans', { method: 'POST', body });
        toast.success(response.message);
        emit('saved');
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
        if (['module_needs', 'module_not_in_base', 'plan_in_use'].includes(error.code)) errors.value.modules = error.message;
        else if (!Object.keys(errors.value).length) errors.value.form = error.message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog
        :open="open"
        size="lg"
        :title="plan ? t('billing.plans.dialog.edit_title', { name: plan.name }) : t('billing.plans.dialog.new_title')"
        :description="t('billing.plans.dialog.text')"
        :icon="Package"
        @close="emit('close')"
    >
        <form id="partner-plan-form" class="space-y-5" novalidate @submit.prevent="save">

            <div class="grid gap-4 sm:grid-cols-2">
                <AppField :label="t('billing.plans.dialog.name_en')" :error="errors['name.en']">
                    <template #default="{ id, invalid }">
                        <input :id="id" v-model="form.nameEn" class="field-input" maxlength="60" :placeholder="t('billing.plans.dialog.name_placeholder')" :aria-invalid="invalid || undefined" />
                    </template>
                </AppField>
                <AppField :label="t('billing.plans.dialog.name_bn')" :error="errors['name.bn']" optional>
                    <template #default="{ id }">
                        <input :id="id" v-model="form.nameBn" class="field-input" maxlength="60" lang="bn" />
                    </template>
                </AppField>
                <AppField :label="t('billing.plans.dialog.desc_en')" optional>
                    <template #default="{ id }">
                        <input :id="id" v-model="form.descEn" class="field-input" maxlength="300" />
                    </template>
                </AppField>
                <AppField :label="t('billing.plans.dialog.desc_bn')" optional>
                    <template #default="{ id }">
                        <input :id="id" v-model="form.descBn" class="field-input" maxlength="300" lang="bn" />
                    </template>
                </AppField>
            </div>

            <p v-if="locked" class="flex items-center gap-2 rounded-xl bg-subtle p-3 text-[12.5px] text-fg-2">
                <Lock class="size-4 shrink-0 text-muted" aria-hidden="true" />{{ t('billing.plans.dialog.locked', { count: plan.clients }) }}
            </p>

            <AppField :label="t('billing.plans.dialog.base')" :hint="t('billing.plans.dialog.base_hint')" :error="errors.base_plan_key">
                <template #default="{ id, describedby }">
                    <select :id="id" v-model="form.base" class="field-input" :disabled="locked" :aria-describedby="describedby">
                        <option v-for="item in basePlans" :key="item.key" :value="item.key">{{ item.name }}</option>
                    </select>
                </template>
            </AppField>

            <fieldset class="space-y-2.5" :disabled="locked">
                <legend class="text-[13px] font-medium text-fg">{{ t('billing.plans.dialog.modules') }}</legend>
                <div class="flex items-center justify-between gap-3 rounded-xl border border-line p-3 text-[13px]">
                    <span>
                        <span class="block font-medium text-fg">{{ t('billing.plans.dialog.all_modules') }}</span>
                        <span class="block text-[12px] text-muted">{{ t('billing.plans.dialog.all_modules_hint', { plan: base?.name ?? '' }) }}</span>
                    </span>
                    <AppSwitch v-model="form.allModules" :label="t('billing.plans.dialog.all_modules')" :disabled="locked" />
                </div>
                <div v-if="!form.allModules" class="grid max-h-56 gap-1.5 overflow-y-auto rounded-xl border border-line p-3 sm:grid-cols-2">
                    <label v-for="module in base?.modules ?? []" :key="module.key" class="flex items-center gap-2 text-[13px] text-fg-2">
                        <input type="checkbox" class="size-4 rounded accent-brand" :checked="form.modules.includes(module.key)" @change="toggleModule(module.key, $event.target.checked)" />
                        {{ module.name }}
                    </label>
                </div>
                <p v-if="errors.modules" class="text-[12.5px] text-bad" role="alert">{{ errors.modules }}</p>
            </fieldset>

            <fieldset class="space-y-2.5">
                <legend class="text-[13px] font-medium text-fg">{{ t('billing.plans.dialog.prices') }}</legend>
                <p class="text-[12px] text-muted">
                    {{ t('billing.plans.dialog.prices_hint') }}
                    <template v-if="base?.cost?.kind === 'wholesale' && base.cost.amount_minor !== null">
                        {{ t(`billing.plans.cost_${base.cost.unit}`, { amount: formatMoney({ amount: base.cost.amount_minor, currency: base.cost.currency }) }) }}
                    </template>
                </p>
                <div v-for="(price, index) in form.prices" :key="index" class="grid grid-cols-[5.5rem_1fr_1fr_auto] items-start gap-2">
                    <AppField :label="t('billing.plans.dialog.currency')" :error="errors[`prices.${index}.currency`]">
                        <template #default="{ id, invalid }">
                            <input :id="id" v-model="price.currency" class="field-input uppercase" maxlength="3" dir="ltr" :aria-invalid="invalid || undefined" />
                        </template>
                    </AppField>
                    <AppField :label="t('billing.plans.dialog.period')">
                        <template #default="{ id }">
                            <select :id="id" v-model="price.period" class="field-input">
                                <option value="monthly">{{ t('billing.periods.monthly') }}</option>
                                <option value="yearly">{{ t('billing.periods.yearly') }}</option>
                            </select>
                        </template>
                    </AppField>
                    <AppField :label="t('billing.plans.dialog.amount')" :error="errors[`prices.${index}.amount_minor`]">
                        <template #default="{ id, invalid }">
                            <input :id="id" v-model="price.amount" class="field-input tabular" inputmode="decimal" dir="ltr" placeholder="0.00" :aria-invalid="invalid || undefined" />
                        </template>
                    </AppField>
                    <AppButton class="mt-6" variant="ghost" size="icon" :icon="Trash2" :aria-label="t('billing.plans.dialog.remove_price')" :disabled="form.prices.length === 1" @click="form.prices.splice(index, 1)" />
                </div>
                <p v-if="errors.prices" class="text-[12.5px] text-bad">{{ errors.prices }}</p>
                <AppButton size="sm" variant="ghost" :icon="Plus" @click="form.prices.push({ currency: defaultCurrency, period: 'monthly', amount: '' })">{{ t('billing.plans.dialog.add_price') }}</AppButton>
            </fieldset>

            <p v-if="errors.form" class="rounded-xl bg-bad-soft p-3 text-[13px] text-bad" role="alert">{{ errors.form }}</p>
        </form>

        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton type="submit" form="partner-plan-form" variant="primary" :loading="saving">{{ plan ? t('core.actions.save') : t('billing.plans.dialog.create') }}</AppButton>
        </template>
    </AppDialog>
</template>
