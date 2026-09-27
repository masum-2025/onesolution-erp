import { afterEach, describe, expect, it } from 'vitest';
import { isPortalMember, session } from '@/lib/session';

afterEach(() => {
    session.me = null;
});

describe('portal members', () => {
    it('are recognised by their membership in an organization context', () => {
        session.me = { context: { type: 'organization', membership_type: 'portal' } };
        expect(isPortalMember()).toBe(true);
    });

    it('are never staff, owners or partner console users', () => {
        for (const context of [
            { type: 'organization', membership_type: 'staff' },
            { type: 'organization', membership_type: 'owner' },
            { type: 'partner', role: 'owner' },
            null,
        ]) {
            session.me = context ? { context } : null;
            expect(isPortalMember()).toBe(false);
        }
    });
});
