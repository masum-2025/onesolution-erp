import { cleanTexts, textsFor } from '@/lib/texts';

/**
 * The kinds of block a page is built from, as the forms show them. Each
 * field: `text` (one per language; `long` for several lines), `name`
 * (one plain text), `number`, `date`, `link`, `switch`, `choice`, `image`
 * (one picture of the site), `media` (several pictures or PDFs) and
 * `items` (a repeated group). `label` names the field when its key alone
 * does not (edu_website.fields.<label or key>). The server checks the same limits again
 * (BlockTypes); these only shape the form.
 */
const title = { key: 'title', kind: 'text', max: 120 };

export const BLOCKS = {
    hero: {
        icon: 'sparkles',
        fields: [
            { ...title, required: true },
            { key: 'text', kind: 'text', max: 300, long: true },
            { key: 'image_media_id', kind: 'image' },
            { key: 'button_label', kind: 'text', max: 40 },
            { key: 'button_url', kind: 'link' },
        ],
    },
    slider: {
        icon: 'slides',
        fields: [
            {
                key: 'slides',
                kind: 'items',
                min: 1,
                max: 8,
                // Several pictures picked at once become one slide each.
                bulk: 'image_media_id',
                fields: [
                    { key: 'image_media_id', kind: 'image', required: true },
                    { key: 'title', kind: 'text', max: 120 },
                    { key: 'text', kind: 'text', max: 240 },
                    { key: 'button_label', kind: 'text', max: 40 },
                    { key: 'button_url', kind: 'link' },
                ],
            },
            { key: 'autoplay', kind: 'switch', default: true },
            { key: 'interval', kind: 'number', min: 3, max: 10, default: 5, when: (data) => data.autoplay },
            { key: 'effect', kind: 'choice', options: ['fade', 'slide'], default: 'fade' },
            { key: 'height', kind: 'choice', options: ['normal', 'full'], default: 'normal' },
        ],
    },
    text: { icon: 'text', fields: [title, { key: 'body', kind: 'text', max: 5000, long: true, required: true }] },
    notices: { icon: 'bell', fields: [title, { key: 'count', kind: 'number', min: 1, max: 20, default: 5 }] },
    admission: {
        icon: 'door',
        fields: [
            title,
            { key: 'body', kind: 'text', max: 3000, long: true, required: true },
            { key: 'deadline', kind: 'date' },
            { key: 'button_label', kind: 'text', max: 40 },
            { key: 'button_url', kind: 'link' },
        ],
    },
    programs: {
        icon: 'book',
        fields: [
            title,
            { key: 'source', kind: 'choice', options: ['education', 'manual'], default: 'education' },
            {
                key: 'items',
                label: 'programs_list',
                kind: 'items',
                max: 40,
                when: (data) => data.source === 'manual',
                fields: [
                    { key: 'name', kind: 'text', max: 100, required: true },
                    { key: 'text', kind: 'text', max: 300 },
                ],
            },
        ],
    },
    people: {
        icon: 'users',
        fields: [
            title,
            {
                key: 'items',
                label: 'people_list',
                kind: 'items',
                min: 1,
                max: 60,
                fields: [
                    { key: 'name', kind: 'name', max: 100, required: true },
                    { key: 'role', kind: 'text', max: 100 },
                    { key: 'image_media_id', kind: 'image' },
                ],
            },
        ],
    },
    gallery: {
        icon: 'images',
        fields: [
            title,
            { key: 'layout', kind: 'choice', options: ['grid', 'masonry', 'carousel'], default: 'grid' },
            {
                key: 'items',
                label: 'pictures',
                kind: 'items',
                min: 1,
                max: 40,
                bulk: 'image_media_id',
                fields: [
                    { key: 'image_media_id', kind: 'image', required: true },
                    { key: 'caption', kind: 'text', max: 150 },
                ],
            },
        ],
        // A gallery made before captions kept the picture ids alone.
        upgrade: (data) => (data?.items || !data?.media_ids ? data : { ...data, items: data.media_ids.map((id) => ({ image_media_id: id })) }),
    },
    stats: {
        icon: 'trend',
        fields: [
            title,
            {
                key: 'items',
                label: 'numbers',
                kind: 'items',
                min: 1,
                max: 6,
                fields: [
                    { key: 'value', kind: 'number', min: 0, max: 1000000000, required: true },
                    { key: 'suffix', kind: 'name', max: 4 },
                    { key: 'label', label: 'counts', kind: 'text', max: 60, required: true },
                ],
            },
        ],
    },
    downloads: { icon: 'file', fields: [title, { key: 'media_ids', label: 'files', kind: 'media', media: 'pdf', min: 1, max: 30 }] },
    contact: { icon: 'phone', fields: [title, { key: 'show_map', kind: 'switch', default: true }] },
    attendance: {
        icon: 'chart',
        fields: [title, { key: 'students', kind: 'switch', default: true }, { key: 'staff', kind: 'switch', default: true }],
    },
};

