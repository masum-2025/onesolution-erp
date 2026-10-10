/**
 * Small helpers of the education screens, kept free of components so they
 * can be tested.
 */

/** Two letters of a name for an avatar ("Rahim Uddin" -> "RU", "রহিম" -> "র"). */
export function initials(name) {
    const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    const first = Array.from(parts[0])[0];
    const last = parts.length > 1 ? Array.from(parts[parts.length - 1])[0] : '';
    return (first + last).toUpperCase();
}

/**
 * A steady colour index for a name (the same name, the same colour), so
 * avatars without photos are told apart; never the only way to tell them apart.
 */
export function hue(seed, count = 8) {
    let hash = 0;
    for (const char of String(seed ?? '')) hash = (hash * 31 + char.codePointAt(0)) >>> 0;
    return hash % count;
}

/**
 * How full a section is: taken, capacity, percent, and a tone with its own
 * word (never colour alone): open, filling (75 %+), nearly full (90 %+), full.
 */
export function fullness(taken, capacity) {
    const percent = capacity > 0 ? Math.min(100, Math.round((taken * 100) / capacity)) : 0;
    const state = taken >= capacity ? 'full' : percent >= 90 ? 'nearly_full' : percent >= 75 ? 'filling' : 'open';
    const tone = { full: 'bad', nearly_full: 'warn', filling: 'brand', open: 'ok' }[state];
    return { taken, capacity, left: Math.max(0, capacity - taken), percent, state, tone };
}

/** Status of a student -> badge tone. */
export function statusTone(status) {
    return { active: 'ok', suspended: 'warn', left: 'neutral', graduated: 'brand' }[status] ?? 'neutral';
}

/** A phone kept as E.164 shown the local way for Bangladesh numbers (+8801711000000 -> 01711-000000). */
export function phoneText(phone) {
    if (!phone) return '';
    const local = /^\+880(\d{4})(\d{6})$/.exec(phone);
    return local ? `0${local[1]}-${local[2]}` : phone;
}

/**
 * Own field values from a form ({key: text|list}) to the API, yes/no as
 * booleans. Empty ones are left out, or sent as null with `clear` (when
 * changing a record, so a value wiped in the form is wiped on the server).
 */
export function fieldsToApi(fields, values, { clear = false } = {}) {
    const out = {};
    for (const field of fields) {
        const value = values?.[field.key];
        if (value === undefined || value === null || value === '' || (Array.isArray(value) && !value.length)) {
            if (clear) out[field.key] = null;
            continue;
        }
        out[field.key] = field.type === 'yes_no' ? value === 'yes' : value;
    }
    return out;
}

/** Stored own field values back into a form. */
export function fieldsToForm(fields, values) {
    const out = {};
    for (const field of fields) {
        const value = values?.[field.key];
        if (value === undefined || value === null) continue;
        out[field.key] = field.type === 'yes_no' ? (value ? 'yes' : 'no') : value;
    }
    return out;
}

/** A stored own field value as text for reading. */
export function fieldText(field, value, labelOf) {
    if (value === undefined || value === null || value === '') return '—';
    if (field.type === 'yes_no') return value ? labelOf('yes') : labelOf('no');
    const option = (key) => field.options?.find((item) => item.value === key);
    if (field.type === 'choice') return option(value) ? labelOf(option(value).label) : value;
    if (field.type === 'multi_choice') return (Array.isArray(value) ? value : [value]).map((key) => (option(key) ? labelOf(option(key).label) : key)).join(', ');
    return String(value);
}

/**
 * Suggested sessions for a year of a kind: the whole year, two semesters,
 * three trimesters or four terms, with dates split evenly (editable after).
 */
export function suggestSessions(kind, periods, startsOn, endsOn) {
    const start = new Date(`${startsOn}T00:00:00Z`);
    const end = new Date(`${endsOn}T00:00:00Z`);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end < start) return [];
    const count = kind === 'year' ? 1 : Math.max(1, Math.min(4, periods));
    const months = (end.getUTCFullYear() - start.getUTCFullYear()) * 12 + end.getUTCMonth() - start.getUTCMonth() + 1;
    const each = Math.max(1, Math.floor(months / count));
    const day = (date) => date.toISOString().slice(0, 10);
    const sessions = [];
    for (let index = 0; index < count; index++) {
        const from = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + index * each, 1));
        const to = index === count - 1 ? end : new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + (index + 1) * each, 0));
        sessions.push({ sequence: index + 1, starts_on: day(index === 0 ? start : from), ends_on: day(to) });
    }
    return sessions;
}

