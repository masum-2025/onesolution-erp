<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowLeft, ArrowRight, Check, UserPlus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { hrmApi } from '../api';
import { addDays, hirePayload, missingRequired } from '../lib';

/**
 * Hiring in three steps: the person, the job, a last check. Which details
 * are required and which kinds of employment exist come from the chosen
 * unit's rules (reloaded when the unit changes); the server checks again.
 */
const org = currentOrganization();
const hrm = hrmApi(org.id);
const router = useRouter();

const PERSON = ['full_name', 'full_name_local', 'date_of_birth', 'gender', 'phone', 'email', 'national_id', 'tax_id', 'address', 'emergency_contact'];
const stepKeys = ['person', 'job', 'review'];
const step = ref(0);
const saving = ref(false);
const errors = ref({});

const form = reactive({
    full_name: '',
    full_name_local: '',
    date_of_birth: '',
    gender: '',
    phone: '',
    email: '',
    national_id: '',
    tax_id: '',
    address: { line1: '', line2: '', city: '', district: '', postcode: '' },
    emergency_contact: { name: '', relation: '', phone: '' },
    organization_id: org.id,
    position_id: '',
    employment_type: '',
    manager_id: '',
    joined_on: new Date().toISOString().slice(0, 10),
});

const options = useResource(() => hrm.formOptions(form.organization_id));
const units = useResource(() => api('/api/organizations', { query: { per_page: 100 } }));
const positions = useResource(() => hrm.positions());
const managers = useResource(() => hrm.employees({ per_page: 100 }));

watch(() => form.organization_id, () => options.reload());
watch(options.data, (value) => {
    const types = value?.data.employment_types ?? [];
    if (!types.some((type) => type.value === form.employment_type)) form.employment_type = types[0]?.value ?? '';
});

const opts = computed(() => options.data.value?.data ?? null);
const required = computed(() => new Set(opts.value?.required_fields ?? []));
const workUnits = computed(() => (units.data.value?.data ?? []).filter((unit) => ['company', 'branch', 'department', 'personal'].includes(unit.type)));
const activeManagers = computed(() => (managers.data.value?.data ?? []).filter((person) => person.status !== 'exited'));
const probationEnds = computed(() => {
    const days = opts.value?.probation_days.value ?? 0;
    return days > 0 ? addDays(form.joined_on, days) : null;
});

function label(field) {
    if (field === 'national_id' && opts.value) return opts.value.national_id.label;
    return t(`hrm.fields.${field === 'organization_id' ? 'unit' : field}`);
}

/** What is still missing on this step (rules first, then the always-required ones). */
function missingOn(index) {
    if (index === 0) return [...new Set([...(form.full_name.trim() ? [] : ['full_name']), ...missingRequired(form, [...required.value].filter((field) => PERSON.includes(field)))])];
    if (index === 1) return ['employment_type', 'joined_on', 'organization_id'].filter((field) => !form[field]);
    return [];
}

const blocking = computed(() => missingOn(step.value));

