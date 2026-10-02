import { afterEach, describe, expect, it } from 'vitest';
import { ACCENTS, accentColor, allowedChoices, appearance, applyAppearance, isLocked } from '@/lib/appearance';
import { applyBrand, contrast, contrastProblem } from '@/lib/brand';

function serverLook(extra = {}) {
    const choice = (value, more = {}) => ({ value, source: 'default', level: null, name: null, locked: false, own: null, allowed: [], ...more });
    return {
        template: choice('classic', { allowed: ['classic', 'light'] }),
        accent: choice('brand', { allowed: ['brand', ...Object.keys(ACCENTS)] }),
        color_vision: 'standard',
        contrast: 'standard',
        ...extra,
    };
}

afterEach(() => {
    applyBrand({ primary_color: '#2B4C9B' });
    applyAppearance(serverLook());
    window.localStorage.clear();
});

describe('highlight colors', () => {
    it.each(Object.entries(ACCENTS))('%s carries white text and shows on a white page', (name, color) => {
        expect(contrastProblem(color)).toBeNull();
        expect(contrast(color, '#FFFFFF')).toBeGreaterThanOrEqual(4.5);
    });

    it('uses the brand color for "brand"', () => {
        applyBrand({ primary_color: '#0E7490' });
        expect(accentColor('brand')).toBe('#0E7490');
    });
});

describe('applyAppearance', () => {
    it('puts the look on the page', () => {
        applyAppearance(serverLook({ template: { value: 'light', locked: false, allowed: ['classic', 'light'] }, color_vision: 'blue_orange', contrast: 'high' }));

        const root = document.documentElement;
        expect(root.dataset.shell).toBe('light');
        expect(root.dataset.vision).toBe('blue_orange');
        expect(root.dataset.contrast).toBe('high');
        expect(window.localStorage.getItem('os.shell')).toBe('light');
        expect(window.localStorage.getItem('os.vision')).toBe('blue_orange');
    });

    it('replaces the brand color with a picked highlight color', () => {
        applyAppearance(serverLook({ accent: { value: 'teal', locked: false, allowed: ['brand', 'teal'] } }));

        expect(document.documentElement.style.getPropertyValue('--brand')).toBe(ACCENTS.teal);
        expect(document.documentElement.style.getPropertyValue('--brand-fg')).toBe('#FFFFFF');
    });

    it('falls back to classic for a template it does not know', () => {
        applyAppearance(serverLook({ template: { value: 'neon', locked: false, allowed: [] } }));
        expect(appearance.template).toBe('classic');
    });

    it('knows what the organization locked and what may be picked', () => {
        applyAppearance(serverLook({ template: { value: 'classic', locked: true, level: 'company', name: 'C1', allowed: ['classic'] } }));

        expect(isLocked('template')).toBe(true);
        expect(isLocked('accent')).toBe(false);
        expect(allowedChoices('template')).toEqual(['classic']);
    });

    it('keeps the server details while a choice is being tried', () => {
        applyAppearance(serverLook());
        applyAppearance({ ...appearance, details: undefined, accent: 'rose' });

        expect(appearance.accent).toBe('rose');
        expect(appearance.details.template.allowed).toEqual(['classic', 'light']);
    });
});
