import { computed, ref } from 'vue';
import { currentOrganization } from '@/lib/session';
import { formatMoney } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { feesApi } from './api';

/**
 * What every fee screen needs once (heads, rules, currency, campuses, what
 * the reader may do), fetched once per organization and shared.
 */
const cache = new Map();

export function useFeesSetup() {
    const id = currentOrganization().id;
    if (!cache.has(id)) {
        const state = { data: ref(null), error: ref(null), loading: ref(false) };
        state.reload = async () => {
            state.loading.value = true;
            state.error.value = null;
            try {
                state.data.value = (await feesApi(id).setup()).data;
            } catch (error) {
                state.error.value = error;
            } finally {
                state.loading.value = false;
            }
        };
        state.reload();
        cache.set(id, state);
    }
    const state = cache.get(id);
    const data = state.data;
    const currency = computed(() => data.value?.currency ?? null);

    return {
        ...state,
        currency,
        can: (ability) => data.value?.can?.[ability] === true,
        rules: computed(() => data.value?.rules ?? {}),
        today: computed(() => data.value?.today ?? ''),
        head: (headId) => data.value?.heads?.find((head) => head.id === headId) ?? null,
        headName: (headId) => {
            const head = data.value?.heads?.find((item) => item.id === headId);
            return head ? textIn(head.name) || head.code : '—';
        },
        sessionName: (sessionId) => textIn(data.value?.sessions?.find((session) => session.id === sessionId)?.name),
        /** Minor units as money in the institution's currency. */
        money: (minor) => formatMoney({ amount: minor ?? 0, currency: currency.value }),
    };
}
