import { reactive } from 'vue';

/**
 * "Confirm it's you" before a sensitive action (Phase 8-1). When the server
 * answers 403 step_up_required, the API client asks here; App.vue shows the
 * dialog; the request is sent again once the person confirmed.
 */
export const stepUp = reactive({ open: false, methods: [], resolve: null });

/** @returns {Promise<boolean>} true once confirmed, false when cancelled */
export function askStepUp(methods) {
    stepUp.resolve?.(false);

    return new Promise((resolve) => {
        Object.assign(stepUp, { open: true, methods, resolve });
    });
}

export function finishStepUp(confirmed) {
    const resolve = stepUp.resolve;
    Object.assign(stepUp, { open: false, resolve: null });
    resolve?.(confirmed);
}
