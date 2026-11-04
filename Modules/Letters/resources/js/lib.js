/**
 * Letters helpers kept free of the DOM, so they are tested on their own
 * (tests/js/letters.test.js).
 */

/** Paper sizes in millimetres (width, height). */
export const PAPER = { A4: [210, 297], Letter: [216, 279] };

export const BLOCK_TYPES = ['heading', 'paragraph', 'list', 'table', 'spacer', 'signature', 'page_break'];

const MARKS = [
    ['**', 'bold'],
    ['__', 'underline'],
    ['*', 'italic'],
];

/**
 * A line of letter text in pieces, each with its style: **bold**,
 * __underline__ and *italic*, nested as written. A mark without its pair
 * stays as typed, so nothing a person writes disappears. Never HTML.
 *
 * @returns {{ text: string, bold?: boolean, italic?: boolean, underline?: boolean }[]}
 */
export function segments(text, style = {}) {
    const out = [];
    let buffer = '';
    const flush = () => {
        if (buffer) out.push({ text: buffer, ...style });
        buffer = '';
    };
    let at = 0;
    while (at < text.length) {
        const mark = MARKS.find(([sign]) => text.startsWith(sign, at));
        if (mark) {
            const [sign, name] = mark;
            const close = text.indexOf(sign, at + sign.length);
            if (close > at + sign.length) {
                flush();
                out.push(...segments(text.slice(at + sign.length, close), { ...style, [name]: true }));
                at = close + sign.length;
                continue;
            }
        }
        buffer += text[at];
        at += 1;
    }
    flush();

    return out;
}

/** Text as lines of styled pieces (a line break in the text is a line on paper). */
export function lines(text) {
    return String(text ?? '').split('\n').map((line) => segments(line));
}

/** Wrap the chosen part of a text in a mark ("**" bold...), or put an empty pair at the cursor. */
export function wrapMark(text, start, end, sign) {
    const value = String(text ?? '');
    return { text: `${value.slice(0, start)}${sign}${value.slice(start, end)}${sign}${value.slice(end)}`, cursor: end + sign.length * (end > start ? 2 : 1) };
}

/** Put "{key}" in a text at the cursor (or over the chosen part). */
export function insertField(text, start, end, key) {
    const value = String(text ?? '');
    const field = `{${key}}`;
    return { text: `${value.slice(0, start)}${field}${value.slice(end)}`, cursor: start + field.length };
}

/** Every {placeholder} a block uses (its texts, items, rows; a table field). */
export function placeholdersOf(block) {
    const texts = [block.text, block.name, block.title, ...(block.items ?? []), ...(block.rows ?? []).flat()].filter((value) => typeof value === 'string');
    const found = new Set([...texts.join('\n').matchAll(/\{([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)\}/g)].map((match) => match[1]));
    if (block.type === 'table' && block.field) found.add(block.field);
    return [...found];
}

/** A new block of a type, ready to write in. */
export function newBlock(type) {
    return {
        heading: { type, text: '', level: 2, align: 'start' },
        paragraph: { type, text: '', align: 'start' },
        list: { type, ordered: false, items: [''] },
        table: { type, rows: [['', '']] },
        spacer: { type, size: 'md' },
        signature: { type, name: '', title: '', image_id: null, stamp_id: null, align: 'start' },
        page_break: { type },
    }[type];
}

/** A short line naming a block in the list of parts (its text, cut). */
export function blockSummary(block, max = 48) {
    const text = block.text ?? block.items?.[0] ?? block.name ?? block.title ?? block.field ?? block.rows?.[0]?.join(' · ') ?? '';
    const plain = String(text).replace(/\*\*|__|\*/g, '').replace(/\s+/g, ' ').trim();
    return plain.length > max ? `${plain.slice(0, max - 1).trimEnd()}…` : plain;
}

/**
 * The design's blocks with every placeholder filled from examples (the
 * editor's live preview, before saving): fields' samples, the questions'
 * labels in brackets; a table field shows one example row.
 *
 * @param {object[]} blocks
 * @param {{ group: string, fields: { key: string, label: string, sample: string, table?: boolean }[] }[]} groups
 * @param {{ key: string, label: Record<string, string> }[]} inputs
 */
export function fillSample(blocks, groups, inputs, labelOf = (label) => label?.en ?? '') {
    const values = {};
    for (const group of groups) {
        for (const field of group.fields) values[field.key] = field.table ? [[field.label, field.sample]] : field.sample;
    }
    for (const input of inputs) values[`input.${input.key}`] = `[${labelOf(input.label) || input.key}]`;
    const fill = (text) => String(text ?? '').replace(/\{([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)\}/g, (whole, key) => (typeof values[key] === 'string' ? values[key] : whole));

    return blocks.map((block) => {
        if (block.type === 'table' && block.field) return { type: 'table', rows: Array.isArray(values[block.field]) ? values[block.field] : [[block.field, '']] };
        const copy = { ...block };
        for (const key of ['text', 'name', 'title']) if (typeof copy[key] === 'string') copy[key] = fill(copy[key]);
        if (copy.items) copy.items = copy.items.map(fill);
        if (copy.rows) copy.rows = copy.rows.map(([label, value]) => [fill(label), fill(value)]);
        return copy;
    });
}

/** Placeholders a design uses that are not known here (a typo, or a salary field the reader may not use). */
export function unknownPlaceholders(blocks, groups, inputs) {
    const known = new Set([...groups.flatMap((group) => group.fields.map((field) => field.key)), ...inputs.filter((input) => input.key).map((input) => `input.${input.key}`)]);
    return [...new Set(blocks.flatMap(placeholdersOf))].filter((key) => !known.has(key));
}

/** A question's key made from its English label ("Reply by" -> "reply_by"). */
export function keyFrom(label) {
    const key = String(label ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^[^a-z]+/, '').slice(0, 30);
    return key.length >= 2 ? key : '';
}

/** What is still missing to issue: required questions not answered. */
export function missingAnswers(inputs, answers) {
    return inputs.filter((input) => input.required && !String(answers[input.key] ?? '').trim()).map((input) => input.key);
}

/** The language a letter is written in by default: the reader's, when the design has it; else English. */
export function defaultLocale(locales, mine) {
    return locales.includes(mine) ? mine : locales.includes('en') ? 'en' : (locales[0] ?? 'en');
}

/** Badge tones: a design by its status, a letter by whether it was revoked. */
export const templateTone = (status) => ({ active: 'ok', draft: 'neutral', retired: 'outline' })[status] ?? 'neutral';
export const letterTone = (letter) => (letter.revoked ? 'bad' : 'ok');
