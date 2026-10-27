<script setup>
import { computed, reactive, ref } from 'vue';
import { ListPlus, PenLine, Plus, ReceiptText } from 'lucide-vue-next';
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
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { expensesApi } from '../api';

/**
 * The company's kinds of expense: where each goes in the books (one of the
 * module's accounts, mapped by Accounting), and whether it is claimed by
 * kilometre. "Add the usual ones" adds the kinds the rule lists that the
 * company does not have yet. Switched off, never removed.
 */
const org = currentOrganization();
const calls = expensesApi(org.id);
const list = useResource(() => calls.categories());
const kinds = computed(() => list.data.value?.data ?? []);
const canManage = computed(() => !!list.data.value?.meta?.can_manage);
const postingKeys = computed(() => list.data.value?.meta?.posting_keys ?? []);
const keyLabel = (key) => t(`expenses.categories.keys.${key.replace('expenses.', '')}`);

const adding = ref(false);
async function addDefaults() {
    adding.value = true;
    try {
        toast.success((await calls.addDefaults()).message);
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
const blank = () => ({ code: '', name: { en: '', bn: '' }, posting_key: 'expenses.other', mileage: false, per_diem: false, is_active: true });
const form = reactive(blank());

function edit(kind = null) {
    Object.assign(form, blank(), kind ? { code: kind.code, name: { en: kind.names.en ?? '', bn: kind.names.bn ?? '' }, posting_key: kind.posting_key, mileage: kind.mileage, per_diem: kind.per_diem, is_active: kind.is_active } : {});
    errors.value = {};
    editing.value = kind ?? 'new';
}

async function save() {
    const body = {
        code: form.code.trim(),
        name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())),
        posting_key: form.posting_key,
        mileage: form.mileage,
        per_diem: form.per_diem && !form.mileage,
    };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await calls.createCategory(body);
        else await calls.updateCategory(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('expenses.categories.saved'));
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
        <PageHeader :title="t('expenses.categories.title')" :description="t('expenses.categories.text')">
            <template v-if="canManage" #actions>
                <AppButton :icon="ListPlus" :loading="adding" @click="addDefaults">{{ t('expenses.categories.add_defaults') }}</AppButton>
                <AppButton variant="primary" :icon="Plus" @click="edit()">{{ t('expenses.categories.add') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!kinds.length" :icon="ReceiptText" :title="t('expenses.categories.empty')" :text="t('expenses.categories.empty_text')" compact>
                <AppButton v-if="canManage" variant="primary" :icon="ListPlus" :loading="adding" @click="addDefaults">{{ t('expenses.categories.add_defaults') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="kind in kinds" :key="kind.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ kind.name }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ kind.code }}</span></span>
                        <span class="block text-[12.5px] text-muted">{{ t('expenses.categories.goes_to', { account: keyLabel(kind.posting_key) }) }}</span>
                    </span>
                    <AppBadge v-if="kind.mileage" tone="brand">{{ t('expenses.categories.by_km') }}</AppBadge>
                    <AppBadge v-if="kind.per_diem" tone="brand">{{ t('expenses.categories.by_day') }}</AppBadge>
                    <AppBadge v-if="!kind.is_active" tone="neutral">{{ t('expenses.categories.off') }}</AppBadge>
                    <AppButton v-if="canManage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('expenses.categories.edit')" @click="edit(kind)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('expenses.categories.add') : t('expenses.categories.edit')" @close="editing = null">
            <form id="expenses-kind" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('expenses.categories.code')" :error="fieldError('code')">
                    <input :id="id" v-model="form.code" class="field-input font-mono uppercase" dir="ltr" maxlength="20" />
                </AppField>
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.name" :label="t('expenses.categories.name')" :errors="errors" required :maxlength="80" />
                </div>
                <AppField v-slot="{ id }" :label="t('expenses.categories.account')" :hint="t('expenses.categories.account_hint')" :error="fieldError('posting_key')" class="sm:col-span-3">
                    <select :id="id" v-model="form.posting_key" class="field-input">
                        <option v-for="key in postingKeys" :key="key" :value="key">{{ keyLabel(key) }}</option>
                    </select>
                </AppField>
                <div class="grid gap-3 sm:col-span-3 sm:grid-cols-2">
                    <AppSwitch v-model="form.mileage" :label="t('expenses.categories.mileage')" show-label :disabled="form.per_diem" />
                    <AppSwitch v-model="form.per_diem" :label="t('expenses.categories.per_diem')" show-label :disabled="form.mileage" />
                    <AppSwitch v-if="editing !== 'new'" v-model="form.is_active" :label="t('expenses.categories.active')" show-label />
                </div>
                <p v-if="form.mileage" class="text-[12.5px] text-muted sm:col-span-3">{{ t('expenses.categories.mileage_hint') }}</p>
                <p v-if="form.per_diem" class="text-[12.5px] text-muted sm:col-span-3">{{ t('expenses.categories.per_diem_hint') }}</p>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('expenses.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="expenses-kind" :loading="saving" :disabled="!form.code.trim() || !form.name.en?.trim()">{{ t('expenses.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
