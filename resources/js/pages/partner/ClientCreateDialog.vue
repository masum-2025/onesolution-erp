<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Building2, Plus, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { countries, loadCountries } from '@/lib/countries';
import { cleanTexts, textIn, textsFor } from '@/lib/texts';
import { api } from '@/lib/http';
import { loadPlans, loadSectors } from '@/lib/packaging';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * A new company for a client: on its own (the default), in a new group, or
 * added to a group the partner already serves (billed with that group).
 * First branches are optional; the owner can add more later.
 */
const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'created']);

const sectors = ref([]);
const plans = ref([]);
const groups = ref([]);
const form = reactive({});
const errors = ref({});
const saving = ref(false);

const existing = computed(() => form.structure === 'existing_group');
const structures = computed(() => [
    { value: 'company', label: t('partner.clients.structure.company') },
    { value: 'group', label: t('partner.clients.structure.group') },
    ...(groups.value.length ? [{ value: 'existing_group', label: t('partner.clients.structure.existing_group') }] : []),
]);

onMounted(async () => {
    [sectors.value, plans.value] = await Promise.all([loadSectors().catch(() => []), loadPlans().catch(() => []), loadCountries().catch(() => [])]);
});

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        Object.assign(form, { structure: 'company', names: textsFor(), groupNames: textsFor(), group_id: '', branches: [], sector_key: 'general', plan: 'starter', owner_email: '', owner_name: '', country_code: '' });
        errors.value = {};
        try {
            groups.value = (await api('/api/partner/organizations', { query: { groups: 1, per_page: 100 } })).data.filter((org) => org.status === 'active');
        } catch {
            groups.value = [];
        }
    },
);

function addBranch() {
    form.branches.push(textsFor());
}

async function submit() {
    errors.value = {};
    if (!form.names.en?.trim()) errors.value['name.en'] = t('partner.clients.name_required');
    if (existing.value && !form.group_id) errors.value.group_id = t('partner.clients.group_required');
    if (!existing.value && !/^\S+@\S+\.\S+$/.test(form.owner_email.trim())) errors.value.owner_email = t('partner.clients.email_invalid');
    form.branches.forEach((branch, index) => {
        if (!branch.en?.trim()) errors.value[`branches.${index}.en`] = t('partner.clients.branch_required');
    });
    if (Object.keys(errors.value).length) return;

    const groupName = cleanTexts(form.groupNames);
    saving.value = true;
    try {
        const response = await api('/api/partner/clients', {
            method: 'POST',
            body: {
                structure: form.structure,
                name: cleanTexts(form.names),
                ...(form.structure === 'group' && groupName.en ? { group_name: groupName } : {}),
                ...(existing.value ? { group_id: form.group_id } : { plan: form.plan }),
                ...(form.branches.length ? { branches: form.branches.map(cleanTexts) } : {}),
                sector_key: form.sector_key,
                ...(form.owner_email.trim() ? { owner_email: form.owner_email.trim() } : {}),
                ...(form.owner_name.trim() ? { owner_name: form.owner_name.trim() } : {}),
                ...(form.country_code ? { country_code: form.country_code } : {}),
            },
        });
        toast.success(response.message);
        emit('created');
    } catch (error) {
        if (error.status === 422 && Object.keys(error.errors).length) {
            errors.value = Object.fromEntries(Object.entries(error.errors).map(([key, messages]) => [key, messages[0]]));
        } else {
            errors.value = { form: error.message };
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" size="lg" :title="t('partner.clients.create_title')" :description="t('partner.clients.create_text')" :icon="Building2" @close="emit('close')">
        <form id="client-form" class="space-y-5" novalidate @submit.prevent="submit">
            <p v-if="errors.form" class="rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-2.5 text-[13px] text-fg-2" role="alert">{{ errors.form }}</p>

            <div>
                <AppSegmented v-model="form.structure" block :label="t('partner.clients.structure.label')" :options="structures" />
                <p class="mt-1.5 text-[12.5px] text-muted">{{ t(`partner.clients.structure.${form.structure}_hint`) }}</p>
            </div>

            <AppField v-if="existing" :label="t('partner.clients.group')" :error="errors.group_id">
                <template #default="{ id, invalid }">
                    <select :id="id" v-model="form.group_id" class="field-input" :aria-invalid="invalid || undefined">
                        <option value="" disabled>{{ t('partner.clients.group_pick') }}</option>
                        <option v-for="group in groups" :key="group.id" :value="group.id">{{ textIn(group.name) }}</option>
                    </select>
                </template>
            </AppField>

            <TranslatedFields v-model="form.names" :label="t('partner.clients.name')" required autofocus :errors="errors" />
            <TranslatedFields v-if="form.structure === 'group'" v-model="form.groupNames" :label="t('partner.clients.group_name')" error-prefix="group_name" :errors="errors" />

            <div class="grid gap-4 sm:grid-cols-2">
                <AppField :label="t('partner.clients.sector')" :error="errors.sector_key">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.sector_key" class="field-input">
                            <option v-for="sector in sectors" :key="sector.key" :value="sector.key">{{ sector.name }}</option>
                        </select>
                    </template>
                </AppField>
                <AppField v-if="!existing" :label="t('partner.clients.plan')" :error="errors.plan">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.plan" class="field-input">
                            <option v-for="plan in plans" :key="plan.key" :value="plan.key">{{ plan.name }}</option>
                        </select>
                    </template>
                </AppField>
                <AppField :label="t('partner.clients.owner_email')" :hint="existing ? t('partner.clients.owner_hint_group') : t('partner.clients.owner_hint')" :error="errors.owner_email" :optional="existing">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.owner_email" type="email" class="field-input" autocomplete="off" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('partner.clients.owner_name')" :hint="t('partner.clients.owner_name_hint')" :error="errors.owner_name" optional>
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.owner_name" class="field-input" maxlength="120" autocomplete="off" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('partner.clients.country')" :hint="t('partner.clients.country_hint')" :error="errors.country_code" optional>
                    <template #default="{ id, invalid, describedby }">
                        <select :id="id" v-model="form.country_code" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby">
                            <option value="">{{ t('partner.clients.country_default') }}</option>
                            <option v-for="country in countries.list" :key="country.code" :value="country.code">{{ country.name }}</option>
                        </select>
                    </template>
                </AppField>
            </div>

            <!-- Optional first branches -->
            <fieldset class="rounded-xl border border-line p-4">
                <legend class="px-1.5 text-[13px] font-medium text-fg">{{ t('partner.clients.branches') }}</legend>
                <p class="mb-3 text-[12.5px] text-muted">{{ t('partner.clients.branches_hint') }}</p>
                <div v-for="(branch, index) in form.branches" :key="index" class="mb-3 flex items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <TranslatedFields v-model="form.branches[index]" :label="t('partner.clients.branch_name', { number: index + 1 })" required :errors="errors" :error-prefix="`branches.${index}`" />
                    </div>
                    <AppButton size="icon" variant="ghost" :icon="Trash2" :aria-label="t('partner.clients.branch_remove', { number: index + 1 })" @click="form.branches.splice(index, 1)" />
                </div>
                <AppButton v-if="form.branches.length < 20" size="sm" :icon="Plus" @click="addBranch">{{ t('partner.clients.branch_add') }}</AppButton>
            </fieldset>
        </form>
        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton type="submit" form="client-form" variant="primary" :loading="saving">{{ existing ? t('partner.clients.add_company') : t('partner.clients.create') }}</AppButton>
        </template>
    </AppDialog>
</template>
