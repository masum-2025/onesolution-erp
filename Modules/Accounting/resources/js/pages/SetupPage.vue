<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowLeft, BookCheck, Sparkles } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currencyName } from '@/lib/display';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';

/**
 * Setting up the books once: a starting list of accounts (the sector's
 * suggestion first) and, if wanted, another first day for the first fiscal
 * year than the company's fiscal year start.
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const router = useRouter();

const status = useResource(() => books.setup());
const setup = computed(() => status.data.value?.data ?? null);
const form = reactive({ template: '', first_year_starts_on: '' });
const errors = ref({});
const saving = ref(false);

watch(setup, (value) => {
    if (value && !form.template) form.template = value.suggested_template;
});

async function start() {
    saving.value = true;
    errors.value = {};
    try {
        await books.setUp({ template: form.template, ...(form.first_year_starts_on ? { first_year_starts_on: form.first_year_starts_on } : {}) });
        toast.success(t('accounting.setup.done'));
        router.push({ name: 'accounting-accounts' });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
        status.reload();
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t('accounting.setup.title')" :description="t('accounting.setup.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'accounting' }" :icon="ArrowLeft">{{ t('accounting.journal.back') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="status.loading.value && !setup" class="card"><SkeletonRows :rows="4" /></section>
        <section v-else-if="status.error.value" class="card"><ErrorState compact :error="status.error.value" @retry="status.reload()" /></section>
        <section v-else-if="setup?.set_up" class="card">
            <EmptyState :icon="BookCheck" :title="t('accounting.setup.already_title')" :text="t('accounting.setup.already_text')">
                <AppButton variant="primary" :to="{ name: 'accounting-accounts' }">{{ t('accounting.links.accounts') }}</AppButton>
            </EmptyState>
        </section>
        <section v-else-if="setup && !can('accounting.manage')" class="card">
            <EmptyState :icon="BookCheck" :title="t('accounting.not_set_up.title')" :text="t('accounting.setup.no_permission')" />
        </section>

        <form v-else-if="setup" class="card grid gap-5 p-5" novalidate @submit.prevent="start">
            <fieldset class="grid gap-2">
                <legend class="mb-1 text-[13px] font-medium text-fg">{{ t('accounting.setup.template') }}</legend>
                <label
                    v-for="template in setup.templates"
                    :key="template.key"
                    class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 transition"
                    :class="form.template === template.key ? 'border-brand bg-brand-soft/60' : 'border-line hover:bg-subtle/60'"
                >
                    <input v-model="form.template" type="radio" name="template" :value="template.key" class="accent-brand" />
                    <span class="flex-1 text-[14px] font-medium">{{ template.label }}</span>
                    <span v-if="template.key === setup.suggested_template" class="inline-flex items-center gap-1 text-[12px] text-brand-text">
                        <Sparkles class="size-3.5" aria-hidden="true" />{{ t('accounting.setup.suggested') }}
                    </span>
                </label>
                <p v-if="fieldError('template')" class="text-[12.5px] text-bad">{{ fieldError('template') }}</p>
            </fieldset>

            <AppField v-slot="{ id }" :label="t('accounting.setup.first_year')" :hint="t('accounting.setup.first_year_hint', { date: setup.fiscal_year_start })" :error="fieldError('first_year_starts_on')" optional class="sm:max-w-xs">
                <input :id="id" v-model="form.first_year_starts_on" type="date" class="field-input" />
            </AppField>

            <p class="text-[13px] text-muted">{{ t('accounting.setup.currency', { currency: currencyName(setup.currency) }) }}</p>

            <div class="flex justify-end">
                <AppButton variant="primary" type="submit" :loading="saving" :disabled="!form.template">{{ t('accounting.setup.start') }}</AppButton>
            </div>
        </form>
    </div>
</template>
