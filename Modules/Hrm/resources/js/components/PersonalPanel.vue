<script setup>
import { computed, reactive, ref } from 'vue';
import { Eye, EyeOff, PenLine } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import { formatDate } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { changedDetails } from '../lib';

/**
 * Personal details: read, edit (only the changed fields, with the version
 * seen), and the full national and tax ids on request (audited, may ask for
 * the second step again).
 */
const props = defineProps({
    employee: { type: Object, required: true },
    hrm: { type: Object, required: true },
    options: { type: Object, default: null },
    canEdit: Boolean,
});
const emit = defineEmits(['saved', 'conflict']);

const FIELDS = ['full_name', 'full_name_local', 'date_of_birth', 'gender', 'phone', 'email'];
const editing = ref(false);
const saving = ref(false);
const errors = ref({});
const form = reactive({});
const revealed = ref(null);
const revealing = ref(false);

const idLabel = computed(() => props.options?.national_id.label ?? t('hrm.fields.national_id'));
const genderLabel = computed(() => props.options?.genders.find((gender) => gender.value === props.employee.gender)?.label ?? props.employee.gender);

function start() {
    for (const field of FIELDS) form[field] = props.employee[field] ?? '';
    form.national_id = '';
    errors.value = {};
    editing.value = true;
}

async function save() {
    const changes = changedDetails(Object.fromEntries(FIELDS.map((field) => [field, props.employee[field] ?? null])), Object.fromEntries(FIELDS.map((field) => [field, form[field]])));
    // The national id is masked here: only a newly typed one is sent.
    if (form.national_id.trim()) changes.national_id = form.national_id.trim();
    if (!Object.keys(changes).length) {
        toast.info(t('hrm.profile.nothing_changed'));
        editing.value = false;
        return;
    }

    saving.value = true;
    errors.value = {};
    try {
        const { data } = await props.hrm.update(props.employee.id, { base_version: props.employee.version, ...changes });
        toast.success(t('hrm.profile.saved'));
        editing.value = false;
        revealed.value = null;
        emit('saved', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('hrm.profile.conflict'));
            editing.value = false;
            emit('conflict');
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function reveal() {
    if (revealed.value) {
        revealed.value = null;
        return;
    }
    revealing.value = true;
    try {
        revealed.value = (await props.hrm.sensitive(props.employee.id)).data;
    } catch (error) {
        toast.error(error.message);
    } finally {
        revealing.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <section class="card p-5 sm:p-6">
        <form v-if="editing" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('hrm.fields.full_name')" :error="fieldError('full_name')" class="sm:col-span-2">
                <input :id="id" v-model="form.full_name" class="field-input" maxlength="150" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('hrm.fields.full_name_local')" :error="fieldError('full_name_local')" optional class="sm:col-span-2">
                <input :id="id" v-model="form.full_name_local" class="field-input" lang="bn" maxlength="150" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('hrm.fields.phone')" :error="fieldError('phone')">
                <input :id="id" v-model="form.phone" type="tel" class="field-input" dir="ltr" maxlength="30" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('hrm.fields.email')" :error="fieldError('email')" optional>
                <input :id="id" v-model="form.email" type="email" class="field-input" dir="ltr" maxlength="190" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('hrm.fields.date_of_birth')" :error="fieldError('date_of_birth')" optional>
                <input :id="id" v-model="form.date_of_birth" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('hrm.fields.gender')" :error="fieldError('gender')" optional>
                <select :id="id" v-model="form.gender" class="field-input">
                    <option value="">–</option>
                    <option v-for="gender in options?.genders ?? []" :key="gender.value" :value="gender.value">{{ gender.label }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="idLabel" :hint="employee.national_id ?? ''" :error="fieldError('national_id')" optional class="sm:col-span-2">
                <input :id="id" v-model="form.national_id" class="field-input" dir="ltr" autocomplete="off" maxlength="40" />
            </AppField>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <AppButton variant="ghost" @click="editing = false">{{ t('hrm.profile.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" :loading="saving">{{ t('hrm.profile.save') }}</AppButton>
            </div>
        </form>

        <template v-else>
            <div class="mb-4 flex items-center justify-end">
                <AppButton v-if="canEdit" size="sm" :icon="PenLine" @click="start">{{ t('hrm.profile.edit') }}</AppButton>
            </div>
            <dl class="grid gap-x-6 gap-y-4 text-[13.5px] sm:grid-cols-2">
                <div><dt class="text-muted">{{ t('hrm.fields.full_name') }}</dt><dd class="font-medium text-fg">{{ employee.full_name }}</dd></div>
                <div><dt class="text-muted">{{ t('hrm.fields.full_name_local') }}</dt><dd class="font-medium text-fg" lang="bn">{{ employee.full_name_local || '–' }}</dd></div>
                <div><dt class="text-muted">{{ t('hrm.fields.phone') }}</dt><dd class="font-medium text-fg" dir="ltr">{{ employee.phone || '–' }}</dd></div>
                <div><dt class="text-muted">{{ t('hrm.fields.email') }}</dt><dd class="font-medium break-all text-fg" dir="ltr">{{ employee.email || '–' }}</dd></div>
                <div><dt class="text-muted">{{ t('hrm.fields.date_of_birth') }}</dt><dd class="font-medium text-fg">{{ employee.date_of_birth ? formatDate(employee.date_of_birth) : '–' }}</dd></div>
                <div><dt class="text-muted">{{ t('hrm.fields.gender') }}</dt><dd class="font-medium text-fg">{{ genderLabel || '–' }}</dd></div>
                <div>
                    <dt class="text-muted">{{ idLabel }}</dt>
                    <dd class="font-mono font-medium text-fg" dir="ltr">{{ revealed ? revealed.national_id || '–' : employee.national_id || '–' }}</dd>
                </div>
                <div>
                    <dt class="text-muted">{{ t('hrm.fields.tax_id') }}</dt>
                    <dd class="font-mono font-medium text-fg" dir="ltr">{{ revealed ? revealed.tax_id || '–' : employee.tax_id || '–' }}</dd>
                </div>
            </dl>
            <div v-if="options?.can.view_sensitive && (employee.national_id || employee.tax_id)" class="mt-4 flex flex-wrap items-center gap-3">
                <AppButton size="sm" variant="ghost" :icon="revealed ? EyeOff : Eye" :loading="revealing" @click="reveal">
                    {{ revealed ? t('hrm.profile.hide') : t('hrm.profile.reveal') }}
                </AppButton>
                <span class="text-[12px] text-faint">{{ t('hrm.profile.revealed_note') }}</span>
            </div>
        </template>
    </section>
</template>
