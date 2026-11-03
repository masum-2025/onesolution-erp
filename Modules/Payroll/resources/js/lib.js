import { formatNumber } from '@/lib/format';
import { amountToMinor, cleanNumber, minorToText } from '@/lib/numbers';

// Shared helpers, passed on so this module's screens keep importing them from here.
export { amountToMinor, cleanNumber, minorToText };

/**
 * Payroll helpers for the screens: amounts typed as text become integer
 * minor units (string maths, Bangla digits understood), percentages become
 * basis points, and an approved month becomes the bank's CSV file. The
 * server works every slip out; nothing here calculates pay.
 */

/** "12.5" (%) -> 1250 basis points; null when not a percentage from 0 to 1000 with at most 2 decimals. */
export function percentToBp(text) {
    const match = /^(\d{1,4})(?:\.(\d{1,2}))?$/.exec(cleanNumber(text));
    if (!match) return null;
    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/** 1250 -> "12.5". */
export function bpToPercent(bp) {
    const value = Math.trunc(Number(bp) || 0);
    const fraction = String(value % 100).padStart(2, '0').replace(/0+$/, '');
    return fraction ? `${Math.floor(value / 100)}.${fraction}` : String(Math.floor(value / 100));
}

/** Basis points as a percentage in the reader's digits (1250 -> "12.5", "১২.৫" in Bangla). */
export function percentText(bp) {
    return bpToPercent(bp).replace(/\d/g, (digit) => formatNumber(Number(digit)));
}

/** Tone of a run's status badge (always next to its label). */
export function runTone(status) {
    return { draft: 'neutral', pending_approval: 'warn', approved: 'brand', paid: 'ok' }[status] ?? 'neutral';
}

/** Tone of an increment round's status badge (always next to its label). */
export function incrementTone(status) {
    return { draft: 'neutral', pending_approval: 'warn', approved: 'brand', applied: 'ok', cancelled: 'neutral' }[status] ?? 'neutral';
}

/**
 * A basic raised by a rate (basis points), rounded half up as the server
 * does: a preview while typing a rate by hand (the server works it out).
 */
export function raiseBy(basicMinor, rateBp) {
    const basic = Math.trunc(Number(basicMinor) || 0);
    const rate = Math.trunc(Number(rateBp) || 0);
    return basic + Math.floor((basic * rate + 5000) / 10000);
}

/** Tone of a loan's status badge. */
export function loanTone(status) {
    return { pending_approval: 'warn', active: 'brand', closed: 'ok', rejected: 'bad', cancelled: 'neutral' }[status] ?? 'neutral';
}

/** Tone of a month in a loan's schedule. */
export function installmentTone(status) {
    return { recovered: 'ok', planned: 'brand', skipped: 'warn', due: 'neutral' }[status] ?? 'neutral';
}

/** The first month a new loan is recovered from: the month after it is paid out ("2026-10-05" -> "2026-11"). */
export function firstRecoveryMonth(paidOutOn) {
    const [year, month] = String(paidOutOn).split('-').map(Number);
    if (!year || !month) return '';
    const next = new Date(Date.UTC(year, month, 1));
    return `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** Each instalment for a principal over a number of months, rounded up like the server (integer maths). */
export function installmentOf(principalMinor, installments) {
    const count = Math.max(1, Math.trunc(Number(installments) || 0));
    const principal = Math.trunc(Number(principalMinor) || 0);
    return Math.floor((principal + count - 1) / count);
}

/** Bonus lines: paid first by name, then those getting nothing. */
export function sortBonusLines(lines) {
    return [...(lines ?? [])].sort((a, b) => Number(Boolean(a.not_paid_reason)) - Number(Boolean(b.not_paid_reason)) || a.employee_name.localeCompare(b.employee_name));
}

/**
 * How a settlement line was worked out, as a translation key and its
 * values (null for lines added by hand): the screen writes the sentence.
 */
export function settlementBasis(line) {
    const basis = line?.basis;
    if (!basis?.key) return null;
    return { key: `payroll.settlement.basis.${basis.key}`, params: { ...(basis.params ?? {}), percent: basis.params?.percent === undefined ? undefined : bpToPercent(basis.params.percent) } };
}

/** Settlement lines in the order they read: what is paid, what comes off, then what is only shown. */
export function settlementSections(lines) {
    const all = lines ?? [];
    return {
        earnings: all.filter((line) => line.kind === 'earning'),
        deductions: all.filter((line) => line.kind === 'deduction'),
        info: all.filter((line) => line.kind === 'info'),
    };
}

/** Slips with a problem first, then by name. */
export function sortSlips(slips) {
    return [...(slips ?? [])].sort((a, b) => Number(Boolean(b.problem)) - Number(Boolean(a.problem)) || a.employee_name.localeCompare(b.employee_name));
}

/** The next month after the last run ("2026-10" -> "2026-11"), or this month when there is none. */
export function nextPeriod(runs, today = new Date()) {
    const latest = (runs ?? []).map((run) => run.period).sort().pop();
    if (!latest) return today.toISOString().slice(0, 7);
    const [year, month] = latest.split('-').map(Number);
    const next = new Date(Date.UTC(year, month, 1));
    return `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}`;
}

function csvCell(value) {
    const text = value === null || value === undefined ? '' : String(value);
    // Quote what needs it; a leading = + - @ never reaches a spreadsheet as a formula.
    const safe = /^[=+\-@]/.test(text) ? `'${text}` : text;
    return /[",\n\r]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
}

/** The bank file: one line per person paid, amounts as decimals of the currency. */
export function bankCsv(file, headings) {
    const lines = [headings.map(csvCell).join(',')];
    for (const row of file.rows) {
        lines.push([row.employee_code, row.employee_name, row.method, row.provider, row.account_name, row.account_number, row.branch, minorToText(row.amount_minor, file.currency)].map(csvCell).join(','));
    }
    return `﻿${lines.join('\r\n')}\r\n`;
}
