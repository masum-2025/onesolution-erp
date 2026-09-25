import { api } from './http';
import { cached } from './cache';
import { i18n } from './i18n';
import { formatMoney } from './format';

/**
 * Plans and sector packages (catalog data, the same for every context).
 * Cached per language because names come translated from the server.
 */
export function loadPlans() {
    return cached(`catalog:plans:${i18n.locale}`, () => api('/api/plans').then((response) => response.data));
}

export function loadSectors() {
    return cached(`catalog:sectors:${i18n.locale}`, () => api('/api/sectors').then((response) => response.data));
}

/**
 * The price to show: the preferred currency if the plan has it, else the
 * first one listed. Amounts stay integer minor units until formatting.
 */
export function planPrice(plan, currency = null, period = 'monthly') {
    const prices = (plan?.prices ?? []).filter((price) => price.period === period);
    const price = prices.find((candidate) => candidate.currency === currency) ?? prices[0];
    return price ? formatMoney({ amount: price.amount_minor, currency: price.currency }) : null;
}

/** 0..1 share of a limit in use; null when unlimited or not measured. */
export function usageShare(limit) {
    if (limit?.max === null || limit?.max === undefined || limit.used === null || limit.used === undefined) return null;
    if (limit.max === 0) return 1;
    return Math.min(1, limit.used / limit.max);
}

/** ok | warn (80% and more) | full */
export function usageTone(limit) {
    const share = usageShare(limit);
    if (share === null) return 'ok';
    if (limit.used >= limit.max) return 'full';
    return share >= 0.8 ? 'warn' : 'ok';
}
