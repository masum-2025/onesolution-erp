import { api } from '@/lib/http';
import { currencyDigits, formatDate, minorToDecimalString } from '@/lib/format';
import { t } from '@/lib/i18n';
import { currentOrganization } from '@/lib/session';

/**
 * Export and print of a module page (PageFrame's menu). The page hands over
 * the rows it is allowed to show and how to read them; the server decides
 * what this page may offer (rules, the module's export permission) and
 * records each export before the file is built here. Formats: CSV (Excel
 * reads its Bangla through the byte-order mark; cells that would run as a
 * formula are made plain text), XLSX, DOCX, and PDF through the browser's
 * print (the only way Bangla letters join correctly).
 *
 * columns: [{ key, label, type?: 'text' | 'number' | 'money' | 'date', currency?, value?: (row) => any }]
 */

export const FORMATS = ['pdf', 'xlsx', 'csv', 'docx'];

const settingsCache = new Map();

/**
 * What this module's pages may offer here: { formats, print, max_rows } (asked once per organization
 * and module). module "partner": a partner console page, asked of the partner console by its page.
 */
export function exportSettings(module, page = null) {
    const partner = module === 'partner';
    const key = partner ? `partner:${page}` : `${currentOrganization()?.id ?? ''}:${module}`;
    if (!settingsCache.has(key)) {
        settingsCache.set(key, (partner ? api('/api/partner/page-exports', { query: { page } }) : api('/api/page-exports', { query: { module } }))
            .then((response) => response.data)
            .catch(() => {
                settingsCache.delete(key);
                return { formats: [], print: false, max_rows: 0 };
            }));
    }
    return settingsCache.get(key);
}

/** Forget what was asked (another organization was chosen). */
export function forgetExportSettings() {
    settingsCache.clear();
}

/**
 * Every row of a list the API serves page by page ({ data, meta: { page, last_page } }),
 * up to the export's row limit: fetchPage(n) reads page n.
 */
export async function fetchPages(fetchPage, maxRows = 5000) {
    const rows = [];
    // One row past the limit is enough for the server to refuse: an export is never cut short quietly.
    for (let page = 1; page <= 500 && rows.length <= maxRows; page += 1) {
        const response = await fetchPage(page);
        rows.push(...(response.data ?? []));
        if (!response.meta || response.meta.page >= response.meta.last_page) break;
    }
    return rows.slice(0, maxRows + 1);
}

/** A cell's own value: what the column reads from the row. */
function raw(row, column) {
    return typeof column.value === 'function' ? column.value(row) : row?.[column.key];
}

/** A cell as text (CSV, DOCX): money as a decimal, dates as written, nothing as empty. */
export function cellText(row, column) {
    const value = raw(row, column);
    if (value === null || value === undefined) return '';
    if (column.type === 'money') return minorToDecimalString(value, currencyDigits(column.currency ?? row?.currency_code ?? row?.currency));
    if (column.type === 'date') return String(value).slice(0, 10);
    return String(value);
}

/** A text cell that a spreadsheet would run as a formula starts with a quote instead. */
export function safeText(text) {
    return /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
}

/** CSV with a byte-order mark (Excel then reads Bangla), quoted where needed, formulas made plain. */
export function toCsv(columns, rows) {
    const quote = (text) => (/[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text);
    const line = (cells) => cells.map(quote).join(',');
    const body = rows.map((row) => line(columns.map((column) => {
        const text = cellText(row, column);
        return ['money', 'number'].includes(column.type) ? text : safeText(text);
    })));
    return `﻿${[line(columns.map((column) => safeText(column.label))), ...body].join('\r\n')}\r\n`;
}

/** Spreadsheet cells: numbers and money as numbers, dates as dates, the rest as text. */
export function sheetData(columns, rows) {
    const header = columns.map((column) => ({ value: column.label, fontWeight: 'bold' }));
    const body = rows.map((row) => columns.map((column) => {
        const value = raw(row, column);
        if (value === null || value === undefined || value === '') return null;
        if (column.type === 'money') {
            const digits = currencyDigits(column.currency ?? row?.currency_code ?? row?.currency);
            return { type: Number, value: Number(minorToDecimalString(value, digits)), format: digits > 0 ? `#,##0.${'0'.repeat(digits)}` : '#,##0' };
        }
        if (column.type === 'number') return { type: Number, value: Number(value) };
        if (column.type === 'date') {
            const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);
            return { type: Date, value: new Date(Date.UTC(year, month - 1, day)), format: 'yyyy-mm-dd' };
        }
        return { type: String, value: String(value) };
    }));
    return [header, ...body];
}

