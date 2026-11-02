/**
 * The shared UI every module builds its screens from, in one import:
 *
 *   import { PageFrame, MoneyInput, DateInput, StatusBadge, AppButton, AppField } from '@/ui';
 *
 * Change a piece here (how loading looks, what a money field accepts, what
 * print carries) and every module follows without being touched. The app's
 * frame (header, sidebar, footer) lives in resources/js/layouts and wraps
 * every page already; modules never draw their own. See docs/frontend-ui.md.
 */

// Page frame and states
export { default as PageFrame } from '@/components/PageFrame.vue';
export { default as PageHeader } from '@/components/PageHeader.vue';
export { default as EmptyState } from '@/components/EmptyState.vue';
export { default as ErrorState } from '@/components/ErrorState.vue';
export { default as SkeletonRows } from '@/components/SkeletonRows.vue';
export { default as ExportMenu } from '@/components/ExportMenu.vue';

// Controls
export { default as AppButton } from '@/components/AppButton.vue';
export { default as AppMenu } from '@/components/AppMenu.vue';
export { default as AppSegmented } from '@/components/AppSegmented.vue';
export { default as AppSwitch } from '@/components/AppSwitch.vue';
export { default as AppTabs } from '@/components/AppTabs.vue';

// Forms
export { default as AppField } from '@/components/AppField.vue';
export { default as MoneyInput } from '@/components/MoneyInput.vue';
export { default as DateInput } from '@/components/DateInput.vue';
export { default as PhoneInput } from '@/components/PhoneInput.vue';
export { default as TranslatedFields } from '@/components/TranslatedFields.vue';

// Windows
export { default as AppDialog } from '@/components/AppDialog.vue';
export { default as AppDrawer } from '@/components/AppDrawer.vue';

// Showing values
export { default as AppBadge } from '@/components/AppBadge.vue';
export { default as StatusBadge } from '@/components/StatusBadge.vue';
export { default as SourceBadge } from '@/components/SourceBadge.vue';

// Helpers (no copies in modules: one reader for typed numbers, one "today")
export { amountToMinor, cleanNumber, latinDigits, minorToText } from '@/lib/numbers';
export { localToday } from '@/lib/dates';
export { newOpId } from '@/lib/billing';
export { confirmAction } from '@/lib/dialogs';
export { toast } from '@/lib/toast';
