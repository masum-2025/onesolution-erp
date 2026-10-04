import { currencyDigits, decimalStringToMinor, minorToDecimalString } from '@/lib/format';

/**
 * Small Accounting helpers for the screens (tested in tests/js/accounting.test.js).
 * Amounts are integer minor units; typed amounts are read as text (never
 * floats). The server checks everything again.
 */

const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯';

/** "১,২৫০.৫০" or "1,250.50" -> "1250.50": Bangla digits to ASCII, group separators and spaces removed. */
export function normalizeAmountText(text) {
    return Array.from(String(text ?? ''))
        .map((char) => {
            const index = BANGLA_DIGITS.indexOf(char);
            return index >= 0 ? String(index) : char;
        })
        .join('')
        .replace(/[\s,_']/g, '')
        .replace(/^৳|^\p{Sc}/u, '');
}

/**
 * A typed amount in minor units of the currency, or null when it is not a
 * valid positive amount (letters, too many decimals, negative). Empty = 0.
 */
export function parseAmount(text, currency) {
    const clean = normalizeAmountText(text);
    if (clean === '') return 0;
    if (clean.startsWith('-')) return null;
    return decimalStringToMinor(clean, currencyDigits(currency));
}

/** Minor units back to the text shown in an amount field ("1250.50"; empty for 0). */
export function amountText(minor, currency) {
    if (!minor) return '';
    return minorToDecimalString(minor, currencyDigits(currency));
}

/**
 * Totals of the lines being written: debit, credit, the difference, and
 * whether the entry can be sent (balanced, above zero, at least two lines,
 * every line complete and on one side only).
 */
export function lineTotals(lines, currency) {
    let debit = 0;
    let credit = 0;
    let complete = 0;
    let invalid = false;
    for (const line of lines ?? []) {
        const d = parseAmount(line.debit, currency);
        const c = parseAmount(line.credit, currency);
        if (d === null || c === null || (d > 0 && c > 0)) {
            invalid = true;
            continue;
        }
        debit += d;
        credit += c;
        if (line.account_id && d + c > 0) complete += 1;
    }
    return { debit, credit, difference: debit - credit, balanced: !invalid && debit === credit && debit > 0 && complete >= 2, invalid };
}

/** The request body of a journal: lines with an account and an amount, amounts in minor units. */
export function journalPayload(form, currency) {
    return {
        entry_date: form.entry_date,
        narration: (form.narration ?? '').trim(),
        lines: (form.lines ?? [])
            .filter((line) => line.account_id || line.debit || line.credit)
            .map((line) => {
                const body = { account_id: line.account_id };
                const debit = parseAmount(line.debit, currency);
                const credit = parseAmount(line.credit, currency);
                if (debit) body.debit_minor = debit;
                if (credit) body.credit_minor = credit;
                if (line.cost_centre_id) body.cost_centre_id = line.cost_centre_id;
                if (line.memo?.trim()) body.memo = line.memo.trim();
                return body;
            }),
    };
}

/** Form lines from a saved journal (for editing a draft or a rejected entry); the company itself is "no cost centre". */
export function formLines(journal, currency, companyId) {
    return (journal?.lines ?? []).map((line) => ({
        account_id: line.account_id,
        debit: amountText(line.debit_minor, currency),
        credit: amountText(line.credit_minor, currency),
        cost_centre_id: line.cost_centre_id === companyId ? '' : line.cost_centre_id,
        memo: line.memo ?? '',
    }));
}

/**
 * Accounts as a tree in code order: [{ ...account, depth, children }],
 * flattened for lists and selects (`flat`) with each account's depth.
 */
export function accountTree(accounts) {
    const byParent = new Map();
    for (const account of accounts ?? []) {
        const key = account.parent_id ?? null;
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key).push(account);
    }
    const ids = new Set((accounts ?? []).map((account) => account.id));
    const flat = [];
    const walk = (parentId, depth) => {
        const children = (byParent.get(parentId) ?? []).slice().sort((a, b) => a.code.localeCompare(b.code));
        return children.map((account) => {
            const node = { ...account, depth };
            flat.push(node);
            node.children = walk(account.id, depth + 1);
            return node;
        });
    };
    // Roots, and accounts whose group is not in the list (archived or filtered out).
    const roots = walk(null, 0);
    for (const [parentId] of byParent) {
        if (parentId !== null && !ids.has(parentId)) roots.push(...walk(parentId, 0));
    }
    return { roots, flat };
}

/** Accounts a line can use: active and not a group heading. */
export function postableAccounts(accounts) {
    return (accounts ?? []).filter((account) => !account.is_group && account.status === 'active');
}

/** Badge tone of a journal status. */
export function statusTone(status) {
    return { draft: 'neutral', pending_approval: 'warn', posted: 'ok', rejected: 'bad' }[status] ?? 'neutral';
}

/** Today as YYYY-MM-DD in a timezone (the company's), for the default entry date. */
export function todayIn(timeZone, now = new Date()) {
    try {
        return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    } catch {
        return now.toISOString().slice(0, 10);
    }
}

/** First and last day of the month of a YYYY-MM-DD date. */
export function monthOf(date) {
    const [year, month] = date.split('-').map(Number);
    const last = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const pad = (value) => String(value).padStart(2, '0');
    return { from: `${year}-${pad(month)}-01`, to: `${year}-${pad(month)}-${pad(last)}` };
}

/* ── Receivables and payables (ACC-3b) ─────────────────────────────── */

/** Sales documents are invoices and credit notes; purchases bills and vendor credits. */
export function sideOf(type) {
    return ['invoice', 'credit_note'].includes(type) ? 'sales' : 'purchases';
}

/** A credit note or vendor credit reduces what is owed. */
export function isCredit(type) {
    return ['credit_note', 'vendor_credit'].includes(type);
}

/** "1.5" (Bangla digits too) -> 1500 thousandths; null when not a quantity with at most 3 decimals. */
export function quantityMilli(text) {
    const match = normalizeAmountText(text).match(/^(\d{1,9})(?:\.(\d{1,3}))?$/);
    if (!match) return null;
    return Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));
}

