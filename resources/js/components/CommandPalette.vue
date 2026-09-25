<script setup>
import { computed, nextTick, ref, toRef, useId, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
    ArrowRightLeft,
    Blocks,
    CornerDownLeft,
    Handshake,
    Home,
    KeyRound,
    Languages,
    Moon,
    Network,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    Sun,
} from 'lucide-vue-next';
import OrgTypeIcon from './OrgTypeIcon.vue';
import { useModal } from '@/lib/useModal';
import { api } from '@/lib/http';
import { i18n, setLocale, t } from '@/lib/i18n';
import { setTheme, theme } from '@/lib/theme';
import { can, currentOrganization, enterContext, session } from '@/lib/session';
import { visibleOrganizations } from '@/lib/organizations';
import { toast } from '@/lib/toast';

/**
 * Ctrl/Cmd + K: jump to any page, organization or rule, switch context,
 * change theme or language. Results come only from data the user can see.
 */
const props = defineProps({ open: Boolean });
const emit = defineEmits(['close']);

const router = useRouter();
const panel = ref(null);
const input = ref(null);
const query = ref('');
const active = ref(0);
const organizations = ref([]);
const rules = ref([]);
const listId = useId();

const { onKeydown: modalKeys } = useModal(toRef(props, 'open'), panel, () => emit('close'));

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        query.value = '';
        active.value = 0;
        await nextTick();
        input.value?.focus();
        loadData();
    },
);

async function loadData() {
    const org = currentOrganization();
    if (!org) return;
    try {
        organizations.value = await visibleOrganizations();
    } catch {
        organizations.value = [];
    }
    try {
        const { data } = await api(`/api/organizations/${org.id}/rules`);
        rules.value = data.flatMap((group) => group.categories.flatMap((category) => category.rules.map((rule) => ({ ...rule, moduleName: group.name }))));
    } catch {
        rules.value = [];
    }
}

const pages = computed(() => {
    const context = session.me?.context?.type;
    if (context === 'partner') {
        return [
            { id: 'p-clients', label: t('core.nav.clients'), icon: Handshake, run: () => router.push('/partner/organizations') },
            { id: 'p-rules', label: t('core.nav.partner_rules'), icon: SlidersHorizontal, run: () => router.push('/partner/rules') },
        ];
    }
    return [
        { id: 'home', label: t('core.nav.overview'), icon: Home, run: () => router.push('/') },
        { id: 'orgs', label: t('core.nav.organizations'), icon: Network, run: () => router.push('/organizations') },
        (can('roles.manage') || can('members.manage')) && { id: 'roles', label: t('core.nav.roles'), icon: KeyRound, run: () => router.push('/roles') },
        { id: 'modules', label: t('core.nav.modules'), icon: Blocks, run: () => router.push('/modules') },
        { id: 'rules', label: t('core.nav.rules'), icon: SlidersHorizontal, run: () => router.push('/rules') },
        can('rules.approve') && { id: 'approvals', label: t('core.nav.approvals'), icon: ShieldCheck, run: () => router.push('/approvals') },
    ].filter(Boolean);
});

const actions = computed(() => [
    {
        id: 'theme',
        label: theme.dark ? t('core.palette.light_mode') : t('core.palette.dark_mode'),
        icon: theme.dark ? Sun : Moon,
        run: () => setTheme(theme.dark ? 'light' : 'dark'),
    },
    ...i18n.locales
        .filter((locale) => locale !== i18n.locale)
        .map((locale) => ({ id: `lang-${locale}`, label: t(`core.languages.${locale}`), icon: Languages, run: () => setLocale(locale) })),
]);

const contexts = computed(() => {
    const current = session.me?.context;
    const orgs = (session.me?.contexts?.organizations ?? [])
        .filter((item) => !(current?.type === 'organization' && current.id === item.organization_id))
        .map((item) => ({ id: `ctx-${item.organization_id}`, label: item.name, org: item.type, target: { organization_id: item.organization_id } }));
    const partners = (session.me?.contexts?.partners ?? [])
        .filter((item) => !(current?.type === 'partner' && current.id === item.partner_id))
        .map((item) => ({ id: `ctx-p-${item.partner_id}`, label: item.name, org: 'partner', target: { partner_id: item.partner_id } }));
    return [...orgs, ...partners].map((item) => ({ ...item, run: () => switchTo(item) }));
});

async function switchTo(item) {
    try {
        await enterContext(item.target);
        router.push(item.target.partner_id ? '/partner/organizations' : '/');
        toast.success(t('core.context.switched', { name: item.label }));
    } catch (error) {
        toast.error(error.message);
    }
}

function matches(text) {
    const needle = query.value.trim().toLocaleLowerCase();
    return !needle || String(text).toLocaleLowerCase().includes(needle);
}

