import { t } from '@/lib/i18n';
import { bankCsv } from './lib';

const COLUMNS = ['code', 'name', 'method', 'provider', 'account_name', 'account_number', 'branch', 'amount'];

/**
 * Saves a bank file the server gave (full account numbers) as CSV, never
 * keeping it; returns how many people have no account on file.
 */
export function saveBankFile(file, name) {
    const csv = bankCsv(file, COLUMNS.map((key) => t(`payroll.bank.columns.${key}`)));
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    link.download = name;
    link.click();
    URL.revokeObjectURL(link.href);
    return file.rows.filter((row) => !row.method).length;
}
