import { computed, reactive } from 'vue';
import { CircleAlert, FileText, Info, KeyRound, TriangleAlert } from 'lucide-vue-next';
import { api } from './http';
import { currentOrganization, isPortalMember, session } from './session';
import { t } from './i18n';

/**
 * Work waiting for the person here, shared by the header bell and the
 * overview's "Needs your action": /api/attention (platform and module items)
 * plus what /api/me already says (terms to accept, second step to set up).
 * Every item has an icon by its tone, never only a color.
 */
export const TONE_ICONS = { info: Info, warn: TriangleAlert, bad: CircleAlert };

const state = reactive({ remote: [], loaded: false });

const local = computed(() => {
    const me = session.me;
    const items = [];
    if (me?.context?.legal_pending) {
        items.push({ key: 'legal', label: t('core.attention.legal'), count: me.context.legal_pending, path: '/provider', tone: 'warn', icon: FileText });
    }
    if (me?.user?.two_factor?.setup_due_at && !me.user.two_factor.enabled) {
        items.push({ key: 'two_factor', label: t('core.attention.two_factor'), count: 1, path: '/account#security', tone: 'warn', icon: KeyRound });
    }
    return items;
});

export const attentionItems = computed(() => [...local.value, ...state.remote].map((item) => ({ ...item, icon: item.icon ?? TONE_ICONS[item.tone] ?? Info })));

export const attentionTotal = computed(() => attentionItems.value.reduce((sum, item) => sum + item.count, 0));

export const attentionLoaded = computed(() => state.loaded);

export async function refreshAttention() {
    if (!currentOrganization() || isPortalMember() || session.me?.context?.support) {
        state.remote = [];
        state.loaded = true;
        return;
    }
    try {
        state.remote = (await api('/api/attention')).data;
    } catch {
        // A convenience: on failure it keeps showing what it already knows.
    } finally {
        state.loaded = true;
    }
}