/** "Journal entries 2026-11-03.xlsx": the page title and today, safe as a file name. */
export function fileName(title, extension, now = new Date()) {
    const day = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    const clean = String(title ?? 'export').replace(/[\\/:*?"<>|\u0000-\u001f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 80) || 'export';
    return `${clean} ${day}.${extension}`;
}

function download(blob, name) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

async function toDocx(columns, rows, { title, subtitle }) {
    const { Document, Packer, Paragraph, Table, TableRow, TableCell, TextRun, WidthType, HeadingLevel, AlignmentType } = await import('docx');
    const cell = (text, options = {}) => new TableCell({ children: [new Paragraph({ alignment: options.end ? AlignmentType.END : AlignmentType.START, children: [new TextRun({ text, bold: !!options.bold })] })] });
    const table = new Table({
        width: { size: 100, type: WidthType.PERCENTAGE },
        rows: [
            new TableRow({ tableHeader: true, children: columns.map((column) => cell(column.label, { bold: true })) }),
            ...rows.map((row) => new TableRow({ children: columns.map((column) => cell(cellText(row, column), { end: ['money', 'number'].includes(column.type) })) })),
        ],
    });
    const document = new Document({ sections: [{ children: [
        new Paragraph({ heading: HeadingLevel.HEADING_1, children: [new TextRun(title)] }),
        ...(subtitle ? [new Paragraph({ children: [new TextRun({ text: subtitle, italics: true })] })] : []),
        table,
    ] }] });
    return Packer.toBlob(document);
}

/**
 * One export or print. options: { module, page, title, subtitle?, columns, rows?, fetchAll? }.
 * Throws the server's refusal (its message says what to do) before anything is built.
 */
export async function runExport(format, options) {
    // Checked and audited by the server first: the partner console's own pages there, the rest here.
    const record = (rows) => (options.module === 'partner'
        ? api('/api/partner/page-exports', { method: 'POST', body: { page: options.page, format, rows } })
        : api('/api/page-exports', { method: 'POST', body: { module: options.module, page: options.page, format, rows } }));
    if (format === 'pdf' || format === 'print') {
        await record(options.rows?.length ?? 0);
        window.print();
        return;
    }
    // fetchAll(maxRows): no more is read than the company's rule lets one export hold.
    const rows = options.fetchAll ? await options.fetchAll((await exportSettings(options.module, options.page)).max_rows || 5000) : (options.rows ?? []);
    await record(rows.length);
    const subtitle = options.subtitle ?? t('core.export.as_of', { date: formatDate(new Date().toISOString()) });

    if (format === 'csv') {
        download(new Blob([toCsv(options.columns, rows)], { type: 'text/csv;charset=utf-8' }), fileName(options.title, 'csv'));
    } else if (format === 'xlsx') {
        const { default: writeExcelFile } = await import('write-excel-file/browser');
        const blob = await writeExcelFile(sheetData(options.columns, rows), { columns: options.columns.map((column) => ({ width: column.width ?? (column.type === 'text' || !column.type ? 28 : 16) })) }).toBlob();
        download(blob, fileName(options.title, 'xlsx'));
    } else if (format === 'docx') {
        download(await toDocx(options.columns, rows, { title: options.title, subtitle }), fileName(options.title, 'docx'));
    }
}
