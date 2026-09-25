// Tiny event bus for app-wide signals (session expired, context lost).

const listeners = new Map();

export function on(event, handler) {
    if (!listeners.has(event)) listeners.set(event, new Set());
    listeners.get(event).add(handler);
    return () => listeners.get(event)?.delete(handler);
}

export function emit(event, payload) {
    listeners.get(event)?.forEach((handler) => handler(payload));
}
