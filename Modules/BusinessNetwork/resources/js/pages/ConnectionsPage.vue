<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Copy, KeyRound, Network, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDate, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { connectionsApi, statusTone } from '../api';
import ConnectionDrawer from '../components/ConnectionDrawer.vue';
import UnitChecklist from '../components/UnitChecklist.vue';

/**
 * Business connections: the clients this company serves (it invites them
 * by a code) and the service providers this group or company works with (it
 * enters their code and chooses which factories they may deal with).
 */
const org = currentOrganization();
const calls = connectionsApi(org.id);
const list = useResource(() => calls.list());
const all = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? { can_invite: false, can_accept: false });

const part = ref('clients');
const parts = computed(() => [{ value: 'clients', label: t('business_network.parts.clients') }, { value: 'providers', label: t('business_network.parts.providers') }]);
// Open on the part that has something, a provider's clients first.
watch(all, (rows) => {
    if (!rows.some((row) => row.side === 'provider') && rows.some((row) => row.side === 'client')) part.value = 'providers';
}, { once: true });
const shown = computed(() => all.value.filter((row) => row.side === (part.value === 'clients' ? 'provider' : 'client')));

const openId = ref(null);

// Inviting: the code is shown this once.
const invite = reactive({ open: false, note: '', saving: false, code: null, expires: null });
function startInvite() {
    Object.assign(invite, { open: true, note: '', saving: false, code: null, expires: null });
}
async function makeInvite() {
    invite.saving = true;
    try {
        const { data } = await calls.invite(invite.note.trim());
        Object.assign(invite, { code: data.code, expires: data.expires_at });
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        invite.saving = false;
    }
}
async function copyCode() {
    try {
        await navigator.clipboard.writeText(invite.code);
        toast.success(t('business_network.invite.copied'));
    } catch {
        toast.error(t('business_network.invite.copy_failed'));
    }
}

