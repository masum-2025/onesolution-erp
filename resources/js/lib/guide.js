import { has, t } from '@/lib/i18n';
import { readPref, writePref } from '@/lib/storage';

/*
 * The "How to" guide of a page, read from its translation keys (each a plain
 * string, so partners and companies can reword it in the language editor):
 *
 *   <module>.guide.<page>.title
 *   <module>.guide.<page>.steps.1 … steps.9
 *   <module>.guide.<page>.tips.1 …       (optional)
 *   <module>.guide.<page>.mistakes.1 …   (optional)
 *   <module>.guide.<page>.settings       (optional: where this page's settings live)
 *
 * A page without a title and a first step has no guide (no button, never raw keys).
 */

export const MAX_ITEMS = 9;

function list(key) {
    const items = [];
    for (let i = 1; i <= MAX_ITEMS && has(`${key}.${i}`); i++) items.push(t(`${key}.${i}`));
    return items;
}

export function hasGuide(key) {
    return !!key && has(`${key}.title`) && has(`${key}.steps.1`);
}

export function guideContent(key) {
    if (!hasGuide(key)) return null;
    return {
        title: t(`${key}.title`),
        steps: list(`${key}.steps`),
        tips: list(`${key}.tips`),
        mistakes: list(`${key}.mistakes`),
        settings: has(`${key}.settings`) ? t(`${key}.settings`) : '',
    };
}

/** Only https links (a partner's video), never javascript: or plain http. */
export function safeVideo(url) {
    if (typeof url !== 'string') return null;
    try {
        return new URL(url).protocol === 'https:' ? url : null;
    } catch {
        return null;
    }
}

// Browser-only conveniences: the button pulses until this page's guide was opened once,
// and "Don't show again" stops the pulse on every page.
export function guideSeen(key) {
    return readPref('guide.quiet') === '1' || readPref(`guide.seen.${key}`) === '1';
}

export function markGuideSeen(key) {
    writePref(`guide.seen.${key}`, '1');
}

export function quietGuides() {
    writePref('guide.quiet', '1');
}
