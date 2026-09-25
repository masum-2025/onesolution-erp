<script setup>
import { computed } from 'vue';
import { CornerDownRight, Dot, PenLine } from 'lucide-vue-next';
import AppBadge from './AppBadge.vue';
import { t } from '@/lib/i18n';

/**
 * Where a value comes from: set here, inherited from a named level, or the default.
 * `kind`: self | inherited | default. `name`: the level that set it.
 */
const props = defineProps({
    kind: { type: String, required: true },
    name: { type: String, default: null },
    level: { type: String, default: null },
});

const text = computed(() => {
    if (props.kind === 'self') return t('core.source.self');
    if (props.kind === 'default') return t('core.source.default');
    // The platform level has no business name; show it in the user's language.
    if (props.level === 'platform') return t('core.source.inherited_from', { name: t('core.levels.platform') });
    if (props.name) return t('core.source.inherited_from', { name: props.name });
    if (props.level) return t('core.source.inherited_from', { name: t(`core.levels.${props.level}`) });
    return t('core.source.inherited');
});
</script>

<template>
    <AppBadge v-if="kind === 'self'" tone="brand" :icon="PenLine">{{ text }}</AppBadge>
    <AppBadge v-else-if="kind === 'default'" tone="outline" :icon="Dot">{{ text }}</AppBadge>
    <AppBadge v-else tone="neutral" :icon="CornerDownRight" :title="text">{{ text }}</AppBadge>
</template>
