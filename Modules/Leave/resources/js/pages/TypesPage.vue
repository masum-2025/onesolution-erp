<script setup>
import { computed, reactive, ref } from 'vue';
import { CalendarOff, ListPlus, PenLine, Plus } from 'lucide-vue-next';
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
import { formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { leaveApi } from '../api';
import { daysText, daysToHalves, halvesToDays } from '../lib';

/**
 * The company's kinds of leave: paid or not, how leave comes (all at the
 * year's start, a twelfth a month, or earned by days at work), how much
 * goes into the next year, and who may take it. "Add the usual ones" adds
 * the kinds the country rule lists that the company does not have yet.
 * Switched off, never removed.
 */
const org = currentOrganization();
const leave = leaveApi(org.id);
const list = useResource(() => leave.types());
const types = computed(() => list.data.value?.data ?? []);
const canManage = computed(() => !!list.data.value?.meta?.can_manage);

const adding = ref(false);
async function addDefaults() {
    adding.value = true;
    try {
        const response = await leave.addDefaults();
        toast.success(response.message);
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        adding.value = false;
    }
}

const editing = ref(null);
const saving = ref(false);
const errors = ref({});
const blank = () => ({
    code: '', name: { en: '', bn: '' }, paid: true, accrual: 'yearly', allowance_days: 10, worked_days_per_day: 18, carry_max_days: 0,
    half_day: true, counts_days_off: false, document_after_days: '', gender: '', encashable: false, is_active: true,
});
const form = reactive(blank());

function edit(type = null) {
    Object.assign(form, blank(), type ? {
        code: type.code, name: { en: type.names.en ?? '', bn: type.names.bn ?? '' }, paid: type.paid, accrual: type.accrual,
        allowance_days: halvesToDays(type.allowance_halves), worked_days_per_day: type.worked_days_per_day ?? 18, carry_max_days: halvesToDays(type.carry_max_halves),
        half_day: type.half_day, counts_days_off: type.counts_days_off, document_after_days: type.document_after_days ?? '', gender: type.gender ?? '',
        encashable: type.encashable, is_active: type.is_active,
    } : {});
    errors.value = {};
    editing.value = type ?? 'new';
}

async function save() {
    const body = {
        code: form.code.trim(),
        name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())),
        paid: form.paid,
        accrual: form.accrual,
        allowance_halves: form.accrual === 'worked_days' ? 0 : daysToHalves(form.allowance_days),
        worked_days_per_day: form.accrual === 'worked_days' ? Number(form.worked_days_per_day) || null : null,
        carry_max_halves: daysToHalves(form.carry_max_days),
        half_day: form.half_day,
        counts_days_off: form.counts_days_off,
        document_after_days: form.document_after_days === '' ? null : Number(form.document_after_days),
        gender: form.gender || null,
        encashable: form.encashable,
    };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await leave.createType(body);
        else await leave.updateType(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('leave.types.saved'));
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

