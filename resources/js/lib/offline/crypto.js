/*
 * Encryption at rest for everything the offline store keeps (Phase 7-2):
 * AES-GCM 256 with a fresh 12-byte IV per value. The key is made by Web
 * Crypto as NON-EXTRACTABLE: stored in IndexedDB as a CryptoKey, it can be
 * used by this page but never read out, not even by script.
 */
const ALGORITHM = { name: 'AES-GCM', length: 256 };

export function createKey(subtle = globalThis.crypto.subtle) {
    return subtle.generateKey(ALGORITHM, false, ['encrypt', 'decrypt']);
}

/** Any JSON-able value -> { iv, data } (both binary). */
export async function seal(key, value, subtle = globalThis.crypto.subtle) {
    const iv = globalThis.crypto.getRandomValues(new Uint8Array(12));
    const plain = new TextEncoder().encode(JSON.stringify(value));
    const data = await subtle.encrypt({ name: 'AES-GCM', iv }, key, plain);

    return { iv, data: new Uint8Array(data) };
}

/** { iv, data } -> the value; throws when the data was changed (GCM authenticates it). */
export async function unseal(key, sealed, subtle = globalThis.crypto.subtle) {
    const plain = await subtle.decrypt({ name: 'AES-GCM', iv: sealed.iv }, key, sealed.data);

    return JSON.parse(new TextDecoder().decode(plain));
}
