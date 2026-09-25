<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Copy, CornerDownRight, Eye, FilePlus2, KeyRound, LayoutTemplate, Lock, MoreHorizontal, PenLine, Plus, Search, ShieldOff, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppMenu from '@/components/AppMenu.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import OrgPicker from '@/components/OrgPicker.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import RoleEditor from './RoleEditor.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { i18n, t } from '@/lib/i18n';

const route = useRoute();
const router = useRouter();
const context = currentOrganization();

const orgId = ref(typeof route.query.org === 'string' ? route.query.org : context.id);
const filter = ref('all');
const query = ref('');

const roles = useResource(() => api(`/api/organizations/${orgId.value}/roles`).then((response) => response.data));
const permissions = useResource(() => api(`/api/organizations/${orgId.value}/permissions`));
const templates = useResource(() => api(`/api/organizations/${orgId.value}/role-templates`).then((response) => response.data), { immediate: false });

watch(orgId, (id) => {
    router.replace({ query: { ...route.query, org: id === context.id ? undefined : id } });
    roles.reload();
    permissions.reload();
});

// Creating roles needs roles.manage; the list also serves people who only give roles.
const manageable = computed(() => can('roles.manage'));
const groups = computed(() => permissions.data.value?.data ?? []);
const pairs = computed(() => permissions.data.value?.separation_of_duties ?? []);

const counts = computed(() => {
    const list = roles.data.value ?? [];
    return { all: list.length, own: list.filter((role) => role.owned_here).length, inherited: list.filter((role) => !role.owned_here).length };
});

const filters = computed(() => [
    { value: 'all', label: t('access.filter.all', { count: counts.value.all }) },
    { value: 'own', label: t('access.filter.own', { count: counts.value.own }) },
    { value: 'inherited', label: t('access.filter.inherited', { count: counts.value.inherited }) },
]);

const shown = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return (roles.data.value ?? [])
        .filter((role) => filter.value === 'all' || (filter.value === 'own' ? role.owned_here : !role.owned_here))
        .filter((role) => !needle || role.name.toLocaleLowerCase().includes(needle) || (role.description ?? '').toLocaleLowerCase().includes(needle))
        .sort((a, b) => Number(b.owned_here) - Number(a.owned_here) || a.name.localeCompare(b.name));
});

const noAccess = computed(() => roles.error.value?.status === 403);

// ── Create ──
const choosing = ref(false);
const editor = ref({ open: false, role: null, seed: null });

function startCreate() {
    choosing.value = true;
    if (!templates.data.value) templates.reload();
}

function useTemplate(template) {
    choosing.value = false;
    // The template name arrives in the current language; fill that field only.
    const names = { [i18n.locale]: template.name };
    editor.value = {
        open: true,
        role: null,
        seed: { names, permissions: template.permissions.filter((key) => !template.not_grantable.includes(key)), template_key: template.key },
    };
}

function startBlank() {
    choosing.value = false;
    editor.value = { open: true, role: null, seed: { names: {}, permissions: [] } };
}

function duplicate(role) {
    editor.value = {
        open: true,
        role: null,
        seed: {
            names: { en: role.names.en ? `${role.names.en} (2)` : '', bn: role.names.bn ? `${role.names.bn} (২)` : '' },
            descriptions: role.descriptions,
            permissions: role.permissions.filter((key) => !role.not_assignable_permissions.includes(key)),
        },
    };
}

function open(role) {
    editor.value = { open: true, role, seed: null };
}

function afterSave() {
    editor.value = { ...editor.value, open: false };
    roles.reload();
}

async function reloadEdited() {
    const id = editor.value.role?.id;
    await roles.reload();
    const fresh = (roles.data.value ?? []).find((role) => role.id === id);
    editor.value = fresh ? { open: true, role: fresh, seed: null } : { open: false, role: null, seed: null };
}

