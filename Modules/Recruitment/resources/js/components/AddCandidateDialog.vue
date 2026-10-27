<script setup>
import { reactive, ref, watch } from 'vue';
import { UserPlus } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { newOpId } from '../lib';

/** Someone HR adds to an opening by hand (met at a fair, sent by a colleague): counted at once, at the first stage. */
const props = defineProps({
    open: { type: Boolean, default: false },
    openingId: { type: String, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'added']);

const form = reactive({ name: '', email: '', phone: '', cover_note: '', source: 'referral' });
const errors = ref({});
const saving = ref(false);
let opId = newOpId();

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { name: '', email: '', phone: '', cover_note: '', source: 'referral' });
    errors.value = {};
    opId = newOpId();
});

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const response = await props.calls.addCandidate({
            opening_id: props.openingId, name: form.name.trim(), email: form.email.trim(), phone: form.phone.trim() || null,
            cover_note: form.cover_note.trim() || null, source: form.source, op_id: opId,
        });
        toast.success(t('recruitment.add.added'));
        emit('added', response.data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="t('recruitment.add.title')" :description="t('recruitment.add.text')" :icon="UserPlus" @close="emit('close')">
        <form id="recruitment-add" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('recruitment.add.name')" :error="fieldError('name')">
                <input :id="id" v-model="form.name" class="field-input" maxlength="150" autocomplete="off" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('recruitment.add.email')" :error="fieldError('email')">
                <input :id="id" v-model="form.email" type="email" class="field-input" maxlength="190" dir="ltr" autocomplete="off" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('recruitment.add.phone')" :error="fieldError('phone')" optional>
                <input :id="id" v-model="form.phone" class="field-input" maxlength="30" inputmode="tel" dir="ltr" autocomplete="off" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('recruitment.add.source')" :error="fieldError('source')">
                <select :id="id" v-model="form.source" class="field-input">
                    <option value="referral">{{ t('recruitment.sources.referral') }}</option>
                    <option value="manual">{{ t('recruitment.sources.manual') }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('recruitment.add.note')" :error="fieldError('cover_note')" optional class="sm:col-span-2">
                <textarea :id="id" v-model="form.cover_note" rows="3" class="field-input" maxlength="3000"></textarea>
            </AppField>
            <p class="text-[12.5px] text-muted sm:col-span-2">{{ t('recruitment.add.cv_later') }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('recruitment.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="recruitment-add" :loading="saving" :disabled="form.name.trim().length < 2 || !form.email.includes('@')">{{ t('recruitment.add.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
