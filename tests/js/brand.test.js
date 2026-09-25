import { describe, expect, it } from 'vitest';
import { applyBrand, brand, contrast, readableOn } from '@/lib/brand';

describe('partner brand at runtime', () => {
    it('picks readable text for any brand color', () => {
        expect(readableOn('#4F46E5')).toBe('#FFFFFF');
        expect(readableOn('#FACC15')).toBe('#111114');
        expect(contrast('#FFFFFF', '#000000')).toBeCloseTo(21, 0);
    });

    it('applies the brand as CSS variables', () => {
        applyBrand({ name: 'Acme ERP', primary_color: '#0F766E' });

        const style = document.documentElement.style;
        expect(style.getPropertyValue('--brand')).toBe('#0F766E');
        expect(style.getPropertyValue('--brand-fg')).toBe('#FFFFFF');
        expect(document.title).toBe('Acme ERP');
    });

    it('refuses anything that is not a plain hex color', () => {
        applyBrand({ name: 'Bad', primary_color: 'red;}</style><script>' });

        expect(brand.primary_color).toBe('#2B4C9B');
        expect(document.documentElement.style.getPropertyValue('--brand')).toBe('#2B4C9B');
    });
});
