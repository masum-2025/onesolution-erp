import { describe, expect, it } from 'vitest';
import {
    blockSummary, defaultLocale, fillSample, insertField, keyFrom, lines, missingAnswers, newBlock, placeholdersOf, segments, unknownPlaceholders, wrapMark,
} from '../../Modules/Letters/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Letters/resources/js/locales/en/letters.json';
import bn from '../../Modules/Letters/resources/js/locales/bn/letters.json';

/** Every dotted key of a locale file. */
function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

const groups = [
    { group: 'employee', label: 'Employee', fields: [{ key: 'employee.name', label: 'Name', sample: 'Rahima Akter', salary: false }] },
    { group: 'pay', label: 'Pay', fields: [{ key: 'pay.gross', label: 'Gross', sample: 'BDT 48,000.00', salary: true }, { key: 'pay.breakdown', label: 'Breakdown', sample: 'Basic…', salary: true, table: true }] },
];

describe('Letters screens', () => {
    it('registers its screens with the app shell, the editor full screen and the portal ones marked', () => {
        const routes = moduleRoutes.filter((route) => route.meta.module === 'letters');
        expect(routes.map((route) => route.name)).toEqual(
            expect.arrayContaining(['letters', 'letters-letter', 'letters-templates', 'letters-editor', 'letters-me', 'letters-my-letter', 'letters-portal', 'letters-portal-letter']),
        );
        expect(routes.find((route) => route.name === 'letters-editor').meta.focus).toBe(true);
        expect(routes.find((route) => route.name === 'letters-portal').meta.portal).toBe(true);
    });

    it('has the same texts in Bangla and English', () => {
        expect(keys(bn).sort()).toEqual(keys(en).sort());
    });

    it('reads bold, italic and underline marks, nested, and keeps an unpaired mark as typed', () => {
        expect(segments('Dear **Rahima**,')).toEqual([{ text: 'Dear ' }, { text: 'Rahima', bold: true }, { text: ',' }]);
        expect(segments('**bold *and italic* here**')).toEqual([{ text: 'bold ', bold: true }, { text: 'and italic', bold: true, italic: true }, { text: ' here', bold: true }]);
        expect(segments('__under__ and *it*')).toEqual([{ text: 'under', underline: true }, { text: ' and ' }, { text: 'it', italic: true }]);
        expect(segments('5 * 3 = 15')).toEqual([{ text: '5 * 3 = 15' }]);
        expect(segments('a ** b')).toEqual([{ text: 'a ** b' }]);
        expect(segments('<b>x</b>')).toEqual([{ text: '<b>x</b>' }]);
        expect(lines('one\n**two**')).toEqual([[{ text: 'one' }], [{ text: 'two', bold: true }]]);
    });

    it('wraps the chosen words in a mark and puts fields in at the cursor', () => {
        expect(wrapMark('Dear Rahima', 5, 11, '**')).toEqual({ text: 'Dear **Rahima**', cursor: 15 });
        expect(wrapMark('Dear ', 5, 5, '*')).toEqual({ text: 'Dear **', cursor: 6 });
        expect(insertField('Dear ,', 5, 5, 'employee.name')).toEqual({ text: 'Dear {employee.name},', cursor: 20 });
        expect(insertField('Dear X,', 5, 6, 'employee.name').text).toBe('Dear {employee.name},');
    });

    it('fills a design with examples for the live preview and finds fields it does not know', () => {
        const blocks = [
            { type: 'paragraph', text: 'Dear {employee.name}, from {input.when}: {pay.gross}' },
            { type: 'table', field: 'pay.breakdown' },
            { type: 'list', ordered: false, items: ['{employee.nmae}'] },
        ];
        const inputs = [{ key: 'when', label: { en: 'Joining date' } }];
        const filled = fillSample(blocks, groups, inputs);
        expect(filled[0].text).toBe('Dear Rahima Akter, from [Joining date]: BDT 48,000.00');
        expect(filled[1]).toEqual({ type: 'table', rows: [['Breakdown', 'Basic…']] });
        expect(filled[2].items[0]).toBe('{employee.nmae}');
        expect(unknownPlaceholders(blocks, groups, inputs)).toEqual(['employee.nmae']);
        // Without the pay group (no salary permission) its fields are unknown.
        expect(unknownPlaceholders(blocks, [groups[0]], inputs)).toEqual(['pay.gross', 'pay.breakdown', 'employee.nmae']);
        expect(placeholdersOf({ type: 'signature', name: '{employee.name}', title: 'HR, {company.name}' })).toEqual(['employee.name', 'company.name']);
    });

    it('makes new parts, names them in the list, keys questions and checks the answers', () => {
        expect(newBlock('list')).toEqual({ type: 'list', ordered: false, items: [''] });
        expect(newBlock('signature')).toMatchObject({ type: 'signature', image_id: null, stamp_id: null });
        expect(blockSummary({ type: 'paragraph', text: '**Dear** {employee.name}, we are pleased to appoint you as our new senior accountant' }, 30))
            .toBe('Dear {employee.name}, we are…');
        expect(blockSummary({ type: 'table', field: 'pay.breakdown' })).toBe('pay.breakdown');
        expect(keyFrom('Reply by')).toBe('reply_by');
        expect(keyFrom('1st day')).toBe('st_day');
        expect(keyFrom('কবে')).toBe('');
        const inputs = [{ key: 'when', required: true }, { key: 'note', required: false }];
        expect(missingAnswers(inputs, { when: '  ', note: '' })).toEqual(['when']);
        expect(missingAnswers(inputs, { when: '2027-01-01' })).toEqual([]);
        expect(defaultLocale(['en', 'bn'], 'bn')).toBe('bn');
        expect(defaultLocale(['en'], 'bn')).toBe('en');
    });
});
