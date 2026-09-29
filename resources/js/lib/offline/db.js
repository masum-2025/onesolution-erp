/*
 * A very small promise wrapper around IndexedDB for the offline store
 * (Phase 7-2). One database per person and organization; four stores:
 *
 *   meta     key/value (the encryption key, the device, the lease, the cursor)
 *   records  records the device keeps, by "kind:id"
 *   queue    changes made offline, in order (auto-increment seq)
 *   outcomes changes the server did not apply (conflicts, rejections), by op_id
 */
const VERSION = 1;

export function openDatabase(name, factory = globalThis.indexedDB) {
    return new Promise((resolve, reject) => {
        const request = factory.open(name, VERSION);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'key' });
            if (!db.objectStoreNames.contains('records')) db.createObjectStore('records', { keyPath: 'key' }).createIndex('kind', 'kind');
            if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', { keyPath: 'seq', autoIncrement: true });
            if (!db.objectStoreNames.contains('outcomes')) db.createObjectStore('outcomes', { keyPath: 'op_id' });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

export function deleteDatabase(name, factory = globalThis.indexedDB) {
    return new Promise((resolve) => {
        const request = factory.deleteDatabase(name);
        request.onsuccess = request.onerror = request.onblocked = () => resolve();
    });
}

function done(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/** Runs fn(store) in one transaction and waits for it to commit. */
export function withStore(db, name, mode, fn) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(name, mode);
        let result;
        Promise.resolve(fn(transaction.objectStore(name), done)).then((value) => (result = value), reject);
        transaction.oncomplete = () => resolve(result);
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error);
    });
}

export const get = (db, store, key) => withStore(db, store, 'readonly', (s, wait) => wait(s.get(key)));
export const put = (db, store, value) => withStore(db, store, 'readwrite', (s, wait) => wait(s.put(value)));
export const add = (db, store, value) => withStore(db, store, 'readwrite', (s, wait) => wait(s.add(value)));
export const remove = (db, store, key) => withStore(db, store, 'readwrite', (s, wait) => wait(s.delete(key)));
export const all = (db, store) => withStore(db, store, 'readonly', (s, wait) => wait(s.getAll()));
export const count = (db, store) => withStore(db, store, 'readonly', (s, wait) => wait(s.count()));
export const allByKind = (db, kind) => withStore(db, 'records', 'readonly', (s, wait) => wait(s.index('kind').getAll(kind)));
