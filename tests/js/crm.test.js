import { describe, expect, it } from 'vitest';
import { amountToMinor, fieldsToApi, fieldText, phoneText, priceQuote, quantityToMilli, quoteTone, readCsv, splitTax } from '../../Modules/Crm/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Crm/resources/js/locales/en/crm.json';
import bn from '../../Modules/Crm/resources/js/locales/bn/crm.json';

function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('CRM screens', () => {
    it('registers its screens with the app shell', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'crm').map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['crm-contacts', 'crm-contact', 'crm-deals', 'crm-tasks', 'crm-quotes', 'crm-quote', 'crm-quote-new', 'crm-quote-edit', 'crm-pipelines', 'crm-fields']));
    });

    it('prices a quote exactly as the server does (the same case as CrmTest)', () => {
        // 10 chairs at 6,500 less 5,000 discount, 15% VAT on top; fitting 2,000 without VAT.
        const priced = priceQuote([
            { quantity_milli: 10000, unit_price_minor: 650000, discount_minor: 500000, tax_rate_bp: 1500 },
            { quantity_milli: 1000, unit_price_minor: 200000, discount_minor: 0, tax_rate_bp: 0 },
        ], false);
        expect(priced).toMatchObject({ subtotal: 6700000, discount: 500000, tax: 900000, total: 7100000 });
        expect(splitTax(11500, 1500, true)).toEqual({ net: 10000, tax: 1500 });
        expect(priceQuote([{ quantity_milli: 1500, unit_price_minor: 333, discount_minor: 0, tax_rate_bp: 0 }], false).total).toBe(500);
        expect(amountToMinor('১,২৫০.৫০', 'BDT')).toBe(125050);
        expect(quantityToMilli('2.5')).toBe(2500);
        expect(quantityToMilli('0')).toBeNull();
    });

    it('turns extra-field inputs into API values and shows them back', () => {
        const fields = [
            { key: 'delivery', type: 'money' }, { key: 'urgent', type: 'yes_no' }, { key: 'warranty', type: 'number' },
            { key: 'colour', type: 'choice', options: [{ value: 'red', label: 'Red' }] }, { key: 'note', type: 'text' },
        ];
        expect(fieldsToApi(fields, { delivery: '1,500', urgent: 'yes', warranty: '১২', colour: 'red', note: '  ' }, 'BDT'))
            .toEqual({ values: { delivery: 150000, urgent: true, warranty: '12', colour: 'red', note: null }, errors: {} });
        expect(fieldsToApi(fields, { delivery: 'a lot' }, 'BDT').errors).toEqual({ delivery: true });
        expect(fieldText(fields[3], 'red', String)).toBe('Red');
        expect(fieldText(fields[1], false, String)).toBe('✗');
    });

    it('reads a CSV of contacts with quotes, semicolons and a byte order mark', () => {
        expect(readCsv('﻿Name;Phone;Tags\r\n"Rahim, Jr.";01711000000;vip\r\n\r\nAyesha;01811000000;"a;b"\n')).toEqual([
            { name: 'Rahim, Jr.', phone: '01711000000', tags: 'vip' },
            { name: 'Ayesha', phone: '01811000000', tags: 'a;b' },
        ]);
        expect(readCsv('name,company name\nX,"Say ""hi"""')).toEqual([{ name: 'X', company_name: 'Say "hi"' }]);
    });

    it('shows phones and statuses, and has the same texts in both languages', () => {
        expect(phoneText('+8801711000000')).toBe('01711-000000');
        expect(phoneText('+966501234567')).toBe('+966501234567');
        expect(quoteTone({ status: 'sent', expired: true })).toBe('warn');
        expect(quoteTone({ status: 'accepted' })).toBe('ok');
        expect(keys(bn).sort()).toEqual(keys(en).sort());
    });
});