/** A field key from its English label ("Blood group" -> "blood_group"); empty when too short. */
export function keyFrom(label) {
    const key = String(label ?? '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^[^a-z]+/, '')
        .slice(0, 40)
        .replace(/_+$/, '');
    return key.length >= 2 ? key : '';
}

/**
 * Sections of a session grouped by their level, levels in the order given by
 * `rank` (program order, then sequence), with seats taken and offered summed.
 */
export function byLevel(sections, rank = () => 0) {
    const groups = new Map();
    for (const section of sections) {
        if (!groups.has(section.level_id)) groups.set(section.level_id, { level_id: section.level_id, sections: [], taken: 0, capacity: 0 });
        const group = groups.get(section.level_id);
        group.sections.push(section);
        group.taken += section.taken ?? 0;
        group.capacity += section.capacity ?? 0;
    }
    return [...groups.values()].sort((a, b) => rank(a.level_id) - rank(b.level_id));
}

/** Which step of a form a server error belongs to (the first error that matches; else the first step). */
export function stepOfError(keys, steps) {
    for (const key of keys) {
        const step = steps.find((item) => item.prefixes.some((prefix) => key === prefix || key.startsWith(`${prefix}.`)));
        if (step) return step.key;
    }
    return steps[0]?.key ?? null;
}

/** A fresh id for a write that is safe to send twice. */
export function newOpId() {
    return globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

/** Decisions an application can take next, as the server allows them (admitting is its own step). */
export const ADMISSION_NEXT = {
    applied: ['test', 'offered', 'rejected', 'withdrawn'],
    test: ['offered', 'rejected', 'withdrawn'],
    offered: ['rejected', 'withdrawn'],
};

/** An application still waiting for a decision can be admitted. */
export function canAdmit(status) {
    return ['applied', 'test', 'offered'].includes(status);
}

/** Status of an application -> badge tone. */
export function admissionTone(status) {
    return { applied: 'brand', test: 'warn', offered: 'ok', admitted: 'ok', rejected: 'bad', withdrawn: 'neutral' }[status] ?? 'neutral';
}

/** Status of a promotion list -> badge tone. */
export function promotionTone(status) {
    return { draft: 'neutral', pending_approval: 'warn', applied: 'ok', undone: 'neutral', cancelled: 'outline' }[status] ?? 'neutral';
}

/** How many students of a promotion list get each decision. */
export function decisionTotals(lines) {
    const totals = { promote: 0, repeat: 0, leave: 0, graduate: 0 };
    for (const line of lines ?? []) if (line.decision in totals) totals[line.decision]++;
    return totals;
}

/** Spreadsheet columns the importer knows (the same list as the server's). */
export const IMPORT_COLUMNS = [
    'name', 'name_local', 'gender', 'date_of_birth', 'birth_registration_no', 'phone', 'email', 'admitted_on', 'section',
    'guardian_name', 'guardian_phone', 'guardian_relation', 'guardian2_name', 'guardian2_phone', 'guardian2_relation',
];

/** Columns only people allowed to see private details may bring. */
export const PRIVATE_COLUMNS = ['date_of_birth', 'birth_registration_no'];

/**
 * The columns a reader may import: the known ones (private ones only when
 * allowed) and the institution's own student fields by their key.
 */
export function importColumns(fields, sensitive) {
    const known = IMPORT_COLUMNS.filter((key) => sensitive || !PRIVATE_COLUMNS.includes(key));
    return [...known, ...fields.filter((field) => sensitive || !field.is_sensitive).map((field) => field.key).filter((key) => !known.includes(key))];
}

/**
 * Rows read from a file, kept to the columns that may be imported (empty
 * cells left out), with the headings that were not used, so the screen can
 * say which columns it ignores.
 */
export function prepareImport(rows, columns) {
    const allowed = new Set(columns);
    const headings = [...new Set(rows.flatMap((row) => Object.keys(row)))];
    const unknown = headings.filter((heading) => !allowed.has(heading));
    const kept = rows.map((row) => Object.fromEntries(Object.entries(row).filter(([key, value]) => allowed.has(key) && value !== '')));
    return { rows: kept, unknown, hasName: headings.includes('name') };
}

/** A guardian form, with the student-link switches on. */
export function emptyGuardian(relation = 'father') {
    return { relation, name: '', phone: '', email: '', occupation: '', national_id: '', is_primary: false, can_pick_up: true, receives_notices: true, extra: {} };
}

/**
 * A guardian form to the API: trimmed, empty as null, the national id only
 * from people allowed to send it, own fields as API values.
 */
export function guardianToApi(guardian, fields, { sensitive = false, links = true } = {}) {
    const clean = (value) => String(value ?? '').trim() || null;
    return {
        relation: guardian.relation,
        name: clean(guardian.name),
        phone: clean(guardian.phone),
        email: clean(guardian.email),
        occupation: clean(guardian.occupation),
        ...(sensitive ? { national_id: clean(guardian.national_id) } : {}),
        is_primary: Boolean(guardian.is_primary),
        ...(links ? { can_pick_up: Boolean(guardian.can_pick_up), receives_notices: Boolean(guardian.receives_notices) } : {}),
        extra: fieldsToApi(fields, guardian.extra),
    };
}

/** A guardian kept on an application back into a form. */
export function guardianToForm(guardian, fields) {
    return { ...emptyGuardian(guardian.relation ?? 'guardian'), ...Object.fromEntries(['name', 'phone', 'email', 'occupation', 'national_id'].map((key) => [key, guardian[key] ?? ''])), is_primary: Boolean(guardian.is_primary), extra: fieldsToForm(fields, guardian.extra) };
}

/** A length in tenths of a millimetre as CSS ("856" -> "85.6mm"). */
export function mm(tenths) {
    const whole = Math.trunc(tenths / 10);
    const rest = Math.abs(tenths % 10);
    return `${whole}${rest ? `.${rest}` : ''}mm`;
}

/** A text size in tenths of a point as CSS ("95" -> "9.5pt"). */
export function pt(tenths) {
    return mm(tenths).replace('mm', 'pt');
}

/**
 * A design's text with its {placeholders} filled from values (unknown or
 * empty ones print nothing). Without values (designing) the text is shown
 * as written.
 */
export function fillText(text, values) {
    if (!values) return String(text ?? '');
    return String(text ?? '').replace(/\{([a-z_]+(?:\.[a-z0-9_]+)?)\}/g, (_, key) => values[key] ?? '');
}

/**
 * How many cards of a size fit on a sheet (all in tenths of a millimetre),
 * with a margin around the sheet and a gap between cards for cutting.
 */
export function sheetLayout(card, sheet = [2100, 2970], margin = 50, gap = 30) {
    const fit = (space, size) => Math.max(1, Math.floor((space - 2 * margin + gap) / (size + gap)));
    const columns = fit(sheet[0], card[0]);
    const rows = fit(sheet[1], card[1]);
    return { columns, rows, perSheet: columns * rows };
}

/** A list in pieces of a size. */
export function chunk(items, size) {
    const pieces = [];
    for (let index = 0; index < items.length; index += size) pieces.push(items.slice(index, index + size));
    return pieces;
}

/** Placeholders grouped for the designer's picker (the ones a design may use come from the server). */
export function placeholderGroups(keys) {
    const group = (key) => (key.startsWith('student.') ? 'student' : key.startsWith('guardian.') ? 'guardian' : key.startsWith('document.') || key.startsWith('institution.') ? 'document'
        : key.startsWith('field.') ? 'fields' : key.startsWith('input.') ? 'inputs' : 'class');
    const groups = {};
    for (const key of keys) (groups[group(key)] ??= []).push(key);
    return ['student', 'guardian', 'class', 'document', 'fields', 'inputs'].filter((name) => groups[name]).map((name) => ({ name, keys: groups[name] }));
}

/** A new item of a kind in the middle of a page (tenths of a millimetre), with a fresh id. */
export function newElement(type, page, taken = []) {
    const [width, height] = page;
    const size = { text: [Math.min(600, width - 40), 80], image: [200, 200], photo: [200, 240], qr: [200, 200], line: [Math.min(600, width - 40), 0], box: [400, 300] }[type];
    let index = 1;
    while (taken.includes(`${type}${index}`)) index++;
    const base = { id: `${type}${index}`, type, x: Math.round((width - size[0]) / 2), y: Math.round((height - size[1]) / 2), w: size[0], h: size[1] };
    return {
        text: { ...base, text: '{student.name}', size: 100, weight: 'normal', style: 'normal', align: 'start', font: 'sans', line_height: 130, color: '#000000' },
        image: { ...base, asset_id: '', fit: 'contain' },
        photo: { ...base, fit: 'cover', radius: 0 },
        qr: { ...base, color: '#000000' },
        line: { ...base, color: '#000000', thickness: 3 },
        box: { ...base, color: '#000000', thickness: 3, fill: null, radius: 0 },
    }[type];
}

/** An item kept inside its page after a move or resize (tenths of a millimetre). */
export function clampElement(element, page) {
    const [width, height] = page;
    const minimum = element.type === 'line' ? 0 : 10;
    const w = Math.max(element.type === 'line' && element.h > 0 ? 0 : minimum, Math.min(Math.round(element.w), width));
    const h = Math.max(element.type === 'line' && element.w > 0 ? 0 : minimum, Math.min(Math.round(element.h), height));
    return { ...element, w, h, x: Math.max(0, Math.min(Math.round(element.x), width - w)), y: Math.max(0, Math.min(Math.round(element.y), height - h)) };
}
