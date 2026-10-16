<script setup>
import { onMounted, reactive, ref } from 'vue';
import { Plus } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';

/**
 * Choosing a buyer: CRM organizations tagged "buyer". A new one is added
 * here and kept in CRM (calls, meetings and deals with them stay there).
 */
const props = defineProps({ modelValue: { type: String, default: '' }, id: { type: String, default: undefined }, disabled: Boolean, canAdd: Boolean });
const emit = defineEmits(['update:modelValue']);
const api = factoryApi(currentOrganization().id);

const buyers = ref([]);
const load = async () => {
    try {
        buyers.value = (await api.buyers()).data;
    } catch {
        buyers.value = [];
    }
};
onMounted(load);

const adding = ref(false);
const form = reactive({ name: '', email: '', address: '' });
const errors = ref({});
const busy = ref(false);
async function add() {
    busy.value = true;
    errors.value = {};
    try {
        const { data } = await api.addBuyer({ name: form.name.trim(), email: form.email.trim() || null, address: form.address.trim() || null });
        await load();
        emit('update:modelValue', data.id);
        toast.success(t('factory_erp.buyers.added'));
        adding.value = false;
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
function openAdd() {
    Object.assign(form, { name: '', email: '', address: '' });
    errors.value = {};
    adding.value = true;
}
</script>

<template>
    <div class="flex gap-2">
        <select :id="props.id" class="field-input min-w-0 flex-1" :value="modelValue" :disabled="disabled" @change="emit('update:modelValue', $event.target.value)">
            <option value="" disabled>{{ t('factory_erp.buyers.pick') }}</option>
            <option v-for="buyer in buyers" :key="buyer.id" :value="buyer.id">{{ buyer.name }}</option>
        </select>
        <AppButton v-if="canAdd && !disabled" variant="secondary" :icon="Plus" :aria-label="t('factory_erp.buyers.add')" @click="openAdd">
            <span class="hidden sm:inline">{{ t('factory_erp.buyers.add') }}</span>
        </AppButton>

        <AppDialog :open="adding" layer="z-[60]" :title="t('factory_erp.buyers.add_title')" :description="t('factory_erp.buyers.add_text')" @close="adding = false">
            <form class="grid gap-4" @submit.prevent="form.name.trim().length >= 2 && add()">
                <AppField v-slot="{ id: fieldId }" :label="t('factory_erp.buyers.name')" :error="errors.name?.[0] ?? null">
                    <input :id="fieldId" v-model="form.name" class="field-input" maxlength="150" autofocus />
                </AppField>
                <AppField v-slot="{ id: fieldId }" :label="t('factory_erp.buyers.email')" :error="errors.email?.[0] ?? null" optional>
                    <input :id="fieldId" v-model="form.email" type="email" class="field-input" dir="ltr" maxlength="150" />
                </AppField>
                <AppField v-slot="{ id: fieldId }" :label="t('factory_erp.buyers.address')" optional>
                    <input :id="fieldId" v-model="form.address" class="field-input" maxlength="500" :placeholder="t('factory_erp.buyers.address_hint')" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="adding = false">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="form.name.trim().length < 2" @click="add">{{ t('factory_erp.buyers.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
