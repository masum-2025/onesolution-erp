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
