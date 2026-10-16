import { describe, expect, it } from 'vitest';
import { acceptList, looksLikeVideo, sizeText, statusTone } from '../../Modules/EducationLectures/resources/js/lib';

describe('class lectures', () => {
    it('takes only https links before the server checks the site', () => {
        expect(looksLikeVideo('')).toBe(true);
        expect(looksLikeVideo('https://youtu.be/abc')).toBe(true);
        expect(looksLikeVideo('http://youtu.be/abc')).toBe(false);
        expect(looksLikeVideo('youtube.com/watch')).toBe(false);
        expect(looksLikeVideo('javascript:alert(1)')).toBe(false);
    });

    it('writes file sizes people read', () => {
        expect(sizeText(200)).toBe('1 KB');
        expect(sizeText(820 * 1024)).toBe('820 KB');
        expect(sizeText(2.44 * 1024 * 1024)).toBe('2.4 MB');
        expect(sizeText(820 * 1024, (value) => `#${value}`)).toBe('#820 KB');
    });

    it('offers the file types the server takes, and a tone for each status', () => {
        expect(acceptList(['pdf', 'docx'])).toBe('.pdf,.docx');
        expect(acceptList(undefined)).toBe('');
        expect(['draft', 'waiting', 'published', 'withdrawn'].map(statusTone)).toEqual(['neutral', 'warn', 'ok', 'bad']);
    });
});
