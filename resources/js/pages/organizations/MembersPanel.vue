<script setup>
import { computed, reactive, ref } from 'vue';
import { KeyRound, MoreHorizontal, Search, ShieldCheck, UserCheck, UserPlus, UserRound, UserX, UsersRound } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppMenu from '@/components/AppMenu.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import MemberRolesDialog from './MemberRolesDialog.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { confirmAction } from '@/lib/dialogs';
import { session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

const props = defineProps({
    organization: { type: Object, required: true },
});

const members = useResource(() => api(`/api/organizations/${props.organization.id}/members`, { query: { per_page: 100 } }).then((response) => response.data));
const query = ref('');

const shown = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return (members.data.value ?? []).filter(
        (member) => !needle || member.user?.name?.toLocaleLowerCase().includes(needle) || member.user?.email?.toLocaleLowerCase().includes(needle),
    );
});

const STATUS_TONES = { active: 'ok', invited: 'brand', suspended: 'warn' };

// Only owners make owners or change an owner (the server enforces it too).
const actingOwner = computed(() => session.me?.context?.membership_type === 'owner');
const memberTypes = computed(() => (actingOwner.value ? ['staff', 'owner', 'portal'] : ['staff', 'portal']));

// Roles
const rolesFor = ref(null);

// Add member
const adding = ref(false);
const saving = ref(false);
const form = reactive({ email: '', membership_type: 'staff', access_scope: 'own' });
const errors = ref({});

function openAdd() {
    Object.assign(form, { email: '', membership_type: 'staff', access_scope: 'own' });
    errors.value = {};
    adding.value = true;
}

async function add() {
    errors.value = {};
    if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) {
        errors.value.email = t('orgs.members.email_invalid');
        return;
    }
    saving.value = true;
    try {
        const body = { email: form.email.trim(), membership_type: form.membership_type };
        if (props.organization.type === 'group') body.access_scope = form.access_scope;
        await api(`/api/organizations/${props.organization.id}/members`, { method: 'POST', body });
        toast.success(t('orgs.members.added', { email: body.email }));
        adding.value = false;
        members.reload();
    } catch (error) {
        errors.value = error.status === 422 ? { email: error.field('email'), membership_type: error.field('membership_type'), form: error.field('email') ? null : error.message } : { form: error.message };
    } finally {
        saving.value = false;
    }
}

