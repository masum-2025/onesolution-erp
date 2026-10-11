<script setup>
import { computed, reactive, ref } from 'vue';
import { Pencil, Plus, Tags } from 'lucide-vue-next';
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
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';

/**
 * Fee heads: what fees are for, how often each is billed, where its income
 * goes in the books, an optional tax code, and whether sibling discounts
 * and late fines apply. Changed by people who set fees up.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();
const heads = computed(() => setup.data.value?.heads ?? []);
const FREQUENCIES = ['monthly', 'session', 'admission', 'per_credit', 'other'];
const incomeLabel = (key) => setup.data.value?.income_keys.find((item) => item.key === key)?.label ?? key;

const editing = ref(null);
const form = reactive({ code: '', name: {}, frequency: 'monthly', income_key: '', tax_code_id: '', sibling_discount: false, late_fine: true, is_active: true });
const errors = ref({});
const saving = ref(false);
function open(head = null) {
    editing.value = head ?? {};
    Object.assign(form, head
        ? { code: head.code, name: { ...head.name }, frequency: head.frequency, income_key: head.income_key, tax_code_id: head.tax_code_id ?? '', sibling_discount: head.sibling_discount, late_fine: head.late_fine, is_active: head.is_active }
        : { code: '', name: {}, frequency: 'monthly', income_key: setup.data.value?.income_keys[0]?.key ?? '', tax_code_id: '', sibling_discount: false, late_fine: true, is_active: true });
    errors.value = {};
}
async function save() {
    saving.value = true;
    errors.value = {};
    const body = { ...form, tax_code_id: form.tax_code_id || null };
    try {
        if (editing.value?.id) await api.updateHead(editing.value.id, { ...body, base_version: editing.value.version });
        else await api.createHead(body);
        toast.success(t('education_fees.heads.saved'));
        editing.value = null;
        setup.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (error.data?.field) errors.value[error.data.field] = [error.message];
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_fees.heads.title')" :description="t('education_fees.heads.text')">
            <template v-if="setup.can('configure')" #actions>
                <AppButton variant="primary" :icon="Plus" @click="open()">{{ t('education_fees.heads.add') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="5" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <section v-else class="card">
            <EmptyState v-if="!heads.length" :icon="Tags" :title="t('education_fees.heads.empty')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="head in heads" :key="head.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft font-mono text-[11.5px] font-semibold text-brand-text" dir="ltr">{{ head.code }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium text-fg">{{ textIn(head.name) || head.code }}</span>
                        <span class="block text-[12.5px] text-muted">{{ t(`education_fees.frequencies.${head.frequency}`) }} · {{ incomeLabel(head.income_key) }}</span>
                    </span>
                    <AppBadge v-if="head.sibling_discount" tone="brand">{{ t('education_fees.heads.sibling') }}</AppBadge>
                    <AppBadge v-if="!head.is_active" tone="outline">{{ t('education_fees.heads.inactive') }}</AppBadge>
                    <AppButton v-if="setup.can('configure')" size="icon-sm" variant="ghost" :icon="Pencil" :aria-label="t('education_fees.heads.edit')" @click="open(head)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing?.id ? t('education_fees.heads.edit') : t('education_fees.heads.add')" :icon="Tags" @close="editing = null">
            <form id="fee-head" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('education_fees.heads.code')" :error="errors.code?.[0] ?? null">
                    <input :id="id" v-model="form.code" class="field-input font-mono uppercase" maxlength="20" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_fees.heads.frequency')" :error="errors.frequency?.[0] ?? null">
                    <select :id="id" v-model="form.frequency" class="field-input">
                        <option v-for="frequency in FREQUENCIES" :key="frequency" :value="frequency">{{ t(`education_fees.frequencies.${frequency}`) }}</option>
                    </select>
                </AppField>
                <TranslatedFields v-model="form.name" :label="t('education_fees.heads.name')" :errors="errors" class="sm:col-span-2" required />
                <AppField v-slot="{ id }" :label="t('education_fees.heads.income')" :error="errors.income_key?.[0] ?? null">
                    <select :id="id" v-model="form.income_key" class="field-input">
                        <option v-for="income in setup.data.value?.income_keys ?? []" :key="income.key" :value="income.key">{{ income.label }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_fees.heads.tax')" :error="errors.tax_code_id?.[0] ?? null">
                    <select :id="id" v-model="form.tax_code_id" class="field-input">
                        <option value="">{{ t('education_fees.heads.no_tax') }}</option>
                        <option v-for="code in setup.data.value?.tax_codes ?? []" :key="code.id" :value="code.id">{{ code.code }} · {{ code.name }}</option>
                    </select>
                </AppField>
                <div class="grid gap-2 sm:col-span-2">
                    <AppSwitch v-model="form.sibling_discount" :label="t('education_fees.heads.sibling')" show-label />
                    <AppSwitch v-model="form.late_fine" :label="t('education_fees.heads.late_fine')" show-label />
                    <AppSwitch v-model="form.is_active" :label="t('education_fees.heads.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('education_fees.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="fee-head" :loading="saving" :disabled="!form.code.trim() || !form.income_key">{{ t('education_fees.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
