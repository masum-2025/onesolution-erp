import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { billKind } from './lib';

/** What a bill is for, in words: "March 2026", "Session fees", "Admission fees"… */
export function billTitle(bill) {
    const { kind, month } = billKind(bill?.billing_key);
    if (kind === 'monthly' && month) {
        return t('education_fees.bill_kinds.monthly', { month: formatDate(`${month}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' }) });
    }
    return t(`education_fees.bill_kinds.${kind}`);
}

/** A plain date (Y-m-d) in the reader's language, never shifted by time zones. */
export function day(date) {
    return date ? formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) : '—';
}

/** What a billing run bills: "March 2026", "Session fees", "Other heads". */
export function runTitle(run) {
    if (run?.kind === 'monthly' && run.period) {
        return formatDate(`${run.period}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' });
    }
    return t(`education_fees.run_kinds.${run?.kind ?? 'other'}`);
}
