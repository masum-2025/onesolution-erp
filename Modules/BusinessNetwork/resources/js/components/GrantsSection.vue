<script setup>
import { computed, reactive, watch } from 'vue';
import { Save } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * What the provider may do in the client's data, module by module (NET-2).
 * The client switches a module on, ticks its actions (see, add, change,
 * delete...) and may set a last day; switching it off takes it back at
 * once. The provider sees the same list, read-only.
 */
const props = defineProps({
    item: { type: Object, required: true },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['saved']);

const active = computed(() => props.item.grants.filter((grant) => grant.active));
// One draft per module the client could give: on/off, ticked actions, last day.
const drafts = reactive({});
watch(() => props.item, (item) => {
    for (const key of Object.keys(drafts)) delete drafts[key];
    for (const module of item.grantable) {
        const grant = item.grants.find((row) => row.module === module.module && row.active);
        drafts[module.module] = {
            on: !!grant,
            permissions: grant ? [...grant.permissions] : [],
            expiresOn: grant?.expires_on ?? '',
            busy: false,
            error: null,
        };
    }
}, { immediate: true });

function savedOf(module) {
    return props.item.grants.find((row) => row.module === module && row.active) ?? null;
}
function changed(module) {
    const draft = drafts[module];
    const grant = savedOf(module);
    if (!grant) return draft.on;
    if (!draft.on) return true;
    return [...draft.permissions].sort().join() !== [...grant.permissions].sort().join() || draft.expiresOn !== (grant.expires_on ?? '');
}
const today = new Date().toISOString().slice(0, 10);

async function save(module) {
    const draft = drafts[module.module];
    if (!draft.on) {
        const ok = await confirmAction({
            title: t('business_network.grants.revoke_title', { module: module.name }),
            message: t('business_network.grants.revoke_text', { name: props.item.other }),
            confirmLabel: t('business_network.grants.revoke'),
            danger: true,
        });
        if (!ok) {
            draft.on = true;
            return;
        }
    }
    draft.busy = true;
    draft.error = null;
    try {
        const { data } = draft.on
            ? await props.calls.grant(props.item.id, module.module, draft.permissions, draft.expiresOn)
            : await props.calls.revoke(props.item.id, module.module);
        toast.success(t(draft.on ? 'business_network.grants.saved' : 'business_network.grants.revoked', { module: module.name }));
        emit('saved', data);
    } catch (caught) {
        draft.error = caught.errors?.permissions?.[0] ?? caught.errors?.expires_on?.[0] ?? caught.message;
    } finally {
        draft.busy = false;
    }
}
</script>

<template>
    <section :aria-label="t('business_network.grants.title')">
        <h3 class="text-[13px] font-semibold text-fg-2">{{ t('business_network.grants.title') }}</h3>
        <p class="mb-2 text-[12.5px] text-muted">{{ t(item.can.grants ? 'business_network.grants.hint_client' : 'business_network.grants.hint_provider') }}</p>

        <!-- The client gives and takes back. -->
        <ul v-if="item.can.grants" class="divide-y divide-line rounded-xl border border-line">
            <li v-if="!item.grantable.length" class="px-3 py-3 text-[13px] text-muted">{{ t('business_network.grants.none_grantable') }}</li>
            <li v-for="module in item.grantable" :key="module.module" class="space-y-2.5 px-3 py-3">
                <label class="flex items-center gap-3 text-[13.5px] font-medium">
                    <input v-model="drafts[module.module].on" type="checkbox" role="switch" class="size-4 accent-[var(--color-brand)]" />
                    <span class="flex-1">{{ module.name }}</span>
                    <AppBadge v-if="savedOf(module.module)" tone="ok">{{ t('business_network.grants.given') }}</AppBadge>
                </label>
                <template v-if="drafts[module.module].on">
                    <fieldset class="flex flex-wrap gap-x-4 gap-y-1.5 ps-7">
                        <legend class="sr-only">{{ t('business_network.grants.actions', { module: module.name }) }}</legend>
                        <label v-for="permission in module.permissions" :key="permission.key" class="flex items-center gap-2 text-[13px]">
                            <input v-model="drafts[module.module].permissions" type="checkbox" :value="permission.key" class="size-4" />
                            {{ permission.label }}
                        </label>
                    </fieldset>
                    <label class="flex flex-wrap items-center gap-2 ps-7 text-[13px] text-fg-2">
                        {{ t('business_network.grants.until') }}
                        <input v-model="drafts[module.module].expiresOn" type="date" :min="today" class="field-input w-auto py-1" />
                        <span class="text-[12px] text-muted">{{ t('business_network.grants.until_hint') }}</span>
                    </label>
                </template>
                <p v-if="drafts[module.module].error" class="ps-7 text-[12.5px] text-bad" role="alert">{{ drafts[module.module].error }}</p>
                <div v-if="changed(module.module)" class="ps-7">
                    <AppButton size="sm" :variant="drafts[module.module].on ? 'primary' : 'ghost'" :icon="Save" :loading="drafts[module.module].busy"
                        :disabled="drafts[module.module].on && !drafts[module.module].permissions.length" @click="save(module)">
                        {{ drafts[module.module].on ? t('business_network.grants.save') : t('business_network.grants.revoke') }}
                    </AppButton>
                </div>
            </li>
        </ul>

        <!-- The provider (or a unit that only reads) sees what it was given. -->
        <template v-else>
            <p v-if="!active.length" class="rounded-lg bg-subtle px-3 py-2 text-[13px]">{{ t('business_network.grants.none_given') }}</p>
            <ul v-else class="divide-y divide-line rounded-xl border border-line">
                <li v-for="grant in active" :key="grant.module" class="px-3 py-2.5 text-[13px]">
                    <p class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ grant.name }}</span>
                        <span v-if="grant.expires_on" class="text-[12px] text-muted">{{ t('business_network.grants.ends', { date: formatDate(grant.expires_on, { dateStyle: 'medium', timeZone: 'UTC' }) }) }}</span>
                    </p>
                    <p class="text-[12.5px] text-muted">{{ grant.labels.join(' · ') }}</p>
                </li>
            </ul>
        </template>
    </section>
</template>