async function change(member, changes, message) {
    try {
        await api(`/api/organizations/${props.organization.id}/members/${member.id}`, { method: 'PATCH', body: changes });
        toast.success(message);
        members.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

async function suspend(member) {
    const ok = await confirmAction({
        title: t('orgs.members.suspend_title', { name: member.user?.name ?? '' }),
        message: t('orgs.members.suspend_text'),
        danger: true,
        confirmLabel: t('orgs.members.suspend'),
    });
    if (ok) change(member, { status: 'suspended' }, t('orgs.members.suspended', { name: member.user?.name ?? '' }));
}

function actionsFor(member) {
    const isOwner = member.membership_type === 'owner';
    const ownership = actingOwner.value || !isOwner;

    return [
        member.membership_type !== 'portal' && { label: t('access.members.change'), icon: KeyRound, onSelect: () => (rolesFor.value = member) },
        actingOwner.value &&
            (!isOwner
                ? { label: t('orgs.members.make_owner'), icon: ShieldCheck, onSelect: () => change(member, { membership_type: 'owner' }, t('orgs.members.updated')) }
                : { label: t('orgs.members.make_staff'), icon: UserRound, onSelect: () => change(member, { membership_type: 'staff' }, t('orgs.members.updated')) }),
        ownership && { divider: true },
        ownership &&
            (member.status === 'suspended'
                ? { label: t('orgs.members.reactivate'), icon: UserCheck, onSelect: () => change(member, { status: 'active' }, t('orgs.members.reactivated', { name: member.user?.name ?? '' })) }
                : { label: t('orgs.members.suspend'), icon: UserX, danger: true, onSelect: () => suspend(member) }),
    ].filter(Boolean);
}

function rolesSaved() {
    rolesFor.value = null;
    members.reload();
}

const isSelf = (member) => member.user?.id === session.me?.user?.id;
const initials = (name) =>
    (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
</script>

<template>
    <section class="card">
        <header class="flex flex-col gap-3 border-b border-line px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                <input v-model="query" type="search" class="field-input h-9 min-h-9 ps-9" :placeholder="t('orgs.members.search')" :aria-label="t('orgs.members.search')" />
            </div>
            <AppButton variant="primary" :icon="UserPlus" @click="openAdd">{{ t('orgs.members.add') }}</AppButton>
        </header>

        <SkeletonRows v-if="members.loading.value && !members.data.value" :rows="4" avatar />
        <ErrorState v-else-if="members.error.value" compact :error="members.error.value" @retry="members.reload()" />
        <EmptyState v-else-if="!shown.length && !query" :icon="UsersRound" :title="t('orgs.members.empty_title')" :text="t('orgs.members.empty_text')" compact>
            <AppButton variant="primary" :icon="UserPlus" @click="openAdd">{{ t('orgs.members.add') }}</AppButton>
        </EmptyState>
        <p v-else-if="!shown.length" class="px-5 py-10 text-center text-[13.5px] text-muted">{{ t('orgs.members.no_match') }}</p>

        <ul v-else class="divide-y divide-line">
            <li v-for="member in shown" :key="member.id" class="flex items-center gap-3.5 px-4 py-3 sm:px-5">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-subtle text-[12px] font-semibold text-fg-2 ring-1 ring-line" aria-hidden="true">
                    {{ initials(member.user?.name) }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 truncate text-[13.5px] font-medium text-fg">
                        {{ member.user?.name }}
                        <AppBadge v-if="isSelf(member)" tone="outline">{{ t('orgs.members.you') }}</AppBadge>
                    </p>
                    <p class="truncate text-[12.5px] text-muted">{{ member.user?.email ?? member.user?.phone }}</p>
                </div>
                <div class="hidden items-center gap-2 md:flex">
                    <AppBadge :tone="member.membership_type === 'owner' ? 'brand' : 'neutral'">{{ t(`core.membership_types.${member.membership_type}`) }}</AppBadge>
                    <AppBadge v-if="member.access_scope === 'descendants'" tone="outline">{{ t('orgs.members.scope_descendants') }}</AppBadge>
                </div>
                <div class="hidden max-w-[16rem] flex-wrap items-center justify-end gap-1 lg:flex" :aria-label="t('access.members.roles')">
                    <AppBadge v-for="role in (member.roles ?? []).slice(0, 2)" :key="role.id" tone="outline" :icon="KeyRound">{{ role.name }}</AppBadge>
                    <AppBadge v-if="(member.roles ?? []).length > 2" tone="outline" :title="member.roles.slice(2).map((role) => role.name).join(', ')">+{{ formatNumber(member.roles.length - 2) }}</AppBadge>
                    <span v-if="!(member.roles ?? []).length && member.membership_type === 'staff'" class="text-[12px] text-faint">{{ t('access.members.no_roles') }}</span>
                </div>
                <AppBadge :tone="STATUS_TONES[member.status] ?? 'neutral'" dot>{{ t(`orgs.members.status.${member.status}`) }}</AppBadge>
                <span class="hidden w-28 text-end text-[12.5px] text-muted lg:block">{{ formatDate(member.created_at) }}</span>
                <AppMenu v-if="!isSelf(member)" :items="actionsFor(member)" :label="t('orgs.members.actions', { name: member.user?.name ?? '' })">
                    <template #trigger="{ toggle, attrs }">
                        <AppButton variant="ghost" size="icon-sm" :icon="MoreHorizontal" v-bind="attrs" @click="toggle(false)" />
                    </template>
                </AppMenu>
                <span v-else class="w-8" aria-hidden="true" />
            </li>
        </ul>

        <AppDialog :open="adding" :title="t('orgs.members.add_title')" :description="t('orgs.members.add_text', { name: organization.display_name })" :icon="UserPlus" @close="adding = false">
            <form id="member-form" class="space-y-4" novalidate @submit.prevent="add">
                <p v-if="errors.form" class="rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-2.5 text-[13px] text-fg-2" role="alert">{{ errors.form }}</p>
                <AppField :label="t('orgs.members.email')" :hint="t('orgs.members.email_hint')" :error="errors.email">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.email" type="email" data-autofocus class="field-input" autocomplete="off" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('orgs.members.role')" :error="errors.membership_type">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.membership_type" class="field-input">
                            <option v-for="type in memberTypes" :key="type" :value="type">{{ t(`core.membership_types.${type}`) }} — {{ t(`orgs.members.role_hint.${type}`) }}</option>
                        </select>
                    </template>
                </AppField>
                <AppField v-if="organization.type === 'group'" :label="t('orgs.members.scope')" :hint="t('orgs.members.scope_hint')">
                    <template #default="{ id, describedby }">
                        <select :id="id" v-model="form.access_scope" class="field-input" :aria-describedby="describedby">
                            <option value="own">{{ t('orgs.members.scope_own') }}</option>
                            <option value="descendants">{{ t('orgs.members.scope_descendants') }}</option>
                        </select>
                    </template>
                </AppField>
            </form>
            <template #footer>
                <AppButton @click="adding = false">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="member-form" variant="primary" :loading="saving">{{ t('orgs.members.add') }}</AppButton>
            </template>
        </AppDialog>

        <MemberRolesDialog :open="!!rolesFor" :organization="organization" :member="rolesFor" @close="rolesFor = null" @saved="rolesSaved" />
    </section>
</template>
