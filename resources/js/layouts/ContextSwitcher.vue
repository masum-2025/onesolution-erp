<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { ChevronsUpDown, LayoutGrid } from 'lucide-vue-next';
import AppMenu from '@/components/AppMenu.vue';
import OrgTypeIcon from '@/components/OrgTypeIcon.vue';
import { enterContext, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

// Icon only, for the collapsed sidebar.
defineProps({ compact: Boolean });

const router = useRouter();
const context = computed(() => session.me?.context);

const subtitle = computed(() => {
    if (!context.value) return '';
    if (context.value.type === 'partner') return t('core.context.partner_console');
    return t(`core.org_types.${context.value.organization_type}`);
});

const items = computed(() => {
    const current = context.value;
    const orgs = (session.me?.contexts?.organizations ?? [])
        .filter((item) => !(current?.type === 'organization' && current.id === item.organization_id))
        .slice(0, 6)
        .map((item) => ({ label: item.name, hint: t(`core.org_types.${item.type}`), onSelect: () => switchTo({ organization_id: item.organization_id }, item.name, '/') }));
    const partners = (session.me?.contexts?.partners ?? [])
        .filter((item) => !(current?.type === 'partner' && current.id === item.partner_id))
        .map((item) => ({ label: item.name, hint: t('core.context.partner'), onSelect: () => switchTo({ partner_id: item.partner_id }, item.name, '/partner/organizations') }));

    const list = [];
    if (orgs.length || partners.length) list.push({ heading: t('core.context.switch_to') }, ...orgs, ...partners, { divider: true });
    list.push({ label: t('core.context.all'), icon: LayoutGrid, onSelect: () => router.push({ name: 'choose' }) });
    return list;
});

async function switchTo(target, name, path) {
    try {
        await enterContext(target);
        await router.push(path);
        toast.success(t('core.context.switched', { name }));
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <AppMenu :items="items" align="start" width="w-[min(17rem,calc(100vw-2rem))]" :label="t('core.context.switch')">
        <template #trigger="{ toggle, attrs }">
            <button
                type="button"
                v-bind="attrs"
                class="flex w-full items-center gap-2.5 rounded-xl border border-side-line bg-side-hover text-start transition-colors hover:border-side-ring hover:bg-side-active"
                :class="compact ? 'justify-center p-1' : 'p-2'"
                :title="compact ? context?.name : undefined"
                @click="toggle(false)"
                @keydown.down.prevent="toggle(true)"
            >
                <OrgTypeIcon :type="context?.type === 'partner' ? 'partner' : context?.organization_type ?? 'company'" size="md" />
                <template v-if="!compact">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] leading-tight font-semibold text-side-fg">{{ context?.name }}</span>
                        <span class="mt-0.5 block truncate text-[12px] leading-tight text-side-muted">{{ subtitle }}</span>
                    </span>
                    <ChevronsUpDown class="size-4 shrink-0 text-side-muted" aria-hidden="true" />
                </template>
            </button>
        </template>
    </AppMenu>
</template>
