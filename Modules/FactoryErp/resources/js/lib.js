import { currencyDigits, decimalStringToMinor, formatDecimal, minorToDecimalString } from '@/lib/format';

/**
 * Factory helpers for the screens: typed numbers (Bangla digits understood)
 * to integer thousandths and minor units without floats, order sheet
 * totals, the same need rule the server uses (to preview), and own-field
 * values to and from inputs.
 */

const BANGLA = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' };

/** "১,২৫০.৫" -> "1250.5" */
export function cleanNumber(text) {
    return String(text ?? '').trim().replace(/[০-৯]/g, (digit) => BANGLA[digit]).replace(/[,\s]/g, '');
}

/** "2.5" -> 2500; null when not a quantity above zero with at most 3 decimals. */
export function quantityToMilli(text) {
    const match = /^(\d{1,9})(?:\.(\d{1,3}))?$/.exec(cleanNumber(text));
    if (!match) return null;
    const milli = Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));
    return milli > 0 ? milli : null;
}

/** 2500 -> "2.5" */
export function milliToText(milli) {
    if (milli === null || milli === undefined) return '';
    const whole = Math.trunc(milli / 1000);
    const rest = String(Math.abs(milli) % 1000).padStart(3, '0').replace(/0+$/, '');
    return rest ? `${whole}.${rest}` : String(whole);
}

/** Thousandths for reading: the reader's digits and thousands separators ("১৩,৮৬৬.৮"). */
export function formatMilli(milli) {
    return milli === null || milli === undefined ? '—' : formatDecimal(milliToText(milli));
}

/** "5" -> 500 basis points; "2.5" -> 250; null when not 0-100 with two decimals at most. */
export function percentToBp(text) {
    const match = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(cleanNumber(text));
    if (!match) return null;
    const bp = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
    return bp <= 10000 ? bp : null;
}

export function bpToPercent(bp) {
    return bp === null || bp === undefined ? '' : String(bp / 100);
}

/** Typed money -> minor units; null when not an amount at or above zero. */
export function amountToMinor(text, currency) {
    const clean = cleanNumber(text);
    if (clean === '' || !/^\d+(\.\d+)?$/.test(clean)) return null;
    return decimalStringToMinor(clean, currencyDigits(currency));
}

export function minorToText(minor, currency) {
    return minor === null || minor === undefined ? '' : minorToDecimalString(minor, currencyDigits(currency));
}

/** Pieces as whole numbers (empty is 0); null when not a whole number. */
export function piecesOf(text) {
    const clean = cleanNumber(text);
    if (clean === '') return 0;
    return /^\d{1,8}$/.test(clean) ? Number(clean) : null;
}

/**
 * An order sheet's totals: per size, per colour and in all.
 * cells: {"size|color": pieces}
 */
export function sheetTotals(sizes, colors, cells) {
    const bySize = Object.fromEntries(sizes.map((size) => [size, colors.reduce((sum, color) => sum + (cells[`${size}|${color}`] ?? 0), 0)]));
    const byColor = Object.fromEntries(colors.map((color) => [color, sizes.reduce((sum, size) => sum + (cells[`${size}|${color}`] ?? 0), 0)]));
    return { bySize, byColor, total: Object.values(bySize).reduce((sum, value) => sum + value, 0) };
}

/**
 * What "fill in what is left" gives for a material: still to give, at most
 * what the stores hold, in whole steps of its unit (decimals 0: whole
 * pieces). Up to the next step while the stores have it, else down.
 */
export function fillQuantity(toIssueMilli, onHandMilli, decimals = 3) {
    const step = 10 ** Math.max(0, 3 - Math.min(3, decimals));
    const up = Math.ceil(toIssueMilli / step) * step;
    if (up <= onHandMilli) return up;
    return Math.floor(Math.min(toIssueMilli, onHandMilli) / step) * step;
}

/** A stage's share of the order, whole per cent, 0-100. */
export function stagePercent(value, ordered) {
    return ordered > 0 ? Math.min(100, Math.round((value * 100) / ordered)) : 0;
}

/**
 * Pieces of a size and colour a line can still write: cut, less good and
 * rejected already (what the server checks). cell: {cut, good, rejects}.
 */
export function sewLeft(cell) {
    return Math.max(0, (cell?.cut ?? 0) - (cell?.good ?? 0) - (cell?.rejects ?? 0));
}

/** Pieces an hour at the target, as the server works it out: people × 60 ÷ SMV × target, rounded down. */
export function hourTarget(people, smvMilli, targetBp) {
    return smvMilli > 0 ? Math.floor((people * 60 * 1000 * targetBp) / (smvMilli * 10000)) : 0;
}

/** Efficiency in basis points: earned (thousandths of a minute) over available minutes; null when none available. */
export function efficiencyBp(earnedMilli, availableMinutes) {
    return availableMinutes > 0 ? Math.floor((earnedMilli * 10) / availableMinutes) : null;
}

/** Defects per hundred pieces in basis points of a unit (320 = 3.2), as the server works it out; null with nothing checked. */
export function dhuBp(defects, checked) {
    return checked > 0 ? Math.floor((defects * 10000) / checked) : null;
}

