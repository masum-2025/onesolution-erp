import { confirmAction } from './dialogs';
import { hasOfflineData } from './session';
import { t } from './i18n';
import { formatNumber } from './format';

/**
 * Before signing out: offline changes not synced yet would be wiped with
 * the rest of the offline data (Phase 7-2), so the person is warned first.
 * Signing out is never blocked (a shared computer must be cleared).
 *
 * @returns {Promise<boolean>} true to go on
 */
export async function confirmSignOut() {
    if (!hasOfflineData()) return true;

    const { unsyncedCount } = await import('./offline/index');
    const count = await unsyncedCount().catch(() => 0);
    if (count === 0) return true;

    const confirmed = await confirmAction({
        title: t('core.offline.signout_title'),
        message: t('core.offline.signout_text', { count, changes: formatNumber(count) }),
        confirmLabel: t('core.auth.sign_out'),
        danger: true,
    });

    return Boolean(confirmed);
}
