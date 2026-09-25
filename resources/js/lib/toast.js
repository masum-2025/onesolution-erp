import { reactive } from 'vue';

/**
 * Toast notifications. An optional action (e.g. Undo) keeps the toast
 * on screen a little longer.
 */
export const toasts = reactive([]);

let nextId = 1;

function push(kind, message, { action = null, duration } = {}) {
    const id = nextId++;
    toasts.push({ id, kind, message, action });
    const ms = duration ?? (action ? 8000 : kind === 'error' ? 7000 : 4500);
    setTimeout(() => dismiss(id), ms);
    return id;
}

export function dismiss(id) {
    const index = toasts.findIndex((toast) => toast.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

export const toast = {
    success: (message, options) => push('success', message, options),
    error: (message, options) => push('error', message, options),
    info: (message, options) => push('info', message, options),
};