const sections = computed(() => {
    const searching = query.value.trim().length > 0;
    const list = [
        { key: 'pages', title: t('core.palette.pages'), items: pages.value.filter((item) => matches(item.label)) },
        {
            key: 'orgs',
            title: t('core.palette.organizations'),
            items: searching
                ? organizations.value
                      .filter((org) => matches(org.display_name))
                      .slice(0, 6)
                      .map((org) => ({ id: `org-${org.id}`, label: org.display_name, org: org.type, run: () => router.push(`/organizations/${org.id}`) }))
                : [],
        },
        {
            key: 'rules',
            title: t('core.palette.rules'),
            items: searching
                ? rules.value
                      .filter((rule) => matches(rule.label) || matches(rule.key))
                      .slice(0, 6)
                      .map((rule) => ({
                          id: `rule-${rule.key}`,
                          label: rule.label,
                          hint: rule.moduleName,
                          icon: SlidersHorizontal,
                          run: () => router.push({ path: '/rules', query: { rule: rule.key } }),
                      }))
                : [],
        },
        { key: 'contexts', title: t('core.palette.switch_to'), items: contexts.value.filter((item) => matches(item.label)).slice(0, 5) },
        { key: 'actions', title: t('core.palette.actions'), items: actions.value.filter((item) => matches(item.label)) },
    ];
    return list.filter((section) => section.items.length > 0);
});

const flat = computed(() => sections.value.flatMap((section) => section.items));

watch(query, () => (active.value = 0));

function select(item) {
    emit('close');
    item.run();
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = (active.value + 1) % Math.max(flat.value.length, 1);
        scrollActive();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = (active.value - 1 + flat.value.length) % Math.max(flat.value.length, 1);
        scrollActive();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (flat.value[active.value]) select(flat.value[active.value]);
    } else {
        modalKeys(event);
    }
}

async function scrollActive() {
    await nextTick();
    panel.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
}

const indexOf = (item) => flat.value.indexOf(item);
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150" enter-from-class="opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center px-3 pt-[12vh]" @keydown="onKeydown">
                <div class="absolute inset-0 bg-[rgb(10_10_14/0.42)] backdrop-blur-[2px]" aria-hidden="true" @click="emit('close')" />
                <div
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="t('core.palette.title')"
                    class="relative w-full max-w-xl animate-pop overflow-hidden rounded-2xl border border-line bg-raised shadow-dialog"
                >
                    <div class="flex items-center gap-3 border-b border-line px-4">
                        <Search class="size-[18px] shrink-0 text-faint" aria-hidden="true" />
                        <input
                            ref="input"
                            v-model="query"
                            type="text"
                            role="combobox"
                            aria-expanded="true"
                            :aria-controls="listId"
                            :aria-activedescendant="flat[active] ? `${listId}-${flat[active].id}` : undefined"
                            class="h-14 w-full bg-transparent text-[15px] text-fg outline-none placeholder:text-faint"
                            :placeholder="t('core.palette.placeholder')"
                        />
                        <span class="kbd hidden sm:inline-flex">Esc</span>
                    </div>
                    <div :id="listId" role="listbox" class="max-h-[min(60vh,440px)] overflow-y-auto p-2">
                        <div v-for="section in sections" :key="section.key" class="mb-1 last:mb-0">
                            <div class="px-2.5 pt-2 pb-1.5 text-[11px] font-semibold tracking-wider text-faint uppercase">{{ section.title }}</div>
                            <div
                                v-for="item in section.items"
                                :id="`${listId}-${item.id}`"
                                :key="item.id"
                                role="option"
                                :aria-selected="indexOf(item) === active"
                                :data-active="indexOf(item) === active"
                                class="flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 text-[13.5px]"
                                :class="indexOf(item) === active ? 'bg-brand-soft text-fg' : 'text-fg-2'"
                                @mouseenter="active = indexOf(item)"
                                @click="select(item)"
                            >
                                <OrgTypeIcon v-if="item.org" :type="item.org" size="sm" />
                                <span v-else class="grid size-6 shrink-0 place-items-center rounded-md bg-subtle text-muted" aria-hidden="true">
                                    <component :is="item.icon" class="size-3.5" />
                                </span>
                                <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                                <span v-if="item.hint" class="truncate text-[12px] text-faint">{{ item.hint }}</span>
                                <ArrowRightLeft v-if="section.key === 'contexts'" class="size-3.5 shrink-0 text-faint" aria-hidden="true" />
                                <CornerDownLeft v-else-if="indexOf(item) === active" class="size-3.5 shrink-0 text-faint" aria-hidden="true" />
                            </div>
                        </div>
                        <p v-if="sections.length === 0" class="px-3 py-10 text-center text-[13.5px] text-muted">{{ t('core.palette.no_results') }}</p>
                    </div>
                    <div class="hidden items-center gap-4 border-t border-line bg-surface/60 px-4 py-2.5 text-[12px] text-faint sm:flex">
                        <span class="flex items-center gap-1.5"><span class="kbd">↑</span><span class="kbd">↓</span> {{ t('core.palette.navigate') }}</span>
                        <span class="flex items-center gap-1.5"><span class="kbd">↵</span> {{ t('core.palette.open') }}</span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
