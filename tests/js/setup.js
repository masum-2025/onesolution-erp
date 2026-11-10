import { webcrypto } from 'node:crypto';

// PERF-1: tests run in VM contexts (pool "vmThreads", one jsdom per worker). jsdom there has no
// WebCrypto, which the offline store needs (crypto.subtle); give it Node's, as a browser has its own.
if (!globalThis.crypto?.subtle) {
    Object.defineProperty(globalThis, 'crypto', { value: webcrypto, configurable: true });
}