/** Quantity x unit price, half up, in minor units: the server's own rule, with integers only. */
export function lineAmount(quantity, unitPriceMinor) {
    if (quantity === null || unitPriceMinor === null) return null;
    const total = BigInt(quantity) * BigInt(unitPriceMinor) + 500n;
    return Number(total / 1000n);
}

/** Each line's amount and the document total while people type; null amounts mark lines to fix. */
export function documentTotals(lines, currency) {
    let total = 0;
    let invalid = false;
    const amounts = (lines ?? []).map((line) => {
        const amount = lineAmount(quantityMilli(line.quantity), parseAmount(line.price, currency));
        if (amount === null) invalid = true;
        else total += amount;
        return amount;
    });
    return { amounts, total, invalid };
}

/** The request body of a document: lines with a description, amounts in minor units. */
export function documentPayload(form, currency) {
    return {
        party_id: form.party_id,
        issue_date: form.issue_date,
        ...(form.due_date ? { due_date: form.due_date } : {}),
        reference: form.reference?.trim() || null,
        notes: form.notes?.trim() || null,
        lines: (form.lines ?? [])
            .filter((line) => line.description?.trim() || line.account_id || line.price)
            .map((line) => ({
                description: line.description.trim(),
                quantity: normalizeAmountText(line.quantity),
                unit_price_minor: parseAmount(line.price, currency),
                account_id: line.account_id,
                ...(line.cost_centre_id ? { cost_centre_id: line.cost_centre_id } : {}),
                ...(line.tax_code_id ? { tax_code_id: line.tax_code_id } : {}),
            })),
    };
}

/** Form lines from a saved document (editing a draft or a rejected one). */
export function documentFormLines(document, currency, companyId) {
    return (document?.lines ?? []).map((line) => ({
        description: line.description,
        quantity: line.quantity,
        price: amountText(line.unit_price_minor, currency) || '0',
        account_id: line.account_id,
        cost_centre_id: line.cost_centre_id === companyId ? '' : line.cost_centre_id,
        tax_code_id: line.tax_code_id ?? '',
    }));
}

/**
 * Spread an amount over open documents, oldest due first, as far as it goes.
 * Returns { [document id]: minor amount } for the ones that get something.
 */
