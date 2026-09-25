<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Blocks, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import OrgPicker from '@/components/OrgPicker.vue';
import ModuleCard from './ModuleCard.vue';
import { api } from '@/lib/http';
import { invalidate } from '@/lib/cache';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { can, currentOrganization } from '@/lib/session';
import { visibleOrganizations } from '@/lib/organizations';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

const route = useRoute();
const router = useRouter();
const context = currentOrganization();

const orgId = ref(typeof route.query.org === 'string' ? route.query.org : context.id);
const orgName = ref(context.name);
const filter = ref('all');
const query = ref('');
const busy = ref(null);

const modules = useResource(() => api(`/api/organizations/${orgId.value}/modules`).then((response) => response.data));

watch(orgId, async (id) => {
    router.replace({ query: { ...route.query, org: id === context.id ? undefined : id } });
    orgName.value = (await visibleOrganizations()).find((org) => org.id === id)?.display_name ?? orgName.value;
    modules.reload();
});

const names = computed(() => Object.fromEntries((modules.data.value ?? []).map((module) => [module.key, module.name])));

const shown = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return (modules.data.value ?? [])
        .filter((module) => filter.value === 'all' || (filter.value === 'on' ? module.enabled : !module.enabled))
        .filter((module) => !needle || module.name.toLocaleLowerCase().includes(needle) || module.description.toLocaleLowerCase().includes(needle))
        .sort((a, b) => Number(b.is_core) - Number(a.is_core) || Number(b.enabled) - Number(a.enabled) || a.name.localeCompare(b.name));
});

const counts = computed(() => {
    const list = modules.data.value ?? [];
    return { all: list.length, on: list.filter((module) => module.enabled).length, off: list.filter((module) => !module.enabled).length };
});

const filters = computed(() => [
    { value: 'all', label: t('modules.filter.all', { count: counts.value.all }) },
    { value: 'on', label: t('modules.filter.on', { count: counts.value.on }) },
    { value: 'off', label: t('modules.filter.off', { count: counts.value.off }) },
]);

const manageable = computed(() => can('modules.manage'));
const base = (key) => `/api/organizations/${orgId.value}/modules/${key}`;

function afterChange() {
    invalidate('menu');
    modules.reload();
}

async function inherit(module, reason, message = t('modules.messages.inherited', { name: module.name })) {
    await api(`${base(module.key)}/inherit`, { method: 'POST', body: { reason } });
    toast.success(message);
    afterChange();
}