function howItComes(type) {
    if (type.accrual === 'worked_days') return t('leave.types.comes_worked', { days: formatNumber(type.worked_days_per_day ?? 0) });
    return t(`leave.types.comes_${type.accrual}`, { days: daysText(type.allowance_halves) });
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t('leave.types.title')" :description="t('leave.types.text')">
            <template v-if="canManage" #actions>
                <AppButton :icon="ListPlus" :loading="adding" @click="addDefaults">{{ t('leave.types.add_defaults') }}</AppButton>
                <AppButton variant="primary" :icon="Plus" @click="edit()">{{ t('leave.types.add') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!types.length" :icon="CalendarOff" :title="t('leave.types.empty')" :text="t('leave.types.empty_text')" compact>
                <AppButton v-if="canManage" variant="primary" :icon="ListPlus" :loading="adding" @click="addDefaults">{{ t('leave.types.add_defaults') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="type in types" :key="type.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ type.name }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ type.code }}</span></span>
                        <span class="block text-[12.5px] text-muted">
                            {{ howItComes(type) }}
                            <template v-if="type.carry_max_halves"> · {{ t('leave.types.carry_up_to', { days: daysText(type.carry_max_halves) }) }}</template>
                            <template v-if="type.counts_days_off"> · {{ t('leave.types.counts_days_off_short') }}</template>
                            <template v-if="type.document_after_days"> · {{ t('leave.types.document_short', { days: formatNumber(type.document_after_days) }) }}</template>
                        </span>
                    </span>
                    <AppBadge :tone="type.paid ? 'ok' : 'neutral'">{{ type.paid ? t('leave.common.paid') : t('leave.common.unpaid') }}</AppBadge>
                    <AppBadge v-if="type.gender" tone="outline">{{ t(`leave.types.only_${type.gender}`) }}</AppBadge>
                    <AppBadge v-if="!type.is_active" tone="neutral">{{ t('leave.types.off') }}</AppBadge>
                    <AppButton v-if="canManage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('leave.types.edit')" @click="edit(type)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('leave.types.add') : t('leave.types.edit')" @close="editing = null">
            <form id="leave-type" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('leave.types.code')" :error="fieldError('code')">
                    <input :id="id" v-model="form.code" class="field-input font-mono uppercase" dir="ltr" maxlength="20" />
                </AppField>
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.name" :label="t('leave.types.name')" :errors="errors" required :maxlength="80" />
                </div>
                <AppField v-slot="{ id }" :label="t('leave.types.accrual')" :error="fieldError('accrual')">
                    <select :id="id" v-model="form.accrual" class="field-input">
                        <option v-for="value in ['yearly', 'monthly', 'worked_days']" :key="value" :value="value">{{ t(`leave.types.accrual_${value}`) }}</option>
                    </select>
                </AppField>
                <AppField v-if="form.accrual !== 'worked_days'" v-slot="{ id }" :label="t('leave.types.allowance')" :error="fieldError('allowance_halves')">
                    <input :id="id" v-model.number="form.allowance_days" type="number" min="0" max="365" step="0.5" class="field-input tabular text-end" />
                </AppField>
                <AppField v-else v-slot="{ id }" :label="t('leave.types.worked_days')" :error="fieldError('worked_days_per_day')">
                    <input :id="id" v-model.number="form.worked_days_per_day" type="number" min="1" max="365" class="field-input tabular text-end" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('leave.types.carry')" :hint="t('leave.types.carry_hint')" :error="fieldError('carry_max_halves')">
                    <input :id="id" v-model.number="form.carry_max_days" type="number" min="0" max="365" step="0.5" class="field-input tabular text-end" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('leave.types.document')" :hint="t('leave.types.document_hint')" :error="fieldError('document_after_days')" optional>
                    <input :id="id" v-model="form.document_after_days" type="number" min="1" max="365" class="field-input tabular text-end" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('leave.types.gender')" :error="fieldError('gender')">
                    <select :id="id" v-model="form.gender" class="field-input">
                        <option value="">{{ t('leave.types.everyone') }}</option>
                        <option value="female">{{ t('leave.types.only_female') }}</option>
                        <option value="male">{{ t('leave.types.only_male') }}</option>
                    </select>
                </AppField>
                <div class="grid gap-3 sm:col-span-3 sm:grid-cols-2">
                    <AppSwitch v-model="form.paid" :label="t('leave.types.paid')" show-label />
                    <AppSwitch v-model="form.half_day" :label="t('leave.types.half_day')" show-label />
                    <AppSwitch v-model="form.counts_days_off" :label="t('leave.types.counts_days_off')" show-label />
                    <AppSwitch v-model="form.encashable" :label="t('leave.types.encashable')" show-label />
                    <AppSwitch v-if="editing !== 'new'" v-model="form.is_active" :label="t('leave.types.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('leave.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="leave-type" :loading="saving" :disabled="!form.code.trim() || !form.name.en?.trim()">{{ t('leave.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