export function autoAllocate(documents, amount) {
    const shares = {};
    let left = amount;
    const ordered = [...(documents ?? [])].sort((a, b) => a.due_date.localeCompare(b.due_date) || a.issue_date.localeCompare(b.issue_date));
    for (const document of ordered) {
        if (left <= 0) break;
        const share = Math.min(left, document.balance_minor);
        if (share > 0) {
            shares[document.id] = share;
            left -= share;
        }
    }
    return shares;
}

/** Badge tone of a document or settlement status. */
export function documentTone(status) {
    return { draft: 'neutral', pending_approval: 'warn', rejected: 'bad', posted: 'brand', partly_paid: 'warn', paid: 'ok', void: 'neutral' }[status] ?? 'neutral';
}

/** Days from today to a due date (negative = overdue), in the company timezone's calendar. */
export function daysUntilDue(dueDate, today) {
    const day = (text) => Date.UTC(...text.split('-').map((part, index) => Number(part) - (index === 1 ? 1 : 0)));
    return Math.round((day(dueDate) - day(today)) / 86400000);
}

/** The text key of a document status: credits say "used" where invoices say "paid". */
export function statusKey(type, status) {
    return isCredit(type) && ['posted', 'partly_paid', 'paid'].includes(status) ? `accounting.credit_statuses.${status}` : `accounting.doc_statuses.${status}`;
}

/* ── Tax (ACC-4a) ──────────────────────────────────────────────────── */

/**
 * A line's net amount and tax, like the server (integers, half up): on top
 * of the amount, or contained in it when prices include tax.
 */
export function taxSplit(amount, rateBp, inclusive) {
    if (amount === null) return { net: null, tax: null };
    if (!rateBp) return { net: amount, tax: 0 };
    if (!inclusive) return { net: amount, tax: Number((BigInt(amount) * BigInt(rateBp) + 5000n) / 10000n) };
    const divisor = 10000n + BigInt(rateBp);
    const net = Number((BigInt(amount) * 10000n + divisor / 2n) / divisor);
    return { net, tax: amount - net };
}

/** Net, tax and total of the lines being written, with each line's net amount and tax. */
export function documentTaxTotals(lines, currency, taxCodes, inclusive) {
    const rates = Object.fromEntries((taxCodes ?? []).map((code) => [code.id, code.rate_bp]));
    let net = 0;
    let tax = 0;
    let invalid = false;
    const rows = (lines ?? []).map((line) => {
        const typed = lineAmount(quantityMilli(line.quantity), parseAmount(line.price, currency));
        if (typed === null) {
            invalid = true;
            return { net: null, tax: null };
        }
        const split = taxSplit(typed, rates[line.tax_code_id] ?? 0, inclusive);
        net += split.net;
        tax += split.tax;
        return split;
    });
    return { rows, net, tax, total: net + tax, invalid };
}

/** "7.5" (Bangla digits too) -> 750 basis points; null when not a percentage with at most 2 decimals. */
export function percentToBp(text) {
    const minor = parseAmount(text, 'XXX');
    return minor === null || minor > 10000 ? null : minor;
}

/** 750 basis points -> "7.5". */
export function bpToPercent(bp) {
    const whole = Math.trunc(bp / 100);
    const fraction = String(bp % 100).padStart(2, '0').replace(/0+$/, '');
    return fraction ? `${whole}.${fraction}` : String(whole);
}

/**
 * Opening balances being written: account rows (debit or credit), what each
 * customer still owes and what each vendor is still owed. Debits are the
 * accounts' debits and the customers; credits the accounts' credits and the
 * vendors. The difference goes to the opening balance account (the other
 * way round); invalid is true while an amount cannot be read.
 */
export function openingTotals(form, currency) {
    let debit = 0;
    let credit = 0;
    let invalid = false;
    const add = (text, side) => {
        const minor = parseAmount(text, currency);
        if (minor === null) invalid = true;
        else if (side === 'debit') debit += minor;
        else credit += minor;
    };
    for (const row of form.accounts ?? []) {
        add(row.debit, 'debit');
        add(row.credit, 'credit');
    }
    for (const row of form.customers ?? []) add(row.amount, 'debit');
    for (const row of form.vendors ?? []) add(row.amount, 'credit');

    return { debit, credit, difference: debit - credit, invalid };
}

