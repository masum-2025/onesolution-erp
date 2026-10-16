import { describe, expect, it } from 'vitest';
import { allAnswered, roundTone, scoreTone, starsText } from '../../Modules/TeacherFeedback/resources/js/lib';

describe('teacher feedback', () => {
    it('writes averages in hundredths as one decimal, and nothing when hidden', () => {
        expect(starsText(400)).toBe('4.0');
        expect(starsText(433)).toBe('4.3');
        expect(starsText(467)).toBe('4.7');
        expect(starsText(null)).toBe('—');
        expect(starsText(250, (value) => `#${value}`)).toBe('#2.5');
    });

    it('gives a tone by the average and the round status', () => {
        expect([450, 400, 350, 299, null].map(scoreTone)).toEqual(['ok', 'ok', 'warn', 'bad', 'neutral']);
        expect(['draft', 'open', 'closed'].map(roundTone)).toEqual(['neutral', 'ok', 'outline']);
    });

    it('sends only when every question has one to five stars', () => {
        const questions = [{ id: 'a' }, { id: 'b' }];
        expect(allAnswered(questions, { a: 5, b: 1 })).toBe(true);
        expect(allAnswered(questions, { a: 5 })).toBe(false);
        expect(allAnswered(questions, { a: 5, b: 6 })).toBe(false);
        expect(allAnswered([], {})).toBe(false);
    });
});
