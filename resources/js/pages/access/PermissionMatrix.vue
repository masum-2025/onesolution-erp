<script setup>
import { computed } from 'vue';
import { Lock, PowerOff, Split, TriangleAlert } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import { conflictsIn, partnersOf, permissionLabels, toggleGroup } from '@/lib/permissions';
import { t } from '@/lib/i18n';

/**
 * Permissions grouped by module, as checkboxes. Permissions this person may
 * not grant (not held, module off) are shown but cannot be changed, with the
 * reason. Separation-of-duties pairs warn as soon as both are ticked.
 */
const props = defineProps({
    groups: { type: Array, required: true }, // from GET /permissions
    pairs: { type: Array, default: () => [] },
    modelValue: { type: Array, required: true },
    readonly: Boolean,
});

const emit = defineEmits(['update:modelValue']);

const selected = computed(() => new Set(props.modelValue));
const labels = computed(() => permissionLabels(props.groups));
const conflicts = computed(() => conflictsIn(props.modelValue, props.pairs));
const inConflict = computed(() => new Set(conflicts.value.flatMap((pair) => [pair.first, pair.second])));

function toggle(key) {
    const next = new Set(props.modelValue);
    next.has(key) ? next.delete(key) : next.add(key);
    emit('update:modelValue', [...next].sort());
}

function changeable(group) {
    return group.permissions.filter((permission) => !permission.blocked_by);
}

function allOn(group) {
    const keys = changeable(group).map((permission) => permission.key);
    return keys.length > 0 && keys.every((key) => selected.value.has(key));
}

function countIn(group) {
    return group.permissions.filter((permission) => selected.value.has(permission.key)).length;
}

function pairHint(key) {
    const partners = partnersOf(key, props.pairs);
    return partners.length ? t('access.matrix.pair_hint', { list: partners.map((partner) => labels.value[partner] ?? partner).join(', ') }) : null;
}

function blockedText(permission) {
    return permission.blocked_by === 'module_disabled' ? t('access.matrix.module_off_hint') : permission.blocked_by ? t('access.matrix.locked') : null;
}
</script>

<template>
    <div class="space-y-3">
        <div v-if="conflicts.length" class="space-y-1.5 rounded-xl border border-warn/25 bg-warn-soft p-3.5" role="alert">
            <p v-for="pair in conflicts" :key="`${pair.first}|${pair.second}`" class="flex items-start gap-2 text-[13px] leading-snug text-fg-2">
                <TriangleAlert class="mt-px size-4 shrink-0 text-warn" aria-hidden="true" />
                {{ t('access.matrix.conflict', { first: labels[pair.first] ?? pair.first, second: labels[pair.second] ?? pair.second }) }}
            </p>
        </div>

        <p v-if="!groups.length" class="py-6 text-center text-[13px] text-muted">{{ t('access.matrix.empty') }}</p>

        <fieldset v-for="group in groups" :key="group.group" class="overflow-hidden rounded-xl border border-line" :class="group.module_enabled ? 'bg-surface' : 'bg-subtle/60'">
            <legend class="sr-only">{{ group.label }}</legend>
            <div class="flex items-center gap-2.5 border-b border-line px-3.5 py-2.5">
                <span class="min-w-0 flex-1 truncate text-[13.5px] font-semibold text-fg">{{ group.label }}</span>
                <AppBadge v-if="!group.module_enabled" tone="outline" :icon="PowerOff" :title="t('access.matrix.module_off_hint')">{{ t('access.matrix.module_off') }}</AppBadge>
                <span class="tabular text-[12px] text-muted">{{ t('access.matrix.count', { selected: countIn(group), total: group.permissions.length }) }}</span>
                <button
                    v-if="!readonly && changeable(group).length > 1"
                    type="button"
                    class="rounded-md px-1.5 py-0.5 text-[12px] font-medium text-brand-text transition hover:bg-brand-soft"
                    :aria-label="allOn(group) ? t('access.matrix.clear_all', { name: group.label }) : t('access.matrix.select_all', { name: group.label })"
                    @click="emit('update:modelValue', toggleGroup(modelValue, group))"
                >
                    {{ allOn(group) ? t('access.matrix.clear_all_short') : t('access.matrix.select_all_short') }}
                </button>
            </div>

            <ul class="divide-y divide-line">
                <li v-for="permission in group.permissions" :key="permission.key">
                    <label
                        class="flex items-start gap-3 px-3.5 py-2.5 transition-colors"
                        :class="[
                            readonly || permission.blocked_by ? 'cursor-default' : 'cursor-pointer hover:bg-subtle/70',
                            inConflict.has(permission.key) && selected.has(permission.key) ? 'bg-warn-soft/60' : '',
                        ]"
                    >
                        <input
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 rounded accent-brand disabled:opacity-60"
                            :checked="selected.has(permission.key)"
                            :disabled="readonly || !!permission.blocked_by"
                            :aria-describedby="permission.blocked_by || pairHint(permission.key) ? `perm-${permission.key}-hint` : undefined"
                            @change="toggle(permission.key)"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13.5px] leading-snug" :class="permission.blocked_by ? 'text-muted' : 'text-fg'">{{ permission.label }}</span>
                            <span :id="`perm-${permission.key}-hint`" class="block space-y-0.5">
                                <span v-if="permission.blocked_by === 'not_held' && !readonly" class="flex items-center gap-1 text-[12px] text-muted">
                                    <Lock class="size-3 shrink-0" aria-hidden="true" />{{ blockedText(permission) }}
                                </span>
                                <span v-if="pairHint(permission.key)" class="flex items-center gap-1 text-[12px] text-muted">
                                    <Split class="size-3 shrink-0" aria-hidden="true" />{{ pairHint(permission.key) }}
                                </span>
                            </span>
                        </span>
                        <code class="hidden shrink-0 pt-0.5 font-mono text-[11px] text-faint sm:block" dir="ltr">{{ permission.key }}</code>
                    </label>
                </li>
            </ul>
        </fieldset>
    </div>
</template>
