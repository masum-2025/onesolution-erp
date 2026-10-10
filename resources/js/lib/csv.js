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

/**
 * A small CSV reader (commas or semicolons, quotes, a byte order mark): the
 * first row is the headings, lower-cased; returns a list of {heading: value}.
 */
export function readCsv(text) {
    const clean = String(text ?? '').replace(/^﻿/, '');
    const firstLine = clean.split(/\r?\n/, 1)[0] ?? '';
    const delimiter = (firstLine.match(/;/g)?.length ?? 0) > (firstLine.match(/,/g)?.length ?? 0) ? ';' : ',';
    const rows = [];
    let row = [];
    let cell = '';
    let quoted = false;
    for (let index = 0; index < clean.length; index++) {
        const char = clean[index];
        if (quoted) {
            if (char === '"' && clean[index + 1] === '"') {
                cell += '"';
                index++;
            } else if (char === '"') quoted = false;
            else cell += char;
        } else if (char === '"') quoted = true;
        else if (char === delimiter) {
            row.push(cell);
            cell = '';
        } else if (char === '\n' || char === '\r') {
            if (char === '\r' && clean[index + 1] === '\n') index++;
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';
        } else cell += char;
    }
    if (cell !== '' || row.length) {
        row.push(cell);
        rows.push(row);
    }
    const [head = [], ...body] = rows.filter((cells) => cells.some((value) => value.trim() !== ''));
    const keys = head.map((name) => name.trim().toLowerCase().replace(/\s+/g, '_'));
    return body.map((cells) => Object.fromEntries(keys.map((key, index) => [key, (cells[index] ?? '').trim()])));
}