async function toggle(module) {
    const turningOn = !module.enabled;
    const answer = await confirmAction({
        title: turningOn ? t('modules.confirm.on_title', { name: module.name }) : t('modules.confirm.off_title', { name: module.name }),
        message: turningOn ? t('modules.confirm.on_text', { org: orgName.value }) : t('modules.confirm.off_text', { org: orgName.value }),
        reason: 'required',
        danger: !turningOn,
        checkbox: { label: t('modules.confirm.lock'), hint: t('modules.confirm.lock_hint'), checked: false },
        confirmLabel: turningOn ? t('modules.actions.turn_on_short') : t('modules.actions.turn_off_short'),
    });
    if (!answer) return;

    const wasInherited = module.state === 'inherit';
    busy.value = module.key;
    try {
        const body = { reason: answer.reason, lock: answer.checked };
        let response;
        try {
            response = await api(`${base(module.key)}/${turningOn ? 'enable' : 'disable'}`, { method: 'POST', body });
        } catch (error) {
            if (error.status !== 409 || error.code !== 'dependents_need_confirmation') throw error;
            const dependents = (error.data?.dependents ?? []).map((key) => names.value[key] ?? key);
            const again = await confirmAction({
                title: t('modules.confirm.dependents_title'),
                message: t('modules.confirm.dependents_text', { name: module.name }),
                details: dependents,
                danger: true,
                confirmLabel: t('modules.confirm.dependents_submit', { count: dependents.length + 1 }),
            });
            if (!again) return;
            response = await api(`${base(module.key)}/disable`, { method: 'POST', body: { ...body, confirm: true } });
        }

        const extra = response.data.auto_enabled?.length
            ? t('modules.messages.also_on', { names: response.data.auto_enabled.map((key) => names.value[key] ?? key).join(', ') })
            : response.data.also_disabled?.length
              ? t('modules.messages.also_off', { names: response.data.also_disabled.map((key) => names.value[key] ?? key).join(', ') })
              : '';
        const message = `${turningOn ? t('modules.messages.on', { name: module.name }) : t('modules.messages.off', { name: module.name })} ${extra}`.trim();

        // Undo is exact only when the module simply followed its parent before
        // and no other module was switched along with it.
        const exact = wasInherited && !extra;
        toast.success(message, exact ? { action: { label: t('core.actions.undo'), run: () => inherit(module, t('modules.undo_reason'), t('modules.messages.undone')) } } : undefined);
        afterChange();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function askInherit(module) {
    const answer = await confirmAction({
        title: t('modules.confirm.inherit_title', { name: module.name }),
        message: t('modules.confirm.inherit_text'),
        reason: 'required',
        confirmLabel: t('modules.actions.inherit'),
    });
    if (!answer) return;
    try {
        await inherit(module, answer.reason);
    } catch (error) {
        toast.error(error.message);
    }
}

async function consent(module) {
    const answer = await confirmAction({
        title: t('modules.consent.title', { name: module.name }),
        message: t('modules.consent.text'),
        reason: 'optional',
        confirmLabel: t('modules.consent.submit'),
        checkbox: { label: t('modules.consent.agree', { version: module.consent_terms_version }), checked: false },
    });
    if (!answer) return;
    if (!answer.checked) {
        toast.error(t('modules.consent.must_agree'));
        return;
    }
    try {
        await api(`${base(module.key)}/consent`, { method: 'POST', body: { terms_version: module.consent_terms_version, reason: answer.reason } });
        toast.success(t('modules.messages.consent_given', { name: module.name }));
        afterChange();
    } catch (error) {
        toast.error(error.message);
    }
}

async function withdrawConsent(module) {
    const answer = await confirmAction({
        title: t('modules.consent.withdraw_title', { name: module.name }),
        message: t('modules.consent.withdraw_text'),
        reason: 'required',
        danger: true,
        confirmLabel: t('modules.consent.withdraw'),
    });
    if (!answer) return;
    try {
        await api(`${base(module.key)}/consent`, { method: 'DELETE', body: { reason: answer.reason } });
        toast.success(t('modules.messages.consent_withdrawn', { name: module.name }));
        afterChange();
    } catch (error) {
        toast.error(error.message);
    }
}

async function purge(module) {
    const answer = await confirmAction({
        title: t('modules.purge.title', { name: module.name }),
        message: t('modules.purge.text', { org: orgName.value }),
        reason: 'required',
        danger: true,
        typeToConfirm: module.key,
        confirmLabel: t('modules.purge.submit'),
    });
    if (!answer) return;
    try {
        const { data } = await api(`${base(module.key)}/purge`, { method: 'POST', body: { confirm_text: module.key, reason: answer.reason } });
        toast.success(t('modules.purge.scheduled', { name: module.name, date: new Date(data.execute_after).toLocaleDateString() }), {
            action: {
                label: t('core.actions.undo'),
                run: async () => {
                    try {
                        await api(`${base(module.key)}/purge`, { method: 'DELETE', body: { reason: t('modules.purge.undo_reason') } });
                        toast.success(t('modules.purge.cancelled'));
                    } catch (error) {
                        toast.error(error.message);
                    }
                },
            },
        });
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('modules.title')" :description="t('modules.text')">
            <template #actions>
                <OrgPicker v-model="orgId" compact :label="t('core.org_picker.scope')" />
            </template>
        </PageHeader>

        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <AppSegmented v-model="filter" :options="filters" :label="t('modules.filter.label')" />
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                <input v-model="query" type="search" class="field-input h-9 min-h-9 ps-9" :placeholder="t('modules.search')" :aria-label="t('modules.search')" />
            </div>
        </div>

        <div v-if="modules.loading.value && !modules.data.value" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="status" :aria-label="t('core.states.loading')">
            <div v-for="n in 6" :key="n" class="card space-y-3 p-4">
                <div class="flex items-center gap-3"><div class="skeleton size-10 rounded-xl" /><div class="flex-1 space-y-2"><div class="skeleton h-3.5 w-1/2" /><div class="skeleton h-3 w-1/3" /></div></div>
                <div class="skeleton h-3 w-full" />
                <div class="skeleton h-3 w-4/5" />
            </div>
        </div>
        <ErrorState v-else-if="modules.error.value" :error="modules.error.value" @retry="modules.reload()" />
        <EmptyState v-else-if="!shown.length" :icon="Blocks" :title="t('modules.empty_title')" :text="t('modules.empty_text')" />

        <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <ModuleCard
                v-for="module in shown"
                :key="module.key"
                :module="module"
                :names="names"
                :manageable="manageable"
                :busy="busy === module.key"
                @toggle="toggle(module)"
                @inherit="askInherit(module)"
                @consent="consent(module)"
                @withdraw-consent="withdrawConsent(module)"
                @purge="purge(module)"
            />
        </div>

        <p v-if="!manageable && modules.data.value" class="mt-6 text-center text-[12.5px] text-muted">{{ t('modules.read_only') }}</p>
    </div>
</template>
