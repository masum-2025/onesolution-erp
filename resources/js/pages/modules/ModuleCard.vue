<script setup>
import { computed } from 'vue';
import { BadgeCheck, Briefcase, Leaf, Link2, Lock, MoreHorizontal, Plug, Scale, Sparkles, Undo2, Trash2, ShieldCheck, ShieldOff } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppMenu from '@/components/AppMenu.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import { has, t } from '@/lib/i18n';

const props = defineProps({
    module: { type: Object, required: true },
    names: { type: Object, required: true }, // key -> module name
    manageable: Boolean,
    busy: Boolean,
});

const emit = defineEmits(['toggle', 'inherit', 'consent', 'withdraw-consent', 'purge']);

const ICONS = { business: Briefcase, governance: Scale, ai: Sparkles, platform: Plug, sustainability: Leaf };
const TINTS = {
    business: 'bg-brand-soft text-brand-text',
    governance: 'bg-[color-mix(in_oklab,#7c3aed_12%,var(--c-surface))] text-[color-mix(in_oklab,#7c3aed_80%,var(--c-fg))]',
    ai: 'bg-[color-mix(in_oklab,#db2777_12%,var(--c-surface))] text-[color-mix(in_oklab,#db2777_80%,var(--c-fg))]',
    platform: 'bg-[color-mix(in_oklab,#0891b2_12%,var(--c-surface))] text-[color-mix(in_oklab,#0891b2_80%,var(--c-fg))]',
    sustainability: 'bg-[color-mix(in_oklab,#059669_12%,var(--c-surface))] text-[color-mix(in_oklab,#059669_80%,var(--c-fg))]',
};

const category = computed(() =>
    has(`modules.categories.${props.module.category}`) ? t(`modules.categories.${props.module.category}`) : props.module.category,
);

const locked = computed(() => props.module.locked_by !== null);
const canToggle = computed(() => props.manageable && !props.module.is_core && !locked.value && props.module.available);

const lockHint = computed(() => (locked.value ? t('modules.locked_by', { name: props.module.locked_by?.name ?? '' }) : null));

const menu = computed(() =>
    [
        props.module.state !== 'inherit' && !locked.value && { label: t('modules.actions.inherit'), icon: Undo2, onSelect: () => emit('inherit') },
        props.module.requires_consent && props.module.reason === 'consent_missing' && { label: t('modules.actions.consent'), icon: ShieldCheck, onSelect: () => emit('consent') },
        props.module.requires_consent && props.module.reason !== 'consent_missing' && { label: t('modules.actions.withdraw_consent'), icon: ShieldOff, onSelect: () => emit('withdraw-consent') },
        !props.module.enabled && !props.module.is_core && { divider: true },
        !props.module.enabled && !props.module.is_core && { label: t('modules.actions.purge'), icon: Trash2, danger: true, onSelect: () => emit('purge') },
    ].filter(Boolean),
);
</script>

<template>
    <article class="card flex flex-col p-4 transition-[border-color,box-shadow] hover:border-line-strong" :class="!module.available ? 'bg-subtle/40' : ''">
        <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="TINTS[module.category] ?? 'bg-subtle text-fg-2'" aria-hidden="true">
                <component :is="ICONS[module.category] ?? Briefcase" class="size-[18px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="flex items-center gap-1.5 truncate text-[14px] font-semibold text-fg">
                    {{ module.name }}
                    <BadgeCheck v-if="module.is_core" class="size-4 shrink-0 text-brand-text" :aria-label="t('modules.core')" />
                </h3>
                <p class="text-[12.5px] text-muted">{{ category }}</p>
            </div>
            <AppSwitch
                v-if="canToggle"
                :model-value="module.enabled"
                :label="module.enabled ? t('modules.actions.turn_off', { name: module.name }) : t('modules.actions.turn_on', { name: module.name })"
                :busy="busy"
                @update:model-value="emit('toggle')"
            />
            <AppBadge v-else :tone="module.enabled ? 'ok' : 'neutral'" dot>{{ module.enabled ? t('modules.on') : t('modules.off') }}</AppBadge>
        </div>

        <p class="mt-3 line-clamp-2 text-[13px] leading-relaxed text-fg-2">{{ module.description }}</p>

        <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-4">
            <AppBadge v-if="!module.available || module.reason === 'dependency_disabled'" tone="warn">{{ module.reason_message }}</AppBadge>
            <SourceBadge v-else :kind="module.source" :name="module.source_organization?.name" />
            <AppBadge v-if="locked" tone="neutral" :icon="Lock" :title="lockHint">{{ lockHint }}</AppBadge>
            <AppBadge v-else-if="module.locked_here" tone="brand" :icon="Lock">{{ t('modules.locked_here') }}</AppBadge>
            <AppBadge v-for="dependency in module.requires" :key="dependency" tone="outline" :icon="Link2">
                {{ t('modules.needs', { name: names[dependency] ?? dependency }) }}
            </AppBadge>
            <div class="flex-1" />
            <AppMenu v-if="manageable && menu.length" :items="menu" :label="t('modules.actions.more', { name: module.name })">
                <template #trigger="{ toggle, attrs }">
                    <AppButton variant="ghost" size="icon-sm" :icon="MoreHorizontal" v-bind="attrs" @click="toggle(false)" />
                </template>
            </AppMenu>
        </div>
    </article>
</template>
