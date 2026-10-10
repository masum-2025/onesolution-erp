<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { UsersRound } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import OwnFields from '@/components/OwnFields.vue';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { useEducationSetup } from '../setup';
import { fieldsToApi, fieldsToForm } from '../lib';

/**
 * A guardian of a student: added (a phone already here links that same
 * guardian, so siblings share parents) or changed. Their details belong to
 * the guardian (every child sees the change); relation and the switches
 * belong to this student only.
 */
const props = defineProps({
    open: Boolean,
    studentId: { type: String, required: true },
    guardian: { type: Object, default: null },
    education: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved', 'conflict']);

const setup = useEducationSetup();
const sensitive = computed(() => setup.can('view_sensitive'));
const fields = computed(() => setup.fields('guardian'));
const editing = computed(() => props.guardian !== null);

const form = reactive({});
const extra = ref({});
const errors = ref({});
const saving = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const guardian = props.guardian;
        Object.assign(form, {
            relation: guardian?.relation ?? setup.list('relation')[0]?.key ?? 'guardian',
            name: guardian?.name ?? '',
            phone: guardian?.phone ?? '',
            email: guardian?.email ?? '',
            occupation: guardian?.occupation ?? '',
            national_id: guardian?.national_id ?? '',
            is_primary: guardian?.is_primary ?? false,
            can_pick_up: guardian?.can_pick_up ?? true,
            receives_notices: guardian?.receives_notices ?? true,
        });
        extra.value = fieldsToForm(fields.value, guardian?.extra);
        errors.value = {};
    },
);

const clean = (value) => String(value ?? '').trim() || null;

async function submit() {
    if (!clean(form.name) && !clean(form.phone)) {
        errors.value = { name: [t('education.guardian.need_one')] };
        return;
    }
    saving.value = true;
    errors.value = {};
    const details = {
        name: clean(form.name),
        phone: clean(form.phone),
        email: clean(form.email),
        occupation: clean(form.occupation),
        ...(sensitive.value ? { national_id: clean(form.national_id) } : {}),
    };
    const link = { relation: form.relation, is_primary: form.is_primary, can_pick_up: form.can_pick_up, receives_notices: form.receives_notices };
    try {
        let saved;
        if (editing.value) {
            const changed = await props.education.updateGuardian(props.studentId, props.guardian.id, {
                ...details,
                name: details.name ?? props.guardian.name,
                extra: fieldsToApi(fields.value, extra.value, { clear: true }),
                base_version: props.guardian.version,
            });
            saved = changed.data;
            const relinked = Object.keys(link).some((key) => link[key] !== props.guardian[key]);
            if (relinked) saved = (await props.education.linkGuardian(props.studentId, { guardian_id: props.guardian.id, ...link })).data;
        } else {
            saved = (await props.education.linkGuardian(props.studentId, { ...details, ...link, extra: fieldsToApi(fields.value, extra.value) })).data;
        }
        toast.success(t('education.guardian.saved'));
        emit('saved', saved);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
            emit('conflict');
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
    <AppDialog
        :open="open"
        :title="editing ? t('education.guardian.edit_title', { name: guardian.name }) : t('education.guardian.add_title')"
        :icon="UsersRound"
        size="lg"
        @close="emit('close')"
    >
        <form id="education-guardian" class="space-y-4" novalidate @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('education.guardian.relation')" :error="fieldError('relation')">
                    <select :id="id" v-model="form.relation" class="field-input">
                        <option v-for="item in setup.list('relation')" :key="item.key" :value="item.key">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id, invalid, describedby }" :label="t('education.guardian.name')" :error="fieldError('name')">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="150" autocomplete="off" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.guardian.phone')" :hint="editing ? '' : t('education.guardian.phone_hint')" :error="fieldError('phone')">
                    <input :id="id" v-model="form.phone" type="tel" inputmode="tel" dir="ltr" class="field-input" maxlength="30" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.guardian.occupation')" :error="fieldError('occupation')" optional>
                    <input :id="id" v-model="form.occupation" class="field-input" maxlength="100" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.guardian.email')" :error="fieldError('email')" optional>
                    <input :id="id" v-model="form.email" type="email" dir="ltr" class="field-input" maxlength="190" autocomplete="off" />
                </AppField>
                <AppField v-if="sensitive" v-slot="{ id }" :label="t('education.guardian.national_id')" :error="fieldError('national_id')" optional>
                    <input :id="id" v-model="form.national_id" dir="ltr" inputmode="numeric" class="field-input tabular" maxlength="40" autocomplete="off" />
                </AppField>
            </div>
            <OwnFields v-model="extra" :fields="fields" :errors="errors" prefix="extra" />
            <div class="flex flex-wrap gap-x-6 gap-y-3">
                <AppSwitch v-model="form.is_primary" :label="t('education.guardian.primary')" show-label />
                <AppSwitch v-model="form.can_pick_up" :label="t('education.guardian.can_pick_up')" show-label />
                <AppSwitch v-model="form.receives_notices" :label="t('education.guardian.receives_notices')" show-label />
            </div>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="education-guardian" :loading="saving">{{ t('education.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
