import { t } from './i18n';
import { formatDate, formatDecimal, formatDuration, formatMoney, formatNumber, localeTag } from './format';

/*
 * Display helpers for rule values. The value shapes mirror the server's
 * RuleType (decimals are strings, money is {amount, currency} in minor units).
 */

const ORDERED = ['integer', 'decimal', 'duration', 'money', 'time', 'date'];
const ALLOWED_LIST = ['integer', 'decimal', 'string', 'enum', 'multi_enum'];

export const isOrdered = (type) => ORDERED.includes(type);
export const supportsAllowedList = (type) => ALLOWED_LIST.includes(type);
export const supportsBounds = (type) => isOrdered(type) || supportsAllowedList(type);

function optionLabel(rule, value) {
    return rule.options?.find((option) => option.value === String(value))?.label ?? String(value);
}

function formatMonthDay(value) {
    const match = String(value).match(/^(\d{2})-(\d{2})$/);
    if (!match) return String(value);
    const date = new Date(Date.UTC(2000, Number(match[1]) - 1, Number(match[2])));
    return new Intl.DateTimeFormat(localeTag(), { month: 'long', day: 'numeric', timeZone: 'UTC' }).format(date);
}

export function formatRuleValue(rule, value) {
    if (value === null || value === undefined) return '—';

    switch (rule.type) {
        case 'boolean':
            return value ? t('rules.value.on') : t('rules.value.off');
        case 'integer':
            return formatNumber(value);
        case 'decimal':
            return formatDecimal(value);
        case 'enum':
            return optionLabel(rule, value);
        case 'multi_enum':
            if (!Array.isArray(value) || value.length === 0) return t('rules.value.none');
            return new Intl.ListFormat(localeTag(), { style: 'long', type: 'conjunction' }).format(
                value.map((item) => optionLabel(rule, item)),
            );
        case 'duration':
            return formatDuration(value);
        case 'money':
            return formatMoney(value);
        case 'date':
            return /^\d{2}-\d{2}$/.test(String(value)) ? formatMonthDay(value) : formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
        case 'table':
            return t('rules.value.rows', { count: Array.isArray(value) ? value.length : 0 });
        case 'json':
            return t('rules.value.structured');
        default:
            return String(value);
    }
}

export function formatBounds(rule, bounds) {
    if (!bounds) return null;
    const parts = [];
    const hasMin = bounds.min !== undefined && bounds.min !== null;
    const hasMax = bounds.max !== undefined && bounds.max !== null;

    if (hasMin && hasMax) {
        parts.push(t('rules.bounds.between', { min: formatRuleValue(rule, bounds.min), max: formatRuleValue(rule, bounds.max) }));
    } else if (hasMin) {
        parts.push(t('rules.bounds.at_least', { min: formatRuleValue(rule, bounds.min) }));
    } else if (hasMax) {
        parts.push(t('rules.bounds.at_most', { max: formatRuleValue(rule, bounds.max) }));
    }

    if (Array.isArray(bounds.allowed)) {
        const items = bounds.allowed.map((item) => optionLabel(rule, item));
        parts.push(t('rules.bounds.only', { values: new Intl.ListFormat(localeTag(), { style: 'long', type: 'disjunction' }).format(items) }));
    }

    return parts.join(' · ') || null;
}

/** Deep equality for rule values (used by history diffs). */
export function sameValue(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
}

/** Minimum / maximum from the rule's JSON Schema, for hints and inputs. */
export function schemaRange(rule) {
    return {
        min: rule.schema?.minimum ?? null,
        max: rule.schema?.maximum ?? null,
    };
}
