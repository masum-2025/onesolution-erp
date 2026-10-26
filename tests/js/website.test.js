import { beforeAll, describe, expect, it } from 'vitest';
import { i18n } from '../../resources/js/lib/i18n';
import { BLOCKS, BLOCK_TYPES, blankItem, blockBody, blockForm, blockSummary, carriesWhiteText, COLOUR_SETS, contrast, sizeParts, slugFrom } from '../../Modules/EduWebsite/resources/js/lib';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/EduWebsite/resources/js/locales/en/edu_website.json';
import bn from '../../Modules/EduWebsite/resources/js/locales/bn/edu_website.json';

const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`])).sort();

describe('website screens', () => {
    it('registers its screens with the app shell', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'edu_website').map((route) => route.name);
        expect(names).toEqual(['website', 'website-settings', 'website-pages', 'website-page', 'website-notices', 'website-media', 'website-enquiries']);
    });

    it('says everything in Bangla and English, a name and a line for every kind of block and field', () => {
        expect(keys(bn)).toEqual(keys(en));
        for (const type of BLOCK_TYPES) {
            expect(en.blocks[type]).toBeTruthy();
            expect(en.blocks[`${type}_text`]).toBeTruthy();
            for (const field of BLOCKS[type].fields) {
                expect(en.fields[field.label ?? field.key], `${type}.${field.key}`).toBeTruthy();
                for (const inner of field.fields ?? []) expect(en.fields[inner.label ?? inner.key], `${type}.${field.key}.${inner.key}`).toBeTruthy();
            }
        }
    });

    it('knows the same kinds of block as the server', () => {
        expect(BLOCK_TYPES).toEqual(['hero', 'slider', 'text', 'notices', 'admission', 'enquiry', 'programs', 'people', 'message', 'calendar', 'gallery', 'stats', 'downloads', 'contact', 'attendance']);
    });
});

describe('block forms', () => {
    // The app speaks both; forms carry a text per language it speaks.
    beforeAll(() => {
        i18n.locales = ['en', 'bn'];
    });

    it('starts a block with sensible values', () => {
        expect(blockForm('notices').count).toBe(5);
        expect(blockForm('contact').show_map).toBe(true);
        expect(blockForm('programs').source).toBe('education');
        expect(blockForm('gallery').items).toEqual([]);
        expect(blockForm('gallery').layout).toBe('grid');
        expect(blockForm('slider')).toMatchObject({ autoplay: true, interval: 5, effect: 'fade', height: 'normal', slides: [] });
        expect(blankItem(BLOCKS.people.fields[1].fields)).toMatchObject({ name: '', image_media_id: '' });
    });

    it('sends trimmed texts, leaves out empty ones and hidden fields', () => {
        const form = blockForm('hero', { title: { en: ' Welcome ', bn: 'স্বাগতম' }, button_url: '' });
        expect(blockBody('hero', form)).toEqual({ title: { en: 'Welcome', bn: 'স্বাগতম' }, text: null, image_media_id: null, button_label: null, button_url: null });

        const programs = blockForm('programs', { source: 'education', items: [{ name: { en: 'Class 6' } }] });
        expect(blockBody('programs', programs)).not.toHaveProperty('items');
        programs.source = 'manual';
        expect(blockBody('programs', programs).items).toEqual([{ name: { en: 'Class 6' }, text: null }]);
    });

    it('keeps people names as plain text and numbers as numbers', () => {
        const people = blockForm('people', { items: [{ name: '  Rahima Khatun ', role: { en: 'Head teacher' } }] });
        expect(blockBody('people', people).items[0]).toEqual({ name: 'Rahima Khatun', role: { en: 'Head teacher' }, image_media_id: null });
        const notices = blockForm('notices');
        notices.count = '8';
        expect(blockBody('notices', notices).count).toBe(8);
    });

    it('opens a gallery made before captions as pictures with empty captions', () => {
        const form = blockForm('gallery', { title: { en: 'Sports day' }, media_ids: ['a'.repeat(26), 'b'.repeat(26)] });
        expect(form.items.map((item) => item.image_media_id)).toEqual(['a'.repeat(26), 'b'.repeat(26)]);
        const body = blockBody('gallery', form);
        expect(body).not.toHaveProperty('media_ids');
        expect(body.items[0]).toEqual({ image_media_id: 'a'.repeat(26), caption: null });
        expect(body.layout).toBe('grid');
    });

    it('sends slides and numbers as the server checks them', () => {
        const slider = blockForm('slider', { slides: [{ image_media_id: 'a'.repeat(26), title: { en: 'Welcome' } }], autoplay: false });
        const body = blockBody('slider', slider);
        expect(body.slides[0]).toEqual({ image_media_id: 'a'.repeat(26), title: { en: 'Welcome' }, text: null, button_label: null, button_url: null });
        expect(body).not.toHaveProperty('interval');
        expect(body.autoplay).toBe(false);

        const stats = blockForm('stats', { items: [{ value: 1200, suffix: '+', label: { en: 'Students' } }] });
        stats.items[0].value = '1250';
        expect(blockBody('stats', stats).items).toEqual([{ value: 1250, suffix: '+', label: { en: 'Students' } }]);
        expect(blockSummary({ data: { slides: [{ title: { en: 'A' } }, { title: { en: 'B' } }] } }, (texts) => texts?.en ?? '')).toBe('A · B');
    });

    it('sums up a block by its title, else its text or people', () => {
        const pick = (texts) => texts?.en ?? '';
        expect(blockSummary({ data: { title: { en: 'About us' } } }, pick)).toBe('About us');
        expect(blockSummary({ data: { body: { en: 'Since 1972.' } } }, pick)).toBe('Since 1972.');
        expect(blockSummary({ data: { items: [{ name: 'A' }, { name: { en: 'B' } }] } }, pick)).toBe('A, B');
        expect(blockSummary({ data: {} }, pick)).toBe('');
    });
});

describe('look and addresses', () => {
    it('checks that white text can be read on the main colour', () => {
        expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 5);
        expect(carriesWhiteText('#0f6b4b')).toBe(true);
        expect(carriesWhiteText('#ffd54f')).toBe(false);
        expect(carriesWhiteText('nonsense')).toBe(false);
        for (const set of COLOUR_SETS) expect(carriesWhiteText(set.primary), set.key).toBe(true);
    });

    it('makes a page address from a title', () => {
        expect(slugFrom('About Us!')).toBe('about-us');
        expect(slugFrom('  Class 6 – Results  ')).toBe('class-6-results');
        expect(slugFrom('আমাদের কথা')).toBe('');
    });

    it('writes file sizes for people', () => {
        expect(sizeParts(300)).toEqual({ value: 1, unit: 'kb' });
        expect(sizeParts(250 * 1024)).toEqual({ value: 250, unit: 'kb' });
        expect(sizeParts(2.5 * 1024 * 1024)).toEqual({ value: 2.5, unit: 'mb' });
    });
});
