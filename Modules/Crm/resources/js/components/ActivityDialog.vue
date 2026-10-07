<script setup>
import { computed, reactive, ref } from 'vue';
import { CalendarClock } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { currentOrganization, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { useCrmSetup } from '../setup';

/**
 * Log what happened with a contact (a call, visit, note) or plan a
 * follow-up: when, and who does it (reminded before it).
 */
const props = defineProps({ contactId: { type: String, required: true }, dealId: { type: String, default: null }, plan: Boolean });
const emit = defineEmits(['close', 'saved']);
const crm = crmApi(currentOrganization().id);
const setup = useCrmSetup();
const mode = ref(props.plan ? 'plan' : 'log');
const KINDS = computed(() => (mode.value === 'plan' ? ['call', 'meeting', 'visit', 'task', 'email', 'sms'] : ['call', 'meeting', 'visit', 'note', 'email', 'sms']));
const pad = (number) => String(number).padStart(2, '0');
const local = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
const tomorrow = new Date(Date.now() + 86400000);
tomorrow.setHours(10, 0, 0, 0);
const form = reactive({ kind: props.plan ? 'call' : 'note', subject: '', body: '', due: local(tomorrow), assigned_to: session.me?.user?.id ?? '' });
const errors = ref({});
const saving = ref(false);

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const body = {
            op_id: globalThis.crypto?.randomUUID?.() ?? `${Date.now()}`, contact_id: props.contactId, ...(props.dealId ? { deal_id: props.dealId } : {}),
            kind: form.kind, subject: form.subject.trim(), body: form.body.trim() || null,
            ...(mode.value === 'plan' ? { due_at: new Date(form.due).toISOString(), assigned_to: form.assigned_to || null } : {}),
        };
        const { data } = await crm.createActivity(body);
        toast.success(t(mode.value === 'plan' ? 'crm.activities.planned' : 'crm.activities.logged'));
        emit('saved', data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog open :title="t(mode === 'plan' ? 'crm.activities.plan' : 'crm.activities.log')" :icon="CalendarClock" @close="emit('close')">
        <form id="crm-activity" class="grid gap-4" novalidate @submit.prevent="save">
            <AppSegmented v-model="mode" size="sm" :label="t('crm.activities.what')" :options="[{ value: 'log', label: t('crm.activities.log') }, { value: 'plan', label: t('crm.activities.plan') }]" />
            <AppField v-slot="{ id }" :label="t('crm.activities.kind')" :error="errors.kind?.[0]">
                <select :id="id" v-model="form.kind" class="field-input">
                    <option v-for="kind in KINDS" :key="kind" :value="kind">{{ t(`crm.activity_kinds.${kind}`) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.activities.subject')" :hint="t(mode === 'plan' ? 'crm.activities.subject_plan_hint' : 'crm.activities.subject_hint')" :error="errors.subject?.[0]">
                <input :id="id" v-model="form.subject" class="field-input" maxlength="150" />
            </AppField>
            <template v-if="mode === 'plan'">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('crm.activities.when')" :error="errors.due_at?.[0]">
                        <input :id="id" v-model="form.due" type="datetime-local" class="field-input" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('crm.activities.who')" :error="errors.assigned_to?.[0]">
                        <select :id="id" v-model="form.assigned_to" class="field-input">
                            <option v-for="person in setup.data.value?.people ?? []" :key="person.id" :value="person.id">{{ person.name }}</option>
                        </select>
                    </AppField>
                </div>
            </template>
            <AppField v-slot="{ id }" :label="t('crm.activities.body')" :error="errors.body?.[0]" optional>
                <textarea :id="id" v-model="form.body" rows="3" class="field-input" maxlength="5000" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('crm.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="crm-activity" :loading="saving" :disabled="form.subject.trim().length < 2">{{ t('crm.common.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
