import { formatDate, formatDecimal } from '@/lib/format';
import { t } from '@/lib/i18n';
import { textIn } from '@/lib/texts';
import { centiText, pointText } from './lib';

/** Marks (hundredths) to read, in the reader's digits ('৮২.৫' in Bangla); typing fields keep plain digits. */
export function marksShown(centi) {
    return centi === null || centi === undefined ? '—' : formatDecimal(centiText(centi));
}

/** A grade point to read ('5.00', '৫.০০'). */
export function pointShown(centi) {
    return centi === null || centi === undefined ? '—' : formatDecimal(pointText(centi));
}

/** A plain date (Y-m-d) in the reader's language, never shifted by time zones. */
export function day(date) {
    return date ? formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) : '—';
}

/** "1 Jun 2026 – 15 Jun 2026", or one day when both are the same or the end is unknown. */
export function dateRange(from, to) {
    if (!from) return '';
    return to && to !== from ? `${day(from)} – ${day(to)}` : day(from);
}

/** A paper's subject: "Mathematics (MATH)". */
export function subjectName(subject) {
    if (!subject) return '—';
    const name = textIn(subject.name);
    return name && subject.code ? `${name} (${subject.code})` : name || subject.code;
}

/** A part's label: its name, else its code ("Creative", "MCQ"). */
export function partName(part) {
    return textIn(part?.name) || part?.code || t('education_exams.paper.marks');
}
