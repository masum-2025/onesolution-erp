<script setup>
import { computed } from 'vue';
import { Building2, Handshake, Network, Store, UsersRound, UserRound } from 'lucide-vue-next';

const props = defineProps({
    type: { type: String, required: true }, // group | company | branch | department | partner | personal
    size: { type: String, default: 'md' }, // sm | md | lg
});

const ICONS = { group: Network, company: Building2, branch: Store, department: UsersRound, partner: Handshake, personal: UserRound };
const TINTS = {
    group: 'bg-[color-mix(in_oklab,#7c3aed_12%,var(--c-surface))] text-[color-mix(in_oklab,#7c3aed_80%,var(--c-fg))]',
    company: 'bg-brand-soft text-brand-text',
    branch: 'bg-[color-mix(in_oklab,#0891b2_12%,var(--c-surface))] text-[color-mix(in_oklab,#0891b2_80%,var(--c-fg))]',
    department: 'bg-[color-mix(in_oklab,#d97706_12%,var(--c-surface))] text-[color-mix(in_oklab,#d97706_85%,var(--c-fg))]',
    partner: 'bg-[color-mix(in_oklab,#059669_12%,var(--c-surface))] text-[color-mix(in_oklab,#059669_80%,var(--c-fg))]',
    personal: 'bg-subtle text-fg-2',
};
const SIZES = { sm: 'size-6 rounded-md [&>svg]:size-3.5', md: 'size-8 rounded-lg [&>svg]:size-4', lg: 'size-10 rounded-xl [&>svg]:size-5' };

const icon = computed(() => ICONS[props.type] ?? Building2);
</script>

<template>
    <span class="grid shrink-0 place-items-center" :class="[TINTS[type] ?? TINTS.personal, SIZES[size]]" aria-hidden="true">
        <component :is="icon" />
    </span>
</template>