/** "3.2" from a DHU in basis points. */
export function dhuText(bp) {
    return bp === null || bp === undefined ? '—' : (Math.floor((bp + 5) / 10) / 10).toFixed(1);
}

/** Sample size and most defects accepted for a lot, from the AQL rows the server sends ([{up_to, sample, accept}]). */
export function aqlSample(levels, lot) {
    if (!(lot >= 2)) return null;
    const row = levels.find((level) => level.up_to === null || lot <= level.up_to);
    return row ? { sample: Math.min(row.sample, lot), accept: row.accept } : null;
}

/** What a piece ticket pays, as the server works it out: pieces × rate (÷ 12 for a dozen, half up). */
export function ticketAmount(pieces, rateMinor, per) {
    return Math.floor((pieces * rateMinor * 2 + per) / (2 * per));
}

/**
 * A packing list's totals from its carton runs ({from_no, to_no, pieces_each, net_grams_each, gross_grams_each, length_mm, width_mm, height_mm}):
 * cartons, pieces, net and gross grams, and volume in cubic millimetres (integers).
 */
export function packingTotals(cartons) {
    return cartons.reduce((sum, row) => {
        const count = Math.max(0, row.to_no - row.from_no + 1);
        return {
            cartons: sum.cartons + count,
            pieces: sum.pieces + count * row.pieces_each,
            net: sum.net + count * (row.net_grams_each ?? 0),
            gross: sum.gross + count * (row.gross_grams_each ?? 0),
            volume: sum.volume + count * (row.length_mm ?? 0) * (row.width_mm ?? 0) * (row.height_mm ?? 0),
        };
    }, { cartons: 0, pieces: 0, net: 0, gross: 0, volume: 0 });
}

/** Cubic millimetres as cubic metres with three decimals ("1.234"). */
export function cbmText(volumeMm3) {
    const thousandths = Math.round(volumeMm3 / 1000000);
    return milliToText(thousandths) || '0';
}

/** The next run after the last one: numbers carry on, the same carton (or an empty one). */
export function nextCartonRun(rows, count = 1) {
    const last = rows[rows.length - 1];
    const from = last ? Number(last.to_no) + 1 : 1;
    return { ...(last ?? { size: '', color: '', pieces_each: '', net: '', gross: '', length: '', width: '', height: '' }), from_no: from, to_no: from + count - 1 };
}

/** The hour a phone is in (0-23), for a new entry. */
export function currentHour(now = new Date()) {
    return now.getHours();
}

/** A typed sheet ({"size|color": text}) -> lines for the API; blanks are 0. */
export function sheetToLines(sheet) {
    return Object.entries(sheet).map(([key, text]) => {
        const [size, color] = key.split('|');
        return { size, color, quantity: piecesOf(text) ?? 0 };
    });
}

/** Most pieces of a size and colour that may be cut: ordered plus the allowance (basis points), rounded down as the server does. */
export function cutAllowed(ordered, allowanceBp) {
    return Math.floor((ordered * (10000 + allowanceBp)) / 10000);
}

/** The server's rule: ceil(pieces × consumption × (10000 + wastage) / (per × 10000)), per = 12 for a dozen. */
export function need(pieces, consumptionMilli, wastageBp, basis) {
    const bottom = (basis === 'dozen' ? 12 : 1) * 10000;
    return Math.floor((pieces * consumptionMilli * (10000 + wastageBp) + bottom - 1) / bottom);
}

/** An own field's stored value as the text of its input. */
export function fieldToInput(field, value, currency) {
    if (value === null || value === undefined) return '';
    if (field.type === 'money') return minorToText(value, currency);
    if (field.type === 'yes_no') return value ? 'yes' : 'no';
    return String(value);
}

/** Inputs of own fields -> what the API takes ({key: value}); money as minor units; empty clears. */
export function fieldsToApi(fields, inputs, currency) {
    const values = {};
    const errors = {};
    for (const field of fields) {
        if (!(field.key in inputs)) continue;
        const raw = inputs[field.key];
        const text = typeof raw === 'string' ? raw.trim() : raw;
        if (text === '' || text === null || text === undefined) {
            values[field.key] = null;
        } else if (field.type === 'money') {
            const minor = amountToMinor(text, currency);
            if (minor === null) errors[field.key] = true;
            else values[field.key] = minor;
        } else if (field.type === 'yes_no') {
            values[field.key] = text === 'yes' || text === true;
        } else if (field.type === 'number') {
            values[field.key] = cleanNumber(text);
        } else {
            values[field.key] = text;
        }
    }
    return { values, errors };
}

/** An own field's value as text to read (choices by their label). */
export function fieldText(field, value, money) {
    if (value === null || value === undefined || value === '') return '—';
    if (field.type === 'money') return money(value);
    if (field.type === 'choice') return field.options.find((option) => option.value === value)?.label ?? value;
    if (field.type === 'yes_no') return value ? '✓' : '✗';
    return String(value);
}

/** Order status -> badge tone. */
export function orderTone(status) {
    return { draft: 'neutral', confirmed: 'brand', in_production: 'warn', shipped: 'ok', cancelled: 'bad' }[status] ?? 'neutral';
}
