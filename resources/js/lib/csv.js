/**
 * Saving tables as CSV from the screens (reports already on the page, so
 * no second request): a byte order mark for spreadsheets, quotes where
 * needed, and any cell that could start a formula kept as text.
 */
export function toCsv(rows) {
    const cell = (value) => {
        let text = value === null || value === undefined ? '' : String(value);
        if (/^[=+\-@\t\r]/.test(text) && !/^-?\d+(\.\d+)?$/.test(text)) text = `'${text}`;
        return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    };
    return `﻿${rows.map((row) => row.map(cell).join(',')).join('\r\n')}\r\n`;
}

/** Hands the reader a text file to save. */
export function downloadText(name, text, type = 'text/csv;charset=utf-8') {
    const url = URL.createObjectURL(new Blob([text], { type }));
    const link = Object.assign(document.createElement('a'), { href: url, download: name });
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Thousandths -> "12.5" (plain digits, no grouping) for a CSV cell. */
export function milliForCsv(milli) {
    if (milli === null || milli === undefined) return '';
    const sign = milli < 0 ? '-' : '';
    const whole = Math.trunc(Math.abs(milli) / 1000);
    const rest = String(Math.abs(milli) % 1000).padStart(3, '0').replace(/0+$/, '');
    return `${sign}${whole}${rest ? `.${rest}` : ''}`;
}