/** The opening balances for the API: empty rows left out, amounts in minor units. */
export function openingPayload(form, currency) {
    const party = (kind) => (row) => ({
        kind,
        party_id: row.party_id,
        amount_minor: parseAmount(row.amount, currency),
        issue_date: row.issue_date,
        ...(row.due_date ? { due_date: row.due_date } : {}),
        reference: row.reference?.trim() || null,
    });
    const filled = (row) => row.party_id || row.amount?.trim();

    return {
        opening_date: form.opening_date,
        lines: [
            ...(form.accounts ?? [])
                .filter((row) => row.account_id || row.debit?.trim() || row.credit?.trim())
                .map((row) => ({
                    kind: 'account',
                    account_id: row.account_id,
                    ...(row.debit?.trim() ? { debit_minor: parseAmount(row.debit, currency) } : {}),
                    ...(row.credit?.trim() ? { credit_minor: parseAmount(row.credit, currency) } : {}),
                })),
            ...(form.customers ?? []).filter(filled).map(party('customer')),
            ...(form.vendors ?? []).filter(filled).map(party('vendor')),
        ],
    };
}

/** Form rows from saved opening balances (editing a draft or a rejected one). */
export function openingForm(opening, currency) {
    const lines = opening?.lines ?? [];
    const partyRow = (line) => ({
        party_id: line.party_id,
        amount: amountText(line.amount_minor, currency),
        reference: line.reference ?? '',
        issue_date: line.issue_date ?? '',
        due_date: line.due_date ?? '',
    });

    return {
        opening_date: opening?.opening_date ?? '',
        accounts: lines.filter((line) => line.kind === 'account').map((line) => ({
            account_id: line.account_id,
            debit: amountText(line.debit_minor, currency),
            credit: amountText(line.credit_minor, currency),
        })),
        customers: lines.filter((line) => line.kind === 'customer').map(partyRow),
        vendors: lines.filter((line) => line.kind === 'vendor').map(partyRow),
    };
}

/** Split one CSV line into cells (quotes, doubled quotes, the delimiter given). */
function csvCells(line, delimiter) {
    const cells = [];
    let cell = '';
    let quoted = false;
    for (let i = 0; i < line.length; i++) {
        const char = line[i];
        if (quoted) {
            if (char === '"' && line[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (char === '"') quoted = false;
            else cell += char;
        } else if (char === '"') quoted = true;
        else if (char === delimiter) {
            cells.push(cell.trim());
            cell = '';
        } else cell += char;
    }
    cells.push(cell.trim());
    return cells;
}

/**
 * The heading row and first lines of a statement file (the server reads the
 * whole file; this only fills the column choices and shows an example).
 */
export function csvPreview(text, sampleRows = 3) {
    const lines = String(text ?? '').replace(/^﻿/, '').split(/\r?\n/).filter((line) => line.trim() !== '');
    if (!lines.length) return { columns: [], rows: [] };
    const first = lines[0];
    const delimiter = [',', ';', '\t'].sort((a, b) => first.split(b).length - first.split(a).length)[0];
    return {
        columns: csvCells(first, delimiter).filter((name) => name !== ''),
        rows: lines.slice(1, 1 + sampleRows).map((line) => csvCells(line, delimiter)),
    };
}

/** A first guess of which column holds what, from heading words in English or Bangla. */
export function guessBankColumns(columns) {
    const find = (...words) => columns.find((name) => words.some((word) => name.toLowerCase().includes(word))) ?? '';
    const moneyIn = find('deposit', 'credit', 'money in', 'জমা');
    const moneyOut = find('withdraw', 'debit', 'money out', 'উত্তোলন', 'খরচ');
    return {
        date: find('date', 'তারিখ'),
        description: find('narration', 'description', 'particular', 'details', 'বিবরণ'),
        reference: find('ref', 'cheque', 'chq', 'transaction id', 'trx'),
        amount: moneyIn || moneyOut ? '' : find('amount', 'অঙ্ক', 'টাকা'),
        money_in: moneyIn,
        money_out: moneyOut,
    };
}

/** Book lines chosen for a statement line: their total and whether it is exactly the line's amount. */
export function matchTotal(bookLines, chosenIds, amount) {
    const chosen = new Set(chosenIds);
    const total = (bookLines ?? []).filter((line) => chosen.has(line.id)).reduce((sum, line) => sum + line.amount_minor, 0);
    return { total, exact: chosen.size > 0 && total === amount };
}
