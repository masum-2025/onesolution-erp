// Per-browser UI preferences only (theme, language, collapsed panels).
// Never tokens or personal data. Storage can be blocked, so every access is guarded.

const PREFIX = 'os.';

export function readPref(key, fallback = null) {
    try {
        const value = window.localStorage.getItem(PREFIX + key);
        return value === null ? fallback : value;
    } catch {
        return fallback;
    }
}

export function writePref(key, value) {
    try {
        if (value === null || value === undefined) {
            window.localStorage.removeItem(PREFIX + key);
        } else {
            window.localStorage.setItem(PREFIX + key, String(value));
        }
    } catch {
        // Private mode or blocked storage: the preference just is not remembered.
    }
}