function next() {
    if (blocking.value.length) return;
    step.value += 1;
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const body = hirePayload(form);
        if (body.organization_id === org.id) delete body.organization_id;
        const { data } = await hrm.hire(body);
        toast.success(t('hrm.hire_page.saved', { name: data.full_name, code: data.employee_code }));
        router.push({ name: 'hrm-employee', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        // Back to the step that holds the first field with a problem.
        const first = Object.keys(errors.value)[0]?.split('.')[0];
        if (first) step.value = PERSON.includes(first) ? 0 : 1;
        if (!first) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

function fieldError(name) {
    return errors.value[name]?.[0] ?? errors.value[`${name}.line1`]?.[0] ?? null;
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('hrm.hire_page.title')" :description="t('hrm.hire_page.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'hrm' }" :icon="ArrowLeft">{{ t('hrm.profile.back') }}</AppButton>
            </template>
        </PageHeader>

        <ol class="mb-5 grid grid-cols-3 gap-2" :aria-label="t('hrm.hire_page.title')">
            <li v-for="(key, index) in stepKeys" :key="key" class="flex items-center gap-2 rounded-xl px-3 py-2 text-[12.5px]" :class="index === step ? 'bg-brand-soft font-medium text-brand-text' : 'bg-subtle text-muted'" :aria-current="index === step ? 'step' : undefined">
                <span class="grid size-5 place-items-center rounded-full text-[11px]" :class="index < step ? 'bg-ok text-white' : 'bg-surface'">
                    <Check v-if="index < step" class="size-3" aria-hidden="true" />
                    <template v-else>{{ index + 1 }}</template>
                </span>
                <span class="truncate">{{ t(`hrm.hire_page.steps.${key}`) }}</span>
            </li>
        </ol>

        <section class="card p-5 sm:p-6">
            <SkeletonRows v-if="options.loading.value && !opts" :rows="5" />
            <ErrorState v-else-if="options.error.value" compact :error="options.error.value" @retry="options.reload()" />

            <form v-else class="space-y-5" novalidate @submit.prevent="step < 2 ? next() : save()">
                <!-- 1. The person -->
                <div v-show="step === 0" class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('hrm.fields.full_name')" :error="fieldError('full_name')" class="sm:col-span-2">
                        <input :id="id" v-model="form.full_name" class="field-input" autocomplete="name" maxlength="150" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.full_name_local')" :error="fieldError('full_name_local')" optional class="sm:col-span-2">
                        <input :id="id" v-model="form.full_name_local" class="field-input" lang="bn" maxlength="150" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.phone')" :error="fieldError('phone')" :optional="!required.has('phone')">
                        <input :id="id" v-model="form.phone" type="tel" class="field-input" dir="ltr" autocomplete="tel" maxlength="30" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.email')" :error="fieldError('email')" :optional="!required.has('email')">
                        <input :id="id" v-model="form.email" type="email" class="field-input" dir="ltr" autocomplete="email" maxlength="190" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.date_of_birth')" :error="fieldError('date_of_birth')" :optional="!required.has('date_of_birth')">
                        <input :id="id" v-model="form.date_of_birth" type="date" class="field-input" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.gender')" :error="fieldError('gender')" :optional="!required.has('gender')">
                        <select :id="id" v-model="form.gender" class="field-input">
                            <option value="">–</option>
                            <option v-for="gender in opts?.genders ?? []" :key="gender.value" :value="gender.value">{{ gender.label }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="label('national_id')" :error="fieldError('national_id')" :optional="!required.has('national_id')">
                        <input :id="id" v-model="form.national_id" class="field-input" dir="ltr" autocomplete="off" maxlength="40" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.tax_id')" :error="fieldError('tax_id')" optional>
                        <input :id="id" v-model="form.tax_id" class="field-input" dir="ltr" autocomplete="off" maxlength="40" />
                    </AppField>

                    <fieldset class="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                        <legend class="mb-1 text-[13px] font-medium text-fg-2">
                            {{ t('hrm.fields.address') }}
                            <span v-if="required.has('address')" class="text-[12px] font-normal text-muted">· {{ t('hrm.fields.required') }}</span>
                            <span v-else class="font-normal text-faint">· {{ t('core.optional') }}</span>
                        </legend>
                        <AppField v-slot="{ id }" :label="t('hrm.fields.address_line1')" :error="fieldError('address')" class="sm:col-span-2">
                            <input :id="id" v-model="form.address.line1" class="field-input" autocomplete="address-line1" maxlength="150" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('hrm.fields.city')">
                            <input :id="id" v-model="form.address.city" class="field-input" autocomplete="address-level2" maxlength="150" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('hrm.fields.district')">
                            <input :id="id" v-model="form.address.district" class="field-input" maxlength="150" />
                        </AppField>
                    </fieldset>

                    <fieldset class="grid gap-3 sm:col-span-2 sm:grid-cols-3">
                        <legend class="mb-1 text-[13px] font-medium text-fg-2">
                            {{ t('hrm.fields.emergency_contact') }}
                            <span v-if="required.has('emergency_contact')" class="text-[12px] font-normal text-muted">· {{ t('hrm.fields.required') }}</span>
                            <span v-else class="font-normal text-faint">· {{ t('core.optional') }}</span>
                        </legend>
                        <AppField v-slot="{ id }" :label="t('hrm.fields.emergency_name')" :error="fieldError('emergency_contact')">
                            <input :id="id" v-model="form.emergency_contact.name" class="field-input" maxlength="100" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('hrm.fields.emergency_relation')">
                            <input :id="id" v-model="form.emergency_contact.relation" class="field-input" maxlength="100" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('hrm.fields.emergency_phone')">
                            <input :id="id" v-model="form.emergency_contact.phone" type="tel" class="field-input" dir="ltr" maxlength="100" />
                        </AppField>
                    </fieldset>
                </div>

                <!-- 2. The job -->
                <div v-show="step === 1" class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('hrm.fields.unit')" :error="fieldError('organization_id')" class="sm:col-span-2">
                        <select :id="id" v-model="form.organization_id" class="field-input">
                            <option v-for="unit in workUnits" :key="unit.id" :value="unit.id">{{ '— '.repeat(Math.max(0, unit.depth - workUnits[0].depth)) }}{{ unit.display_name }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.employment_type')" :error="fieldError('employment_type')">
                        <select :id="id" v-model="form.employment_type" class="field-input">
                            <option v-for="type in opts?.employment_types ?? []" :key="type.value" :value="type.value">{{ type.label }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.joined_on')" :error="fieldError('joined_on')">
                        <input :id="id" v-model="form.joined_on" type="date" class="field-input" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.position')" :error="fieldError('position_id')" optional>
                        <select :id="id" v-model="form.position_id" class="field-input">
                            <option value="">{{ t('hrm.fields.no_position') }}</option>
                            <option v-for="position in positions.data.value?.data ?? []" :key="position.id" :value="position.id">{{ position.title }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields.manager')" :error="fieldError('manager_id')" optional>
                        <select :id="id" v-model="form.manager_id" class="field-input">
                            <option value="">{{ t('hrm.fields.no_manager') }}</option>
                            <option v-for="person in activeManagers" :key="person.id" :value="person.id">{{ person.full_name }} ({{ person.employee_code }})</option>
                        </select>
                    </AppField>
                </div>

                <!-- 3. Check and save -->
                <div v-show="step === 2" class="space-y-4">
                    <dl class="grid gap-x-6 gap-y-3 text-[13.5px] sm:grid-cols-2">
                        <div><dt class="text-muted">{{ t('hrm.fields.full_name') }}</dt><dd class="font-medium text-fg">{{ form.full_name }}</dd></div>
                        <div><dt class="text-muted">{{ t('hrm.fields.unit') }}</dt><dd class="font-medium text-fg">{{ opts?.unit.name }}</dd></div>
                        <div><dt class="text-muted">{{ t('hrm.fields.employment_type') }}</dt><dd class="font-medium text-fg">{{ opts?.employment_types.find((type) => type.value === form.employment_type)?.label }}</dd></div>
                        <div><dt class="text-muted">{{ t('hrm.fields.joined_on') }}</dt><dd class="font-medium text-fg">{{ formatDate(form.joined_on) }}</dd></div>
                    </dl>
                    <p class="flex flex-wrap items-center gap-2 rounded-xl bg-subtle px-4 py-3 text-[13px] text-fg-2">
                        <span>{{ probationEnds ? t('hrm.hire_page.probation', { date: formatDate(probationEnds), count: opts.probation_days.value }) : t('hrm.hire_page.no_probation') }}</span>
                        <SourceBadge v-if="opts" v-bind="opts.probation_days.source" />
                    </p>
                </div>

                <p v-if="blocking.length" class="text-[12.5px] text-warn" role="status">
                    {{ t('hrm.hire_page.missing', { fields: blocking.map(label).join(', ') }) }}
                </p>

                <div class="flex items-center justify-between gap-3 border-t border-line pt-4">
                    <AppButton v-if="step > 0" variant="ghost" :icon="ArrowLeft" @click="step -= 1">{{ t('hrm.hire_page.back') }}</AppButton>
                    <span v-else />
                    <AppButton v-if="step < 2" variant="primary" type="submit" :icon-end="ArrowRight" :disabled="blocking.length > 0">{{ t('hrm.hire_page.next') }}</AppButton>
                    <AppButton v-else variant="primary" type="submit" :icon="UserPlus" :loading="saving">{{ t('hrm.hire_page.save') }}</AppButton>
                </div>
            </form>
        </section>
    </div>
</template>
