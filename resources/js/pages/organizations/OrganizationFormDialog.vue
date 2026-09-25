<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Building2, PenLine } from 'lucide-vue-next';
import AppDialog from '@/components/AppDialog.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import OrgPicker from '@/components/OrgPicker.vue';
import SectorPicker from './SectorPicker.vue';
import { api } from '@/lib/http';
import { invalidate } from '@/lib/cache';
import { visibleOrganizations } from '@/lib/organizations';
import { countryName, currencyName } from '@/lib/display';
import { i18n, t } from '@/lib/i18n';
import { session } from '@/lib/session';
import { toast } from '@/lib/toast';

/**
 * Create a unit under a parent, or edit one. Empty country / language /
 * timezone / currency means "inherit from above".
 */
const props = defineProps({
    open: Boolean,
    mode: { type: String, default: 'create' }, // create | edit
    organization: { type: Object, default: null }, // edit: the unit
    parentId: { type: String, default: null }, // create: preselected parent
    parentAllowed: { type: Function, default: null }, // create: which parents can take a child
    childTypes: { type: Function, default: () => [] }, // create: parent type -> allowed child types
});

const emit = defineEmits(['close', 'saved']);

const form = reactive({});
const errors = ref({});
const saving = ref(false);
const parent = ref(null);

const timezones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];

function reset() {
    const org = props.organization;
    Object.assign(form, {
        parent_id: props.parentId,
        type: '',
        name_en: org?.name?.en ?? '',
        name_bn: org?.name?.bn ?? '',
        sector_key: org?.sector_key ?? '',
        country_code: org?.country_code ?? '',
        default_locale: org?.default_locale ?? '',
        timezone: org?.timezone ?? '',
        currency_code: org?.currency_code ?? '',
        status: org?.status ?? 'active',
    });
    errors.value = {};
    if (props.mode === 'create') resolveParent(form.parent_id);
}

watch(
    () => props.open,
    (open) => open && reset(),
);

async function resolveParent(id) {
    parent.value = id ? ((await visibleOrganizations()).find((org) => org.id === id) ?? null) : null;
}

watch(() => form.parent_id, resolveParent);

const types = computed(() => (parent.value ? props.childTypes(parent.value.type) : []));

watch(types, (list) => {
    if (!list.includes(form.type)) form.type = list[0] ?? '';
});

const nullable = (value) => (value.trim() === '' ? null : value.trim());

function payload() {
    const body = {
        name: { en: form.name_en.trim(), ...(form.name_bn.trim() ? { bn: form.name_bn.trim() } : {}) },
        country_code: nullable(form.country_code.toUpperCase()),
        default_locale: nullable(form.default_locale),
        timezone: nullable(form.timezone),
        currency_code: nullable(form.currency_code.toUpperCase()),
    };
    if (props.mode === 'create') {
        body.parent_id = form.parent_id;
        body.type = form.type;
        if (form.type === 'company') body.sector_key = nullable(form.sector_key);
    } else {
        if (props.organization.type === 'company') body.sector_key = nullable(form.sector_key);
        body.status = form.status;
    }
    return body;
}

function localErrors() {
    const found = {};
    if (props.mode === 'create' && !form.parent_id) found.parent_id = t('orgs.form.parent_required');
    if (props.mode === 'create' && !form.type) found.type = t('orgs.form.type_required');
    if (!form.name_en.trim()) found.name_en = t('orgs.form.name_required');
    if (props.mode === 'create' && form.type === 'company' && !form.sector_key) found.sector_key = t('orgs.form.sector_required');
    if (form.country_code.trim() && !/^[A-Za-z]{2}$/.test(form.country_code.trim())) found.country_code = t('orgs.form.country_invalid');
    if (form.currency_code.trim() && !/^[A-Za-z]{3}$/.test(form.currency_code.trim())) found.currency_code = t('orgs.form.currency_invalid');
    return found;
}

async function submit() {
    errors.value = localErrors();
    if (Object.keys(errors.value).length) return;

    saving.value = true;
    try {
        const url = props.mode === 'create' ? '/api/organizations' : `/api/organizations/${props.organization.id}`;
        const { data, package: applied } = await api(url, { method: props.mode === 'create' ? 'POST' : 'PATCH', body: payload() });
        invalidate('organizations');
        if (applied) {
            // A new company started with its sector package: say what it got.
            toast.success(
                t('orgs.form.created_with_package', {
                    name: data.display_name,
                    package: applied.package.name,
                    modules: applied.modules_enabled.length,
                    roles: applied.roles_created.length,
                    rules: applied.rules_set.length,
                }),
            );
        } else {
            toast.success(props.mode === 'create' ? t('orgs.form.created', { name: data.display_name }) : t('orgs.form.saved'));
        }
        emit('saved', data);
        emit('close');
    } catch (error) {
        if (error.status === 422) {
            const mapped = {};
            Object.entries(error.errors).forEach(([key, messages]) => {
                mapped[key.replace('name.', 'name_')] = messages[0];
            });
            errors.value = Object.keys(mapped).length ? mapped : { form: error.message };
        } else {
            errors.value = { form: error.message };
        }
    } finally {
        saving.value = false;
    }
}