export const BLOCK_TYPES = Object.keys(BLOCKS);

function blankField(field) {
    if (field.kind === 'text') return textsFor({});
    if (field.kind === 'items') return [];
    if (field.kind === 'media') return [];
    if (field.kind === 'switch') return field.default ?? false;
    if (field.kind === 'number' || field.kind === 'choice') return field.default ?? null;
    return '';
}

function formField(field, value) {
    if (field.kind === 'text') return textsFor(value ?? {});
    if (field.kind === 'items') return (value ?? []).map((item) => formOf(field.fields, item));
    if (field.kind === 'media') return [...(value ?? [])];
    if (field.kind === 'switch') return value ?? field.default ?? false;
    if (value === undefined || value === null) return blankField(field);
    return value;
}

function formOf(fields, data = {}) {
    return Object.fromEntries(fields.map((field) => [field.key, formField(field, data?.[field.key])]));
}

/** An empty form of a group of fields (a new item of a repeated group). */
export function blankItem(fields) {
    return Object.fromEntries(fields.map((field) => [field.key, blankField(field)]));
}

/** The form of a block from its data (a new block when data is empty). */
export function blockForm(type, data = {}) {
    const kind = BLOCKS[type];

    return formOf(kind.fields, kind.upgrade ? kind.upgrade(data) : data);
}

function bodyOf(fields, form) {
    const body = {};
    for (const field of fields) {
        if (field.when && !field.when(form)) continue;
        const value = form[field.key];
        if (field.kind === 'text') {
            const texts = cleanTexts(value);
            body[field.key] = Object.keys(texts).length ? texts : null;
        } else if (field.kind === 'items') {
            body[field.key] = value.map((item) => bodyOf(field.fields, item));
        } else if (field.kind === 'name' || field.kind === 'link' || field.kind === 'date' || field.kind === 'image') {
            const text = String(value ?? '').trim();
            body[field.key] = text === '' ? null : text;
        } else if (field.kind === 'number') {
            body[field.key] = value === '' || value === null ? null : Number(value);
        } else {
            body[field.key] = value;
        }
    }

    return body;
}

/** What the API takes for a block: texts trimmed, empty ones left out, hidden fields not sent. */
export function blockBody(type, form) {
    return bodyOf(BLOCKS[type].fields, form);
}

/** The short line under a block in the list: its title in the language shown, or nothing. */
export function blockSummary(block, pick) {
    const data = block.data ?? {};
    const text = pick(data.title) || pick(data.text) || pick(data.body);
    if (text) return text;
    if (Array.isArray(data.slides)) return data.slides.map((slide) => pick(slide.title)).filter(Boolean).join(' · ');
    if (Array.isArray(data.items)) return data.items.map((item) => (typeof item.name === 'string' ? item.name : pick(item.name))).filter(Boolean).join(', ');

    return '';
}

/** Colour pairs (main, accent) that suit both templates; every main colour carries white text. */
export const COLOUR_SETS = [
    { key: 'green', primary: '#0f6b4b', accent: '#f5b301' },
    { key: 'blue', primary: '#1d4ed8', accent: '#f59e0b' },
    { key: 'maroon', primary: '#8a1c2b', accent: '#e8b04a' },
    { key: 'teal', primary: '#0e7490', accent: '#f97316' },
    { key: 'purple', primary: '#5b21b6', accent: '#f472b6' },
    { key: 'navy', primary: '#1e3a5f', accent: '#22c55e' },
    { key: 'royal', primary: '#1f3a68', accent: '#c9a227' },
];

function luminance(hex) {
    const channels = [1, 3, 5].map((start) => parseInt(hex.slice(start, start + 2), 16) / 255);
    const [r, g, b] = channels.map((value) => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Contrast of two #rrggbb colours (1 to 21). */
export function contrast(first, second) {
    const [light, dark] = [luminance(first), luminance(second)].sort((a, b) => b - a);

    return (light + 0.05) / (dark + 0.05);
}

/** White text on this colour can be read by most people (WCAG AA, 4.5:1). */
export function carriesWhiteText(hex) {
    return /^#[0-9a-f]{6}$/i.test(hex) && contrast(hex, '#ffffff') >= 4.5;
}

/** A page address from a title: small letters, digits and hyphens. */
export function slugFrom(text) {
    return String(text ?? '')
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);
}

/** File size for people: KB below a megabyte, else MB with one decimal (whole numbers only in the maths). */
export function sizeParts(bytes) {
    if (bytes < 1024 * 1024) return { value: Math.max(1, Math.round(bytes / 1024)), unit: 'kb' };

    return { value: Math.round((bytes * 10) / (1024 * 1024)) / 10, unit: 'mb' };
}
