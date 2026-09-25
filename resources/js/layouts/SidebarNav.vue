<script setup>
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { Blocks, Boxes, Handshake, Home, Network, ShieldCheck, SlidersHorizontal } from 'lucide-vue-next';
import ContextSwitcher from './ContextSwitcher.vue';
import UserMenu from './UserMenu.vue';
import { can, session } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps({
    menu: { type: Array, default: () => [] },
    pendingApprovals: { type: Number, default: 0 },
});

defineEmits(['navigate']);

const route = useRoute();
const isPartner = computed(() => session.me?.context?.type === 'partner');

const workspace = computed(() =>
    isPartner.value
        ? [
              { to: '/partner/organizations', label: t('core.nav.clients'), icon: Handshake },
              { to: '/partner/rules', label: t('core.nav.partner_rules'), icon: SlidersHorizontal },
          ]
        : [
              { to: '/', label: t('core.nav.overview'), icon: Home, exact: true },
              { to: '/organizations', label: t('core.nav.organizations'), icon: Network },
              { to: '/modules', label: t('core.nav.modules'), icon: Blocks },
              { to: '/rules', label: t('core.nav.rules'), icon: SlidersHorizontal },
              ...(can('rules.manage') ? [{ to: '/approvals', label: t('core.nav.approvals'), icon: ShieldCheck, count: props.pendingApprovals }] : []),
          ],
);

function isActive(item) {
    return item.exact ? route.path === item.to : route.path === item.to || route.path.startsWith(`${item.to}/`);
}
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="p-3">
            <ContextSwitcher />
        </div>

        <nav class="min-h-0 flex-1 overflow-y-auto px-3 pb-3" :aria-label="t('core.nav.label')">
            <p class="px-2.5 pt-2 pb-1.5 text-[11px] font-semibold tracking-wider text-faint uppercase">{{ t('core.nav.workspace') }}</p>
            <ul class="space-y-0.5">
                <li v-for="item in workspace" :key="item.to">
                    <RouterLink
                        :to="item.to"
                        class="group flex h-9 items-center gap-2.5 rounded-lg px-2.5 text-[13.5px] font-medium transition-colors"
                        :class="isActive(item) ? 'bg-surface text-fg shadow-[0_1px_2px_rgb(0_0_0/0.06),0_0_0_1px_var(--c-line)]' : 'text-fg-2 hover:bg-subtle hover:text-fg'"
                        :aria-current="isActive(item) ? 'page' : undefined"
                        @click="$emit('navigate')"
                    >
                        <component
                            :is="item.icon"
                            class="size-[17px] shrink-0 transition-colors"
                            :class="isActive(item) ? 'text-brand-text' : 'text-muted group-hover:text-fg-2'"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                        <span v-if="item.count" class="tabular rounded-full bg-brand px-1.5 text-[11px] leading-[18px] font-semibold text-brand-fg">
                            {{ formatNumber(item.count) }}
                        </span>
                    </RouterLink>
                </li>
            </ul>

            <template v-if="!isPartner && menu.length">
                <p class="px-2.5 pt-5 pb-1.5 text-[11px] font-semibold tracking-wider text-faint uppercase">{{ t('core.nav.apps') }}</p>
                <ul class="space-y-0.5">
                    <li v-for="item in menu" :key="`${item.module}-${item.key}`">
                        <RouterLink
                            :to="`/apps/${item.module}/${item.key}`"
                            class="group flex h-9 items-center gap-2.5 rounded-lg px-2.5 text-[13.5px] font-medium transition-colors"
                            :class="route.path === `/apps/${item.module}/${item.key}` ? 'bg-surface text-fg shadow-[0_1px_2px_rgb(0_0_0/0.06),0_0_0_1px_var(--c-line)]' : 'text-fg-2 hover:bg-subtle hover:text-fg'"
                            @click="$emit('navigate')"
                        >
                            <Boxes class="size-[17px] shrink-0 text-muted group-hover:text-fg-2" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </template>
        </nav>

        <div class="border-t border-line p-3">
            <UserMenu />
        </div>
    </div>
</template>
