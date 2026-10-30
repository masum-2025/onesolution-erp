<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Building2, Plus, User, X } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { t } from '@/lib/i18n';

/**
 * Choose a project's client among the company's CRM contacts, people and
 * organizations alike (clients first), or add a new one there with its
 * kind, name, company, phone, email and address. The chosen contact is
 * { contact_id, name, kind }.
 */
const props = defineProps({
    modelValue: { type: Object, default: () => ({ contact_id: null, name: '', kind: null }) },
    calls: { type: Object, required: true },
    canAdd: Boolean,
    error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const term = ref('');
const kind = ref('');
const found = ref([]);
const open = ref(false);
const loading = ref(false);
const kinds = computed(() => [
    { value: '', label: t('projects.client.all') },
    { value: 'person', label: t('projects.client.person') },
    { value: 'organization', label: t('projects.client.organization') },
]);

let timer;
function look() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        loading.value = true;
        try {
            found.value = (await props.calls.clients(term.value.trim(), kind.value)).data;
        } catch {
            found.value = [];
        } finally {
            loading.value = false;
        }
    }, 250);
}
watch([term, kind], () => {
    open.value = true;
    look();
});
function focus() {
    open.value = true;
    if (!found.value.length) look();
}
// Leaving the field closes the list (after a click on it has landed).
function closeSoon() {
    setTimeout(() => (open.value = false), 200);
}
function choose(contact) {
    emit('update:modelValue', { contact_id: contact.id, name: contact.name, kind: contact.kind });
    open.value = false;
    term.value = '';
}
function clear() {
    emit('update:modelValue', { contact_id: null, name: '', kind: null });
}

