/**
 * The arithmetic of marks on screen, with integers only, as the server
 * does it (ExamMath): marks in hundredths, percentages in basis points,
 * grade points in hundredths, half up. Typed numbers may use Bangla digits.
 */
const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯';

/** "৮২.৫" -> "82.5": Bangla digits to English, spaces and commas dropped. */
export function normalizeNumberText(text) {
    return Array.from(String(text ?? ''))
        .map((char) => {
            const index = BANGLA_DIGITS.indexOf(char);
            return index >= 0 ? String(index) : char;
        })
        .join('')
        .replace(/[\s,_']/g, '');
}

/** A decimal with up to two places as hundredths ("82.5" -> 8250), or null. */
export function parseCenti(text) {
    const clean = normalizeNumberText(text);
    if (!/^\d{1,4}(\.\d{1,2})?$/.test(clean)) return null;
    const [whole, part = ''] = clean.split('.');
    return Number(whole) * 100 + Number(part.padEnd(2, '0'));
}

/** Hundredths as the shortest decimal (8250 -> "82.5", 7000 -> "70"; empty for null). */
export function centiText(centi) {
    if (centi === null || centi === undefined) return '';
    const whole = Math.floor(centi / 100);
    const part = String(centi % 100).padStart(2, '0').replace(/0+$/, '');
    return part ? `${whole}.${part}` : String(whole);
}

/** Basis points as a percentage text (3350 -> "33.5"). */
export const percentText = (bp) => centiText(bp);

/** A typed percentage ("33", "33.5") as basis points, 0 to 100 %, or null. */
export function parsePercent(text) {
    const bp = parseCenti(normalizeNumberText(text).replace(/%$/, ''));
    return bp === null || bp > 10000 ? null : bp;
}

/** A grade point as hundredths with two places (500 -> "5.00"). */
export function pointText(centi) {
    if (centi === null || centi === undefined) return '—';
    return `${Math.floor(centi / 100)}.${String(centi % 100).padStart(2, '0')}`;
}

/**
 * One typed cell of the mark grid: empty (clears the mark), absent
 * ("a", "ab", "অ"), marks within full marks, or not valid.
 *
 * @returns {{ kind: 'empty'|'absent'|'marks'|'invalid', centi: number|null, reason?: 'format'|'too_high' }}
 */
export function readCell(text, fullCenti) {
    const clean = normalizeNumberText(text).toLowerCase();
    if (clean === '') return { kind: 'empty', centi: null };
    if (['a', 'ab', 'abs', 'অ', 'অনু'].includes(clean)) return { kind: 'absent', centi: null };
    const centi = parseCenti(clean);
    if (centi === null) return { kind: 'invalid', centi: null, reason: 'format' };
    if (centi > fullCenti) return { kind: 'invalid', centi, reason: 'too_high' };
    return { kind: 'marks', centi };
}

/** What a saved mark shows in its cell ("82.5", "a" when absent, empty when none). */
export function cellText(mark) {
    if (!mark) return '';
    return mark.absent ? 'a' : centiText(mark.marks_centi);
}

/** Marks as a percentage of full marks in basis points, half up (66 of 75 -> 8800). */
export function percentBp(marksCenti, fullCenti) {
    if (fullCenti <= 0) return 0;
    return Math.floor((marksCenti * 10000 + Math.floor(fullCenti / 2)) / fullCenti);
}

/** The band a percentage falls in: the one with the highest start at or below it. */
export function bandFor(bands, bp) {
    let best = null;
    for (const band of bands ?? []) {
        if (band.min_percent_bp <= bp && (best === null || band.min_percent_bp > best.min_percent_bp)) best = band;
    }
    return best;
}

/** Whether bands make a whole scheme: one starts at 0 %, none twice, all within 0-100 %. */
export function bandsComplete(bands) {
    const starts = (bands ?? []).map((band) => band.min_percent_bp);
    return starts.length > 0 && starts.includes(0) && new Set(starts).size === starts.length && starts.every((bp) => bp !== null && bp >= 0 && bp <= 10000);
}

/**
 * A student's paper as the server grades it: absent parts count 0; it
 * passes when every part with a pass mark is met, the paper's pass mark is
 * met, the band passes and the student sat at least one part. A fail by a
 * part or the pass mark shows the failing band.
 *
 * @param {Array<{full_marks_centi: number, pass_marks_centi: ?number, marks_centi: ?number, absent: boolean}>} parts
 */
export function gradePaper(parts, passCenti, bands) {
    let marks = 0;
    let full = 0;
    let partsPass = true;
    let absent = parts.length > 0;
    for (const part of parts) {
        full += part.full_marks_centi;
        marks += part.absent ? 0 : (part.marks_centi ?? 0);
        absent = absent && part.absent;
        if (part.pass_marks_centi !== null && part.pass_marks_centi !== undefined && (part.absent || (part.marks_centi ?? 0) < part.pass_marks_centi)) partsPass = false;
    }
    const percent = percentBp(marks, full);
    let band = bandFor(bands, percent);
    const passed = !absent && partsPass && marks >= (passCenti ?? 0) && (band?.is_pass ?? false);
    if (!passed && band?.is_pass) band = bandFor(bands, 0);
    return { marks_centi: marks, full_marks_centi: full, percent_bp: percent, letter: band?.letter ?? null, point_centi: passed ? (band?.point_centi ?? 0) : 0, passed, absent };
}

/**
 * How far a sheet is: a student is done with every part entered, or with
 * any part absent (as the server counts before taking it in).
 */
export function sheetProgress(students, parts, cells) {
    let done = 0;
    for (const student of students) {
        const row = parts.map((part) => cells[student.id]?.[part.id]);
        // A cell on screen: { kind: 'marks'|'absent'|'empty'|'invalid' }.
        if (row.some((cell) => cell?.kind === 'absent') || row.every((cell) => cell?.kind === 'marks')) done++;
    }
    return { done, total: students.length, missing: students.length - done };
}

/** Where an exam can go next (as the server allows). */
export function nextStatuses(status) {
    return { draft: ['marking'], marking: ['closed'], closed: ['marking'] }[status] ?? [];
}

export const examTone = { draft: 'neutral', marking: 'brand', closed: 'warn', published: 'ok' };
export const sheetTone = { open: 'neutral', returned: 'bad', submitted: 'warn', approved: 'ok' };

/** Sheets of an exam by status, for a progress bar: approved, waiting, being entered, sent back. */
export function sheetCounts(counts) {
    const get = (key) => Number(counts?.[key] ?? 0);
    const total = get('open') + get('returned') + get('submitted') + get('approved');
    return { total, approved: get('approved'), submitted: get('submitted'), open: get('open'), returned: get('returned') };
}

/** Typed weights ("30", "70") as basis points with their total; a weight not valid is null. */
export function readWeights(texts) {
    const weights = texts.map((text) => {
        const bp = parsePercent(text);
        return bp === null || bp === 0 ? null : bp;
    });
    return { weights, total: weights.reduce((sum, bp) => sum + (bp ?? 0), 0), valid: weights.length > 0 && weights.every((bp) => bp !== null) };
}

/**
 * A results list in short: how many, passing and failing (of those whose
 * whole result shows) and the pass rate in basis points.
 */
export function resultSummary(rows) {
    const whole = rows.filter((row) => !row.partial);
    const passed = whole.filter((row) => row.passed).length;
    return { students: rows.length, passed, failed: whole.length - passed, rate_bp: whole.length ? Math.floor((passed * 10000 + Math.floor(whole.length / 2)) / whole.length) : null };
}
