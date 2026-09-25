<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Info, RotateCw, TriangleAlert } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppField from '@/components/AppField.vue';
import PermissionMatrix from './PermissionMatrix.vue';
import { api } from '@/lib/http';
import { confirmAction } from '@/lib/dialogs';
import { conflictsIn, diffPermissions, permissionLabels } from '@/lib/permissions';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Create or edit one role. `role` null = new role, prefilled from `seed`
 * (a template or a copy). Editing asks for a reason and sends the version it
 * started from; if someone else saved first, nothing is overwritten.
 */
const props = defineProps({
    open: Boolean,
    organizationId: { type: String, required: true },
    role: { type: Object, default: null },
    seed: { type: Object, default: null }, // { names, permissions, template_key }
    groups: { type: Array, default: () => [] },
    pairs: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved', 'reload']);

const form = reactive({ names: { en: '', bn: '' }, descriptions: { en: '', bn: '' }, permissions: [] });
const initial = ref('');
const errors = ref({});
const saving = ref(false);
const conflict = ref(false);

const creating = computed(() => !props.role);
const readonly = computed(() => !!props.role && !props.role.editable);
const labels = computed(() => permissionLabels(props.groups));
const title = computed(() =>
    creating.value
        ? t('access.editor.create_title')
        : readonly.value
          ? t('access.editor.view_title', { name: props.role.name })
          : t('access.editor.edit_title', { name: props.role.name }),
);
const snapshot = () => JSON.stringify(form);
const dirty = computed(() => snapshot() !== initial.value);
const hasConflict = computed(() => conflictsIn(form.permissions, props.pairs).length > 0);

watch(
    () => [props.open, props.role, props.seed],
    () => {
        if (!props.open) return;
        const source = props.role ?? props.seed ?? {};
        form.names = { en: source.names?.en ?? '', bn: source.names?.bn ?? '' };
        form.descriptions = { en: source.descriptions?.en ?? '', bn: source.descriptions?.bn ?? '' };
        form.permissions = [...(source.permissions ?? [])].sort();
        initial.value = snapshot();
        errors.value = {};
        conflict.value = false;
    },
    { immediate: true },
);

async function close() {
    if (dirty.value && !readonly.value) {
        const ok = await confirmAction({ title: t('access.editor.unsaved_title'), message: t('access.editor.unsaved_text'), danger: true, confirmLabel: t('access.editor.discard') });
        if (!ok) return;
    }
    emit('close');
}

function clean(texts) {
    return Object.fromEntries(Object.entries(texts).map(([locale, text]) => [locale, text.trim() || null]));
}

function displayName() {
    return form.names.en.trim() || form.names.bn.trim();
}

async function save() {
    errors.value = {};
    if (!form.names.en.trim() && !form.names.bn.trim()) {
        errors.value.name = t('access.editor.name_required');
        return;
    }

    const body = { name: clean(form.names), description: clean(form.descriptions), permissions: form.permissions };

    if (!creating.value) {
        const { added, removed } = diffPermissions(props.role.permissions, form.permissions);
        const named = (keys) => keys.map((key) => labels.value[key] ?? key).join(', ');
        const answer = await confirmAction({
            title: t('access.editor.change_reason_title', { name: props.role.name }),
            message: t('access.editor.change_reason_text'),
            details: [added.length && t('access.editor.added', { list: named(added) }), removed.length && t('access.editor.removed', { list: named(removed) })].filter(Boolean),
            reason: 'required',
            confirmLabel: t('core.actions.save'),
        });
        if (!answer) return;
        Object.assign(body, { base_version: props.role.version, reason: answer.reason });
    } else if (props.seed?.template_key) {
        body.template_key = props.seed.template_key;
    }

    saving.value = true;
    try {
        const url = `/api/organizations/${props.organizationId}/roles${creating.value ? '' : `/${props.role.id}`}`;
        const { data } = await api(url, { method: creating.value ? 'POST' : 'PATCH', body });
        toast.success(t(creating.value ? 'access.editor.created' : 'access.editor.saved', { name: data.name || displayName() }));
        initial.value = snapshot();
        emit('saved', data);
    } catch (error) {
        if (error.status === 409 && error.code === 'version_conflict') {
            conflict.value = true;
        } else if (error.status === 422 && Object.keys(error.errors).length) {
            errors.value = { name: error.field('name'), form: error.field('name') ? null : error.message };
        } else {
            errors.value = { form: error.message };
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDrawer :open="open" :title="title" width="sm:max-w-[680px]" @close="close">
        <form id="role-form" class="space-y-5 px-5 py-5 sm:px-6" novalidate @submit.prevent="save">
            <div v-if="conflict" class="flex flex-col gap-3 rounded-xl border border-warn/25 bg-warn-soft p-3.5 sm:flex-row sm:items-center" role="alert">
                <TriangleAlert class="size-4 shrink-0 text-warn" aria-hidden="true" />
                <p class="flex-1 text-[13px] text-fg-2">{{ t('access.errors.version_conflict') }}</p>
                <AppButton size="sm" :icon="RotateCw" @click="emit('reload')">{{ t('access.editor.conflict_reload') }}</AppButton>
            </div>
            <p v-if="errors.form" class="rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-2.5 text-[13px] text-fg-2" role="alert">{{ errors.form }}</p>
            <div v-if="readonly" class="flex items-start gap-2.5 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
                <Info class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />
                {{ role.organization ? t('access.list.read_only_hint', { name: role.organization.name }) : t('access.editor.read_only_note') }}
            </div>

            <div v-if="!readonly" class="grid gap-4 sm:grid-cols-2">
                <AppField :label="t('access.editor.name_en')" :error="errors.name">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.names.en" data-autofocus class="field-input" maxlength="80" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('access.editor.name_bn')" optional>
                    <template #default="{ id }">
                        <input :id="id" v-model="form.names.bn" class="field-input" maxlength="80" lang="bn" />
                    </template>
                </AppField>
                <AppField :label="t('access.editor.description_en')" optional>
                    <template #default="{ id }">
                        <textarea :id="id" v-model="form.descriptions.en" rows="2" class="field-input" maxlength="300" />
                    </template>
                </AppField>
                <AppField :label="t('access.editor.description_bn')" optional>
                    <template #default="{ id }">
                        <textarea :id="id" v-model="form.descriptions.bn" rows="2" class="field-input" maxlength="300" lang="bn" />
                    </template>
                </AppField>
            </div>

            <section>
                <h3 class="text-[13.5px] font-semibold text-fg">{{ t('access.editor.permissions') }}</h3>
                <p v-if="!readonly" class="mt-0.5 mb-3 text-[12.5px] text-muted">{{ t('access.editor.permissions_hint', { count: form.permissions.length }) }}</p>
                <PermissionMatrix v-model="form.permissions" class="mt-3" :groups="groups" :pairs="pairs" :readonly="readonly" />
            </section>
        </form>

        <template #footer>
            <footer class="flex flex-col-reverse gap-2 border-t border-line bg-surface/60 px-5 py-3.5 pb-[max(0.875rem,env(safe-area-inset-bottom))] sm:flex-row sm:items-center sm:justify-end sm:px-6">
                <p v-if="hasConflict && !readonly" class="flex items-center gap-1.5 text-[12.5px] text-warn sm:me-auto">
                    <TriangleAlert class="size-3.5 shrink-0" aria-hidden="true" />{{ t('access.editor.conflict_footer') }}
                </p>
                <AppButton @click="close">{{ readonly ? t('core.actions.close') : t('core.actions.cancel') }}</AppButton>
                <AppButton
                    v-if="!readonly"
                    type="submit"
                    form="role-form"
                    variant="primary"
                    :loading="saving"
                    :disabled="hasConflict || conflict || (!creating && !dirty)"
                >
                    {{ creating ? t('access.editor.save_create') : t('core.actions.save') }}
                </AppButton>
            </footer>
        </template>
    </AppDrawer>
</template>