// A new client, kept in CRM.
const adding = ref(false);
const form = reactive({ kind: 'organization', name: '', company_name: '', phone: '', email: '', line1: '', city: '', district: '' });
const errors = ref({});
const saving = ref(false);
function startAdding() {
    Object.assign(form, { kind: kind.value || 'organization', name: term.value.trim(), company_name: '', phone: '', email: '', line1: '', city: '', district: '' });
    errors.value = {};
    adding.value = true;
    open.value = false;
}
async function save() {
    saving.value = true;
    errors.value = {};
    const address = { line1: form.line1.trim() || null, city: form.city.trim() || null, district: form.district.trim() || null };
    try {
        const { data } = await props.calls.addClient({
            kind: form.kind,
            name: form.name.trim(),
            company_name: form.kind === 'person' ? form.company_name.trim() || null : null,
            phone: form.phone.trim() || null,
            email: form.email.trim() || null,
            address: Object.values(address).some(Boolean) ? address : null,
        });
        choose(data);
        adding.value = false;
    } catch (error) {
        errors.value = error.errors ?? (error.message ? { name: [error.message] } : {});
    } finally {
        saving.value = false;
    }
}
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div class="space-y-2">
        <p class="text-[13px] font-medium text-fg-2">{{ t('projects.fields.client') }}</p>

        <!-- Chosen. -->
        <div v-if="modelValue.contact_id && !adding" class="flex items-center gap-2 rounded-xl border border-line bg-subtle px-3 py-2">
            <component :is="modelValue.kind === 'person' ? User : Building2" class="size-4 shrink-0 text-muted" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate text-[13.5px] font-medium">{{ modelValue.name }}</span>
            <span class="text-[12px] text-muted">{{ t(`projects.client.${modelValue.kind ?? 'organization'}`) }}</span>
            <AppButton size="icon-sm" variant="ghost" :icon="X" :aria-label="t('projects.client.change')" @click="clear" />
        </div>

        <!-- Choosing. -->
        <div v-else-if="!adding" class="relative space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <AppSegmented v-model="kind" size="sm" :label="t('projects.client.kind')" :options="kinds" />
                <AppButton v-if="canAdd" size="sm" class="ms-auto" :icon="Plus" @click="startAdding">{{ t('projects.client.new') }}</AppButton>
            </div>
            <label class="sr-only" for="client-search">{{ t('projects.client.search') }}</label>
            <input id="client-search" v-model="term" class="field-input" autocomplete="off" maxlength="100" :placeholder="t('projects.client.search')" @focus="focus" @blur="closeSoon" @keydown.esc="open = false" />
            <ul v-if="open" class="absolute inset-x-0 top-full z-10 mt-1 max-h-60 overflow-y-auto rounded-xl border border-line bg-raised shadow-pop" role="listbox">
                <li v-if="loading && !found.length" class="px-3 py-2 text-[13px] text-muted">{{ t('projects.client.looking') }}</li>
                <li v-else-if="!found.length" class="px-3 py-2 text-[13px] text-muted">{{ t('projects.client.none') }}</li>
                <li v-for="contact in found" :key="contact.id" role="option">
                    <button type="button" class="flex w-full items-center gap-2.5 px-3 py-2 text-start hover:bg-subtle" @click="choose(contact)">
                        <component :is="contact.kind === 'person' ? User : Building2" class="size-4 shrink-0 text-muted" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13.5px]">{{ contact.name }}</span>
                            <span class="block truncate text-[12px] text-muted">
                                {{ t(`projects.client.${contact.kind}`) }}<template v-if="contact.company_name"> · {{ contact.company_name }}</template><template v-if="contact.phone"> · <span dir="ltr">{{ contact.phone }}</span></template>
                            </span>
                        </span>
                        <AppBadge v-if="contact.tagged" tone="ok">{{ t('projects.client.is_client') }}</AppBadge>
                    </button>
                </li>
            </ul>
        </div>

        <!-- A new client (CRM contact). -->
        <fieldset v-else class="space-y-3 rounded-xl border border-line p-3">
            <legend class="px-1 text-[13px] font-semibold">{{ t('projects.client.new_title') }}</legend>
            <AppSegmented v-model="form.kind" size="sm" :label="t('projects.client.kind')" :options="kinds.slice(1)" />
            <div class="grid gap-3 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t(form.kind === 'person' ? 'projects.client.person_name' : 'projects.client.organization_name')" :error="fieldError('name')" class="sm:col-span-2">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="150" />
                </AppField>
                <AppField v-if="form.kind === 'person'" v-slot="{ id }" :label="t('projects.client.company_name')" :error="fieldError('company_name')" optional class="sm:col-span-2">
                    <input :id="id" v-model="form.company_name" class="field-input" maxlength="150" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('projects.client.phone')" :error="fieldError('phone')" optional>
                    <input :id="id" v-model="form.phone" type="tel" class="field-input" dir="ltr" maxlength="20" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('projects.client.email')" :error="fieldError('email')" optional>
                    <input :id="id" v-model="form.email" type="email" class="field-input" dir="ltr" maxlength="190" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('projects.client.address')" :error="fieldError('address.line1')" optional class="sm:col-span-2">
                    <input :id="id" v-model="form.line1" class="field-input" maxlength="150" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('projects.client.city')" optional>
                    <input :id="id" v-model="form.city" class="field-input" maxlength="150" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('projects.client.district')" optional>
                    <input :id="id" v-model="form.district" class="field-input" maxlength="150" />
                </AppField>
            </div>
            <p class="text-[12px] text-muted">{{ t('projects.client.kept_in_crm') }}</p>
            <div class="flex justify-end gap-2">
                <AppButton size="sm" variant="ghost" @click="adding = false">{{ t('projects.common.cancel') }}</AppButton>
                <AppButton size="sm" variant="primary" :loading="saving" :disabled="form.name.trim().length < 2" @click="save">{{ t('projects.client.add') }}</AppButton>
            </div>
        </fieldset>
        <p v-if="error" class="text-[12.5px] text-bad" role="alert">{{ error }}</p>
    </div>
</template>
