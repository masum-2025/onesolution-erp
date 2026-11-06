/**
 * Helpers for a section's form, which is drawn from the server's field
 * descriptions (BlockTypes::fields): a blank value for a field, and the
 * data as sent (empty texts left out, so optional parts stay optional).
 */

/** The swatch shown for each colour name (the site's stylesheet has its own). */
export const SWATCHES = { blue: '#2563eb', green: '#10b981', red: '#f43f5e', violet: '#7c3aed', sky: '#0ea5e9', amber: '#f59e0b', navy: '#1e3a8a' };

export function blankFor(field) {
    switch (field.kind) {
        case 'text':
        case 'map':
            return {};
        case 'object':
            return Object.fromEntries(field.fields.map((child) => [child.key, blankFor(child)]));
        case 'list':
            return Array.from({ length: field.min ?? 0 }, () => blankFor(field.item));
        case 'choice':
            return field.required ? field.options[0] : null;
        case 'boolean':
            return field.key === 'autoplay';
        case 'integer':
            return field.required ? (field.min ?? 0) : null;
        default:
            return '';
    }
}

/** A new section's data: every field blank, required lists with their first item. */
export function blankData(fields) {
    return blankFor({ kind: 'object', fields });
}

/** Fills fields missing from saved data (a field added later), keeping everything saved. */
export function withDefaults(fields, data) {
    const value = JSON.parse(JSON.stringify(data ?? {}));
    for (const field of fields) {
        if (value[field.key] === undefined) value[field.key] = blankFor(field);
    }
    return value;
}

export function cleanData(value) {
    if (Array.isArray(value)) return value.map(cleanData);
    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, cleanData(item)]).filter(([, item]) => item !== null));
    }
    return typeof value === 'string' ? value.trim() || null : value;
}

/** A short line naming a section in the list: its first title-like text. */
export function summaryOf(data, pick) {
    const first = (list) => (Array.isArray(list) && list.length ? list[0] : null);
    const candidates = [data?.title, first(data?.slides)?.title, data?.body, first(data?.items)?.title, first(data?.items)?.question, first(data?.items)?.label];
    for (const texts of candidates) {
        const text = texts && typeof texts === 'object' ? pick(texts) : '';
        if (text) return text.length > 80 ? `${text.slice(0, 80)}…` : text;
    }
    return '';
}