const inheritHint = t('orgs.form.inherit_hint');
</script>

<template>
    <AppDialog
        :open="open"
        :title="mode === 'create' ? t('orgs.form.create_title') : t('orgs.form.edit_title')"
        :description="mode === 'create' ? t('orgs.form.create_text') : organization?.display_name"
        :icon="mode === 'create' ? Building2 : PenLine"
        size="lg"
        @close="emit('close')"
    >
        <form id="org-form" class="space-y-5" novalidate @submit.prevent="submit">
            <p v-if="errors.form" class="rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-2.5 text-[13px] text-fg-2" role="alert">{{ errors.form }}</p>

            <div v-if="mode === 'create'" class="grid gap-4 sm:grid-cols-2">
                <AppField :label="t('orgs.form.parent')" :error="errors.parent_id">
                    <template #default="{ id, invalid }">
                        <OrgPicker
                            v-model="form.parent_id"
                            :input-id="id"
                            :invalid="invalid"
                            :allow="parentAllowed"
                            :label="t('orgs.form.parent')"
                        />
                    </template>
                </AppField>
                <AppField :label="t('orgs.form.type')" :error="errors.type" :hint="form.parent_id && !types.length ? t('orgs.form.no_child_types') : ''">
                    <template #default="{ id, invalid, describedby }">
                        <select :id="id" v-model="form.type" class="field-input" :disabled="!types.length" :aria-invalid="invalid || undefined" :aria-describedby="describedby">
                            <option v-for="type in types" :key="type" :value="type">{{ t(`core.org_types.${type}`) }}</option>
                        </select>
                    </template>
                </AppField>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <AppField :label="t('orgs.form.name_en')" :error="errors.name_en">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.name_en" class="field-input" maxlength="150" lang="en" data-autofocus :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('orgs.form.name_bn')" :error="errors.name_bn" optional>
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.name_bn" class="field-input" maxlength="150" lang="bn" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
            </div>

            <AppField
                v-if="(mode === 'create' && form.type === 'company') || (mode === 'edit' && organization?.type === 'company')"
                :label="t('orgs.form.sector')"
                :hint="mode === 'create' ? t('orgs.form.sector_hint') : t('orgs.form.sector_hint_edit')"
                :error="errors.sector_key"
            >
                <template #default="{ id, invalid, describedby }">
                    <SectorPicker :id="id" v-model="form.sector_key" :invalid="invalid" :describedby="describedby" />
                </template>
            </AppField>

            <fieldset class="rounded-xl border border-line p-4">
                <legend class="px-1.5 text-[13px] font-medium text-fg">{{ t('orgs.form.regional') }}</legend>
                <p class="mb-4 text-[12.5px] text-muted">{{ inheritHint }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField :label="t('orgs.settings.fields.country_code')" :error="errors.country_code" :hint="form.country_code.length === 2 ? countryName(form.country_code.toUpperCase()) : ''" optional>
                        <template #default="{ id, invalid, describedby }">
                            <input :id="id" v-model="form.country_code" class="field-input uppercase" maxlength="2" placeholder="BD" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </template>
                    </AppField>
                    <AppField :label="t('orgs.settings.fields.currency_code')" :error="errors.currency_code" :hint="form.currency_code.length === 3 ? currencyName(form.currency_code.toUpperCase()) : ''" optional>
                        <template #default="{ id, invalid, describedby }">
                            <input :id="id" v-model="form.currency_code" class="field-input uppercase" maxlength="3" placeholder="BDT" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </template>
                    </AppField>
                    <AppField :label="t('orgs.settings.fields.default_locale')" :error="errors.default_locale" optional>
                        <template #default="{ id, invalid }">
                            <select :id="id" v-model="form.default_locale" class="field-input" :aria-invalid="invalid || undefined">
                                <option value="">{{ t('orgs.form.inherit') }}</option>
                                <option v-for="locale in session.me?.locales ?? i18n.locales" :key="locale" :value="locale">{{ t(`core.languages.${locale}`) }}</option>
                            </select>
                        </template>
                    </AppField>
                    <AppField :label="t('orgs.settings.fields.timezone')" :error="errors.timezone" optional>
                        <template #default="{ id, invalid }">
                            <input :id="id" v-model="form.timezone" class="field-input" list="org-form-timezones" placeholder="Asia/Dhaka" :aria-invalid="invalid || undefined" />
                            <datalist id="org-form-timezones">
                                <option v-for="zone in timezones" :key="zone" :value="zone" />
                            </datalist>
                        </template>
                    </AppField>
                </div>
            </fieldset>

            <AppField v-if="mode === 'edit'" :label="t('orgs.form.status')" :error="errors.status" :hint="t('orgs.form.status_hint')">
                <template #default="{ id, invalid, describedby }">
                    <select :id="id" v-model="form.status" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby">
                        <option v-for="status in ['active', 'suspended', 'archived']" :key="status" :value="status">{{ t(`orgs.status.${status}`) }}</option>
                    </select>
                </template>
            </AppField>
        </form>

        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton type="submit" form="org-form" variant="primary" :loading="saving">
                {{ mode === 'create' ? t('orgs.form.create_submit') : t('core.actions.save') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