// Accepting: the code, who invites, then the factories to share.
const accept = reactive({ open: false, code: '', busy: false, error: null, preview: null, units: [], unitsError: null });
function startAccept() {
    Object.assign(accept, { open: true, code: '', busy: false, error: null, preview: null, units: [], unitsError: null });
}
async function lookUp() {
    accept.busy = true;
    accept.error = null;
    try {
        accept.preview = (await calls.preview(accept.code.trim())).data;
    } catch (error) {
        accept.error = error.errors?.code?.[0] ?? error.message;
    } finally {
        accept.busy = false;
    }
}
async function confirmAccept() {
    accept.busy = true;
    accept.unitsError = null;
    try {
        const { data } = await calls.accept(accept.code.trim(), accept.units);
        toast.success(t('business_network.accept.done', { name: data.other }));
        accept.open = false;
        part.value = 'providers';
        list.reload();
        openId.value = data.id;
    } catch (error) {
        if (error.errors?.unit_ids || error.code === 'unit_not_yours' || error.code === 'units_needed') accept.unitsError = error.errors?.unit_ids?.[0] ?? error.message;
        else accept.error = error.message;
    } finally {
        accept.busy = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('business_network.title')" :description="t('business_network.text')">
            <template #actions>
                <AppButton v-if="meta.can_accept" :icon="KeyRound" @click="startAccept">{{ t('business_network.accept.button') }}</AppButton>
                <AppButton v-if="meta.can_invite" variant="primary" :icon="Plus" @click="startInvite">{{ t('business_network.invite.button') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-4">
            <AppSegmented v-model="part" size="sm" :label="t('business_network.title')" :options="parts" />
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!shown.length" :icon="Network" compact
                :title="t(`business_network.empty.${part}_title`)" :text="t(`business_network.empty.${part}_text`)">
                <AppButton v-if="part === 'clients' && meta.can_invite" variant="primary" :icon="Plus" @click="startInvite">{{ t('business_network.invite.button') }}</AppButton>
                <AppButton v-if="part === 'providers' && meta.can_accept" variant="primary" :icon="KeyRound" @click="startAccept">{{ t('business_network.accept.button') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="row in shown" :key="row.id">
                    <button type="button" class="flex w-full items-center gap-3 px-5 py-3.5 text-start transition hover:bg-subtle/60" @click="openId = row.id">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium text-fg">{{ row.other ?? row.note ?? t('business_network.list.waiting') }}</span>
                            <span class="block truncate text-[12.5px] text-muted">
                                <template v-if="row.status === 'invited'">{{ t('business_network.list.expires', { date: formatDate(row.expires_at) }) }}</template>
                                <template v-else-if="row.accepted_at">{{ t('business_network.list.since', { date: formatDate(row.accepted_at) }) }}<template v-if="row.note && row.other"> · {{ row.note }}</template></template>
                            </span>
                            <span v-if="row.status === 'active' || row.status === 'paused'" class="block truncate text-[12.5px] text-muted">
                                {{ row.modules.length ? t('business_network.list.modules', { count: row.modules.length, modules: formatNumber(row.modules.length) }) : t('business_network.list.no_modules') }}
                                <template v-if="row.last_used_at"> · {{ t('business_network.list.last_used', { date: formatDate(row.last_used_at) }) }}</template>
                            </span>
                        </span>
                        <AppBadge :tone="statusTone(row.status)">{{ t(`business_network.status.${row.status}`) }}</AppBadge>
                    </button>
                </li>
            </ul>
        </section>

        <ConnectionDrawer :open="openId !== null" :connection-id="openId" :calls="calls" @close="openId = null" @changed="list.reload()" />

        <AppDialog :open="invite.open" :title="t('business_network.invite.title')" :description="t('business_network.invite.text')" @close="invite.open = false">
            <form v-if="!invite.code" id="network-invite" class="space-y-4" novalidate @submit.prevent="makeInvite">
                <AppField v-slot="{ id }" :label="t('business_network.invite.note')" :hint="t('business_network.invite.note_hint')" optional>
                    <input :id="id" v-model="invite.note" class="field-input" maxlength="150" />
                </AppField>
            </form>
            <div v-else class="space-y-3">
                <p class="text-[13px] text-fg-2">{{ t('business_network.invite.give') }}</p>
                <p class="flex items-center gap-3 rounded-xl bg-subtle px-4 py-3">
                    <span class="flex-1 font-mono text-[20px] font-semibold tracking-wider" dir="ltr">{{ invite.code }}</span>
                    <AppButton size="sm" :icon="Copy" @click="copyCode">{{ t('business_network.invite.copy') }}</AppButton>
                </p>
                <p class="text-[12.5px] text-muted">{{ t('business_network.invite.once', { date: formatDate(invite.expires) }) }}</p>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="invite.open = false">{{ invite.code ? t('business_network.common.done') : t('business_network.common.cancel') }}</AppButton>
                <AppButton v-if="!invite.code" variant="primary" type="submit" form="network-invite" :loading="invite.saving">{{ t('business_network.invite.make') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="accept.open" :title="t('business_network.accept.title')" :description="t('business_network.accept.text')" @close="accept.open = false">
            <form id="network-accept" class="space-y-4" novalidate @submit.prevent="accept.preview ? confirmAccept() : lookUp()">
                <AppField v-slot="{ id }" :label="t('business_network.accept.code')" :error="accept.error">
                    <input :id="id" v-model="accept.code" class="field-input font-mono uppercase" dir="ltr" maxlength="30" autocomplete="off" :readonly="!!accept.preview" placeholder="ABCD-EFGH-JKMN" />
                </AppField>
                <template v-if="accept.preview">
                    <p class="rounded-xl bg-subtle px-4 py-3 text-[13.5px]">{{ t('business_network.accept.from', { name: accept.preview.provider }) }}</p>
                    <UnitChecklist v-model="accept.units" :units="accept.preview.units" :error="accept.unitsError" />
                </template>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="accept.open = false">{{ t('business_network.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="network-accept" :loading="accept.busy"
                    :disabled="accept.preview ? !accept.units.length : accept.code.trim().length < 12">{{ accept.preview ? t('business_network.accept.confirm') : t('business_network.accept.look_up') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
