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