// ── Delete ──
async function remove(role) {
    const answer = await confirmAction({
        title: t('access.delete.title', { name: role.name }),
        message: t('access.delete.text'),
        reason: 'required',
        danger: true,
        confirmLabel: t('access.delete.submit'),
    });
    if (!answer) return;
    try {
        await api(`/api/organizations/${orgId.value}/roles/${role.id}`, { method: 'DELETE', body: { reason: answer.reason } });
        toast.success(t('access.delete.done', { name: role.name }));
        roles.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

function actionsFor(role) {
    return [
        role.editable
            ? { label: t('access.list.edit'), icon: PenLine, onSelect: () => open(role) }
            : { label: t('access.list.view'), icon: Eye, onSelect: () => open(role) },
        manageable.value && { label: t('access.list.duplicate'), icon: Copy, onSelect: () => duplicate(role) },
        role.editable && { divider: true },
        role.editable &&
            (role.members_count > 0
                ? { label: t('access.list.delete'), hint: t('access.list.delete_in_use'), icon: Trash2, disabled: true }
                : { label: t('access.list.delete'), icon: Trash2, danger: true, onSelect: () => remove(role) }),
    ].filter(Boolean);
}

function membersText(count) {
    return count === 0 ? t('access.list.members_none') : t('access.list.members', { count });
}

const templateGroups = computed(() => {
    const list = templates.data.value ?? [];
    return [
        { key: 'sector', label: t('access.create.sector'), items: list.filter((template) => template.sector) },
        { key: 'general', label: t('access.create.general'), items: list.filter((template) => !template.sector) },
    ].filter((group) => group.items.length);
});
</script>

<template>
    <div>
        <PageHeader :title="t('access.title')" :description="t('access.text')">
            <template #actions>
                <OrgPicker v-model="orgId" compact :label="t('core.org_picker.scope')" />
                <AppButton v-if="manageable && !noAccess" variant="primary" :icon="Plus" @click="startCreate">{{ t('access.new') }}</AppButton>
            </template>
        </PageHeader>

        <EmptyState v-if="noAccess" :icon="ShieldOff" :title="t('access.title')" :text="t('access.no_access')" />

        <template v-else>
            <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <AppSegmented v-model="filter" :options="filters" :label="t('access.filter.label')" />
                <div class="relative w-full sm:max-w-xs">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                    <input v-model="query" type="search" class="field-input h-9 min-h-9 ps-9" :placeholder="t('access.search')" :aria-label="t('access.search')" />
                </div>
            </div>

            <section class="card">
                <SkeletonRows v-if="roles.loading.value && !roles.data.value" :rows="4" avatar />
                <ErrorState v-else-if="roles.error.value" compact :error="roles.error.value" @retry="roles.reload()" />
                <EmptyState v-else-if="!roles.data.value?.length" :icon="KeyRound" :title="t('access.empty_title')" :text="t('access.empty_text')" compact>
                    <AppButton v-if="manageable" variant="primary" :icon="Plus" @click="startCreate">{{ t('access.new') }}</AppButton>
                </EmptyState>
                <p v-else-if="!shown.length" class="px-5 py-10 text-center text-[13.5px] text-muted">{{ t('access.no_match') }}</p>

                <ul v-else class="divide-y divide-line">
                    <li v-for="role in shown" :key="role.id" class="flex items-center gap-3.5 px-4 py-3.5 sm:px-5">
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl ring-1"
                            :class="role.owned_here ? 'bg-brand-soft text-brand-text ring-brand/20' : 'bg-subtle text-muted ring-line'"
                            aria-hidden="true"
                        >
                            <KeyRound class="size-4" />
                        </span>
                        <button type="button" class="min-w-0 flex-1 text-start" @click="open(role)">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-[13.5px] font-medium text-fg">{{ role.name }}</span>
                                <AppBadge v-if="role.owned_here" tone="brand">{{ t('access.list.made_here') }}</AppBadge>
                                <AppBadge v-else tone="neutral" :icon="CornerDownRight">{{ t('access.list.from', { name: role.organization?.name ?? '' }) }}</AppBadge>
                                <AppBadge v-if="!role.editable" tone="outline" :icon="Lock" :title="role.organization ? t('access.list.read_only_hint', { name: role.organization.name }) : ''">
                                    {{ t('access.list.read_only') }}
                                </AppBadge>
                            </span>
                            <span v-if="role.description" class="mt-0.5 block truncate text-[12.5px] text-muted">{{ role.description }}</span>
                        </button>
                        <span class="hidden w-32 text-end text-[12.5px] text-muted md:block">{{ t('access.list.permissions', { count: role.permissions.length }) }}</span>
                        <span class="hidden w-32 text-end text-[12.5px] text-muted sm:block">{{ membersText(role.members_count) }}</span>
                        <AppMenu :items="actionsFor(role)" :label="t('access.list.actions', { name: role.name })">
                            <template #trigger="{ toggle, attrs }">
                                <AppButton variant="ghost" size="icon-sm" :icon="MoreHorizontal" v-bind="attrs" @click="toggle(false)" />
                            </template>
                        </AppMenu>
                    </li>
                </ul>
            </section>
        </template>

        <!-- Choose how to start -->
        <AppDialog :open="choosing" size="lg" :title="t('access.create.title')" :description="t('access.create.text')" :icon="FilePlus2" @close="choosing = false">
            <button
                type="button"
                class="mb-5 flex w-full items-center gap-3 rounded-xl border border-dashed border-line-strong p-3.5 text-start transition hover:border-brand/40 hover:bg-brand-soft/40"
                @click="startBlank"
            >
                <span class="grid size-9 place-items-center rounded-lg bg-subtle text-fg-2" aria-hidden="true"><Plus class="size-4" /></span>
                <span>
                    <span class="block text-[13.5px] font-medium text-fg">{{ t('access.create.blank') }}</span>
                    <span class="block text-[12.5px] text-muted">{{ t('access.create.blank_text') }}</span>
                </span>
            </button>

            <SkeletonRows v-if="templates.loading.value && !templates.data.value" :rows="3" />
            <ErrorState v-else-if="templates.error.value" compact :error="templates.error.value" @retry="templates.reload()" />
            <div v-for="group in templateGroups" v-else :key="group.key" class="mb-4 last:mb-0">
                <p class="mb-2 text-[11px] font-semibold tracking-wider text-faint uppercase">{{ t('access.create.templates') }} · {{ group.label }}</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="template in group.items"
                        :key="template.key"
                        type="button"
                        class="flex flex-col items-start gap-1 rounded-xl border border-line bg-surface p-3.5 text-start transition hover:border-brand/40 hover:shadow-card"
                        @click="useTemplate(template)"
                    >
                        <span class="flex items-center gap-2 text-[13.5px] font-medium text-fg">
                            <LayoutTemplate class="size-4 text-brand-text" aria-hidden="true" />{{ template.name }}
                        </span>
                        <span class="text-[12.5px] leading-snug text-muted">{{ template.description }}</span>
                        <span class="mt-1 text-[12px] text-faint">{{ t('access.list.permissions', { count: template.permissions.length - template.not_grantable.length }) }}</span>
                        <span v-if="template.not_grantable.length" class="flex items-center gap-1 text-[12px] text-warn">
                            <Lock class="size-3" aria-hidden="true" />{{ t('access.create.left_out', { count: template.not_grantable.length }) }}
                        </span>
                    </button>
                </div>
            </div>
        </AppDialog>

        <RoleEditor
            :open="editor.open"
            :organization-id="orgId"
            :role="editor.role"
            :seed="editor.seed"
            :groups="groups"
            :pairs="pairs"
            @close="editor = { ...editor, open: false }"
            @saved="afterSave"
            @reload="reloadEdited"
        />
    </div>
</template>
