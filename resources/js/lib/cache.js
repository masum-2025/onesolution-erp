// In-memory cache for data shared by several screens (organization list, menu).
// Cleared on login, logout and context switch, so nothing leaks between contexts.

const entries = new Map();

export function cached(key, loader) {
    if (!entries.has(key)) {
        const promise = loader().catch((error) => {
            entries.delete(key);
            throw error;
        });
        entries.set(key, promise);
    }
    return entries.get(key);
}

export function invalidate(prefix) {
    [...entries.keys()].filter((key) => key.startsWith(prefix)).forEach((key) => entries.delete(key));
}

export function resetCaches() {
    entries.clear();
}
