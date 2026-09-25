<script setup>
import { computed, ref, watch } from 'vue';
import { CornerDownRight, KeyRound, Lock, TriangleAlert } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { conflictsIn, permissionLabels, permissionsOfRoles } from '@/lib/permissions';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Choose the roles one member holds. Roles with permissions this person
 * does not hold are locked (nobody grants or removes more than they have);
 * separation-of-duties clashes show before saving. The server checks again.
 */
const props = defineProps({
    open: Boolean,
    organization: { type: Object, required: true },
    member: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const base = computed(() => `/api/organizations/${props.organization.id}`);
const roles = useResource(() => api(`${base.value}/roles`).then((response) => response.data), { immediate: false });
const permissions = useResource(() => api(`${base.value}/permissions`), { immediate: false });

const chosen = ref([]);
const reason = ref('');
const errors = ref({});
const saving = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        chosen.value = (props.member?.roles ?? []).map((role) => role.id);
        reason.value = '';
        errors.value = {};
        roles.reload();
        permissions.reload();
    },
);

const name = computed(() => props.member?.user?.name ?? '');
const isPortal = computed(() => props.member?.membership_type === 'portal');
const list = computed(() => [...(roles.data.value ?? [])].sort((a, b) => Number(b.owned_here) - Number(a.owned_here) || a.name.localeCompare(b.name)));
const pairs = computed(() => permissions.data.value?.separation_of_duties ?? []);
const labels = computed(() => permissionLabels(permissions.data.value?.data));
const total = computed(() => permissionsOfRoles(list.value, chosen.value));
const conflicts = computed(() => conflictsIn(total.value, pairs.value));
const changed = computed(() => [...chosen.value].sort().join() !== (props.member?.roles ?? []).map((role) => role.id).sort().join());

function toggle(role) {
    if (!role.assignable) return;
    chosen.value = chosen.value.includes(role.id) ? chosen.value.filter((id) => id !== role.id) : [...chosen.value, role.id];
}

async function save() {
    errors.value = {};
    if (reason.value.trim().length < 5) {
        errors.value.reason = t('core.confirm.reason_short');
        return;
    }
    saving.value = true;
    try {
        await api(`${base.value}/members/${props.member.id}/roles`, { method: 'PUT', body: { role_ids: chosen.value, reason: reason.value.trim() } });
        toast.success(t('access.members.saved', { name: name.value }));
        emit('saved');
    } catch (error) {
        errors.value = { reason: error.field('reason'), form: error.field('reason') ? null : error.message };
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog
        :open="open"
        size="lg"
        :title="t('access.members.dialog_title', { name })"
        :description="t('access.members.dialog_text', { name, org: organization.display_name })"
        :icon="KeyRound"
        @close="emit('close')"
    >
        <p v-if="isPortal" class="rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">{{ t('access.members.portal') }}</p>

        <template v-else>
            <SkeletonRows v-if="roles.loading.value && !roles.data.value" :rows="3" />
            <ErrorState v-else-if="roles.error.value" compact :error="roles.error.value" @retry="roles.reload()" />
            <div v-else-if="!list.length" class="rounded-xl bg-subtle p-4 text-center text-[13px] text-muted">
                {{ t('access.members.dialog_empty') }}
                <RouterLink :to="{ path: '/roles', query: { org: organization.id } }" class="ms-1 font-medium text-brand-text hover:underline">{{ t('access.members.create_link') }}</RouterLink>
            </div>

            <form v-else id="member-roles-form" class="space-y-4" novalidate @submit.prevent="save">
                <p v-if="errors.form" class="rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-2.5 text-[13px] text-fg-2" role="alert">{{ errors.form }}</p>

                <fieldset class="overflow-hidden rounded-xl border border-line">
                    <legend class="sr-only">{{ t('access.members.roles') }}</legend>
                    <ul class="divide-y divide-line">
                        <li v-for="role in list" :key="role.id">
                            <label class="flex items-start gap-3 px-3.5 py-3" :class="role.assignable ? 'cursor-pointer hover:bg-subtle/70' : 'cursor-default'">
                                <input
                                    type="checkbox"
                                    class="mt-0.5 size-4 shrink-0 rounded accent-brand disabled:opacity-60"
                                    :checked="chosen.includes(role.id)"
                                    :disabled="!role.assignable"
                                    @change="toggle(role)"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-[13.5px] font-medium" :class="role.assignable ? 'text-fg' : 'text-muted'">{{ role.name }}</span>
                                        <AppBadge v-if="!role.owned_here" tone="neutral" :icon="CornerDownRight">{{ t('access.list.from', { name: role.organization?.name ?? '' }) }}</AppBadge>
                                    </span>
                                    <span v-if="role.description" class="mt-0.5 block text-[12.5px] text-muted">{{ role.description }}</span>
                                    <span v-if="!role.assignable" class="mt-0.5 flex items-center gap-1 text-[12px] text-muted">
                                        <Lock class="size-3" aria-hidden="true" />{{ t('access.members.not_assignable') }}
                                    </span>
                                </span>
                                <span class="shrink-0 pt-0.5 text-[12px] text-faint">{{ t('access.list.permissions', { count: role.permissions.length }) }}</span>
                            </label>
                        </li>
                    </ul>
                </fieldset>

                <p class="text-[12.5px] text-muted">{{ t('access.members.summary', { count: total.length }) }}</p>

                <div v-if="conflicts.length" class="space-y-1.5 rounded-xl border border-warn/25 bg-warn-soft p-3.5" role="alert">
                    <p v-for="pair in conflicts" :key="`${pair.first}|${pair.second}`" class="flex items-start gap-2 text-[13px] text-fg-2">
                        <TriangleAlert class="mt-px size-4 shrink-0 text-warn" aria-hidden="true" />
                        {{ t('access.matrix.conflict', { first: labels[pair.first] ?? pair.first, second: labels[pair.second] ?? pair.second }) }}
                    </p>
                </div>

                <AppField :label="t('access.members.reason')" :hint="t('core.confirm.reason_hint')" :error="errors.reason">
                    <template #default="{ id, invalid, describedby }">
                        <input
                            :id="id"
                            v-model="reason"
                            class="field-input"
                            maxlength="500"
                            :placeholder="t('access.members.reason_placeholder')"
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedby"
                        />
                    </template>
                </AppField>
            </form>
        </template>

        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton
                v-if="!isPortal && list.length"
                type="submit"
                form="member-roles-form"
                variant="primary"
                :loading="saving"
                :disabled="!changed || conflicts.length > 0"
            >
                {{ t('access.members.save') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
