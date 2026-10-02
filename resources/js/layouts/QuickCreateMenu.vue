<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { Plus } from 'lucide-vue-next';
import AppMenu from '@/components/AppMenu.vue';
import { moduleIcon } from '@/lib/icons';
import { menuLink } from '@/lib/menu';
import { t } from '@/lib/i18n';

/**
 * The header "New" button: things the person may create here, declared by
 * modules (quick_actions) and already filtered by the server for permission,
 * enabled modules and read-only mode. Hidden when there is nothing to create.
 */
const props = defineProps({
    actions: { type: Array, default: () => [] },
});

const router = useRouter();

const items = computed(() =>
    props.actions.map((action) => ({
        label: action.label,
        icon: moduleIcon(action.icon),
        onSelect: () => router.push(menuLink(action, router)),
    })),
);
</script>

<template>
    <AppMenu v-if="actions.length" :items="items" width="w-[min(16rem,calc(100vw-2rem))]" :label="t('core.quick_create.label')">
        <template #trigger="{ toggle, attrs }">
            <button
                type="button"
                v-bind="attrs"
                class="new-button flex h-10 items-center gap-2 rounded-xl px-3 text-[14px] font-semibold text-brand-fg transition sm:px-4"
                @click="toggle(false)"
                @keydown.down.prevent="toggle(true)"
            >
                <Plus class="size-[18px]" aria-hidden="true" />
                <span class="hidden sm:inline">{{ t('core.quick_create.button') }}</span>
            </button>
        </template>
        <template #header>
            <p class="px-2.5 pt-2 pb-1 text-[11px] font-semibold tracking-wide text-muted uppercase">{{ t('core.quick_create.heading') }}</p>
        </template>
    </AppMenu>
</template>
