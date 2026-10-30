import { describe, expect, it } from 'vitest';
import { counts, expiryFrom, grid, mailtoLink, renewalMark, stateTone } from '../../Modules/Compliance/resources/js/lib';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Compliance/resources/js/locales/en/compliance.json';
import bn from '../../Modules/Compliance/resources/js/locales/bn/compliance.json';

const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`])).sort();

describe('compliance screens', () => {
    it('registers its screens with the app shell', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'compliance').map((route) => route.name);
        expect(names).toEqual(['compliance', 'compliance-renewals', 'compliance-lists', 'compliance-import']);
    });

    it('says everything in Bangla and English', () => {
        expect(keys(bn)).toEqual(keys(en));
    });

    it('proposes the expiry as the day before the same day so many months on, keeping month ends', () => {
        expect(expiryFrom('2026-03-15', 12)).toBe('2027-03-14');
        expect(expiryFrom('2026-01-31', 1)).toBe('2026-02-27');
        expect(expiryFrom('2024-02-29', 12)).toBe('2025-02-27');
        expect(expiryFrom('', 12)).toBe('');
        expect(expiryFrom('2026-03-15', null)).toBe('');
    });

    it('lays the register out as factories by services, empty rows kept only when not filtering', () => {
        const factories = [{ id: 'c1' }, { id: 'b1' }, { id: 'c2' }];
        const services = [{ id: 's1' }, { id: 's2' }, { id: 's3' }];
        const lines = [{ id: 'l1', factory: { id: 'b1' }, service: { id: 's2' } }, { id: 'l2', factory: { id: 'c1' }, service: { id: 's1' } }];
        const all = grid(lines, factories, services);
        expect(all.columns.map((column) => column.id)).toEqual(['s1', 's2']);
        expect(all.rows.map((row) => row.cells.map((cell) => cell?.id ?? null))).toEqual([['l2', null], [null, 'l1'], [null, null]]);
        expect(grid(lines, factories, services, false).rows.map((row) => row.factory.id)).toEqual(['c1', 'b1']);
    });

    it('marks a line with results waiting first, else its open renewal request', () => {
        expect(renewalMark({ results_pending: 2, renewal: { status: 'sent' } })).toEqual({ key: 'results', tone: 'warn', count: 2 });
        expect(renewalMark({ results_pending: 0, renewal: { status: 'accepted', planned_on: '2026-11-15' } })).toEqual({ key: 'accepted', tone: 'ok', planned: '2026-11-15' });
        expect(renewalMark({ results_pending: 0, renewal: { status: 'cancelled' } })).toBeNull();
        expect(renewalMark({ results_pending: 0, renewal: null })).toBeNull();
    });

    it('prepares the email to a provider not on the platform', () => {
        const link = mailtoLink({ email: 'desk@certs.test', subject: 'Renewal: WRAP', body: 'Dear Desk,\nPlease & thanks' });
        expect(link).toBe('mailto:desk@certs.test?subject=Renewal%3A%20WRAP&body=Dear%20Desk%2C%0APlease%20%26%20thanks');
        // Nothing but an address goes before the "?".
        expect(mailtoLink({ email: 'a@b.test?cc=x@y.test', subject: 's', body: 'b' })).toBe('mailto:a@b.testccx@y.test?subject=s&body=b');
        expect(mailtoLink(null)).toBe('');
    });

    it('tones each state and counts every state', () => {
        expect(stateTone('expired')).toBe('bad');
        expect(stateTone('valid')).toBe('ok');
        expect(counts({ expired: 2 })).toEqual({ expired: 2, expiring: 0, valid: 0, unknown: 0 });
    });
});
