import { reactive } from 'vue';

/**
 * One confirmation dialog for the whole app (rendered by App.vue).
 * Every change that the server audits asks for a reason here, and
 * destructive actions can require typing a confirmation word.
 *
 *   const result = await confirmAction({ title, message, reason: 'required', danger: true });
 *   if (!result) return; // cancelled
 *   result.reason, result.checked
 */
export const dialog = reactive({
    open: false,
    options: {},
    resolve: null,
});

export function confirmAction(options) {
    if (dialog.resolve) dialog.resolve(null);

    return new Promise((resolve) => {
        dialog.options = {
            reason: 'none', // none | optional | required
            danger: false,
            typeToConfirm: null,
            checkbox: null, // { label, checked }
            details: null, // list of strings shown under the message
            ...options,
        };
        dialog.resolve = resolve;
        dialog.open = true;
    });
}

export function closeDialog(result) {
    const resolve = dialog.resolve;
    dialog.open = false;
    dialog.resolve = null;
    resolve?.(result);
}
