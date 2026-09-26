<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { Building2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { api } from '@/lib/http';
import { loadPlans, loadSectors } from '@/lib/packaging';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Create a client account: a group with its first company (sector package
 * applied) and an owner who already has an account.
 */
const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'created']);

const sectors = ref([]);
const plans = ref([]);
const form = reactive({});
const errors = ref({});
const saving = ref(false);

onMounted(async () => {
    [sectors.value, plans.value] = await Promise.all([loadSectors().catch(() => []), loadPlans().catch(() => [])]);
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        Object.assign(form, { name_en: '', name_bn: '', sector_key: 'general', plan: 'starter', owner_email: '', country_code: '' });
        errors.value = {};
    },
);

async function submit() {
    errors.value = {};
    if (!form.name_en.trim()) errors.value.name_en = t('partner.clients.name_required');
    if (!/^\S+@\S+\.\S+$/.test(form.owner_email.trim())) errors.value.owner_email = t('partner.clients.email_invalid');
    if (Object.keys(errors.value).length) return;

    saving.value = true;
    try {
        const response = await api('/api/partner/clients', {
            method: 'POST',
            body: {
                name: { en: form.name_en.trim(), ...(form.name_bn.trim() ? { bn: form.name_bn.trim() } : {}) },
                sector_key: form.sector_key,
                plan: form.plan,
                owner_email: form.owner_email.trim(),
                ...(form.country_code.trim() ? { country_code: form.country_code.trim().toUpperCase() } : {}),
            },
        });
        toast.success(response.message);
        emit('created');
    } catch (error) {
        if (error.status === 422 && Object.keys(error.errors).length) {
            errors.value = Object.fromEntries(Object.entries(error.errors).map(([key, messages]) => [key.replace('name.', 'name_'), messages[0]]));
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
        <form id="client-form" class="space-y-4" novalidate @submit.prevent="submit">
            <p v-if="errors.form" class="rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-2.5 text-[13px] text-fg-2" role="alert">{{ errors.form }}</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <AppField :label="t('partner.clients.name')" :error="errors.name_en">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.name_en" data-autofocus class="field-input" maxlength="150" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('partner.clients.name_bn')" optional>
                    <template #default="{ id }">
                        <input :id="id" v-model="form.name_bn" class="field-input" maxlength="150" lang="bn" />
                    </template>
                </AppField>
                <AppField :label="t('partner.clients.sector')" :error="errors.sector_key">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.sector_key" class="field-input">
                            <option v-for="sector in sectors" :key="sector.key" :value="sector.key">{{ sector.name }}</option>
                        </select>
                    </template>
                </AppField>
                <AppField :label="t('partner.clients.plan')" :error="errors.plan">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.plan" class="field-input">
                            <option v-for="plan in plans" :key="plan.key" :value="plan.key">{{ plan.name }}</option>
                        </select>
                    </template>
                </AppField>
                <AppField :label="t('partner.clients.owner_email')" :hint="t('partner.clients.owner_hint')" :error="errors.owner_email">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.owner_email" type="email" class="field-input" autocomplete="off" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('partner.clients.country')" :hint="t('partner.clients.country_hint')" :error="errors.country_code" optional>
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.country_code" class="field-input uppercase" maxlength="2" placeholder="BD" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
            </div>
        </form>
        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton type="submit" form="client-form" variant="primary" :loading="saving">{{ t('partner.clients.create') }}</AppButton>
        </template>
    </AppDialog>
</template>
