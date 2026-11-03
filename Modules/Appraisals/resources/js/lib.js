import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Appraisal helpers for the screens. The server keeps scores in whole
 * numbers per mille (0-1000) and works every score out; these only show
 * them, add up goal weights while typing, and name what the rules name.
 */

export const STAGES = ['goals', 'self', 'manager', 'acknowledge', 'done'];

/** The steps a review goes through in a cycle (without the self review when the cycle has none). */
export function stagesOf(cycle) {
    return STAGES.filter((stage) => stage !== 'self' || cycle?.self_review !== false);
}

/** 680 -> "68%" (one decimal when needed: 683 -> "68.3%"), in the reader's digits. */
export function scoreText(score) {
    if (score === null || score === undefined) return '—';
    return `${formatNumber(score / 10, { maximumFractionDigits: 1 })}%`;
}

/** Export columns of a list of reviews (what ReviewRows shows): person, cycle, manager, step, due date, score. */
export function reviewColumns() {
    return [
        { key: 'code', label: t('appraisals.export.code'), value: (item) => item.employee?.code ?? '' },
        { key: 'name', label: t('appraisals.export.employee'), value: (item) => item.employee?.name ?? '' },
        { key: 'cycle_name', label: t('appraisals.export.cycle') },
        { key: 'manager_name', label: t('appraisals.export.manager') },
        { key: 'stage', label: t('appraisals.export.stage'), value: (item) => t(`appraisals.stages.${item.stage}`) },
        { key: 'due', label: t('appraisals.export.due'), type: 'date' },
        { key: 'score', label: t('appraisals.export.score'), type: 'number', value: (item) => (item.score === null || item.score === undefined ? null : item.score / 10) },
    ];
}

/** The sum of goal weights typed in (blank or not a number counts 0). */
export function weightsTotal(goals) {
    return goals.reduce((sum, goal) => sum + (Number.parseInt(goal.weight, 10) || 0), 0);
}

/** The name in the reader's language, else English, else any. */
export function nameIn(texts, locale) {
    if (!texts) return '';
    return texts[locale] || texts.en || Object.values(texts).find(Boolean) || '';
}

/** A band's name from the rules' bands by its key. */
export function bandName(bands, key, locale) {
    return nameIn((bands ?? []).find((band) => band.key === key)?.name, locale) || key || '';
}

/** A rating's name on the cycle's scale. */
export function ratingName(scale, value, locale) {
    return nameIn((scale ?? []).find((step) => step.value === value)?.name, locale);
}

/** Badge tone per stage (with its word next to it, never colour alone). */
export function stageTone(stage) {
    return { goals: 'neutral', self: 'brand', manager: 'warn', acknowledge: 'brand', done: 'ok' }[stage] ?? 'neutral';
}

/** Badge tone per cycle status. */
export function cycleTone(status) {
    return { draft: 'neutral', open: 'ok', closed: 'neutral' }[status] ?? 'neutral';
}
