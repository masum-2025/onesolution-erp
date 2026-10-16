/** Lecture status -> badge tone. */
export function statusTone(status) {
    return { draft: 'neutral', waiting: 'warn', published: 'ok', withdrawn: 'bad' }[status] ?? 'neutral';
}

/** A file size people read: "820 KB", "2.4 MB". */
export function sizeText(bytes, format = (value) => String(value)) {
    if (bytes < 1024 * 1024) return `${format(Math.max(1, Math.round(bytes / 1024)))} KB`;
    return `${format(Math.round((bytes / (1024 * 1024)) * 10) / 10)} MB`;
}

/** Whether a link is an https link (the server checks the site against the rule). */
export function looksLikeVideo(url) {
    if (!url) return true;
    try {
        return new URL(url).protocol === 'https:';
    } catch {
        return false;
    }
}

/** The extensions the file picker offers, from the content types the server takes. */
export function acceptList(types) {
    return (types ?? []).map((ext) => `.${ext}`).join(',');
}
