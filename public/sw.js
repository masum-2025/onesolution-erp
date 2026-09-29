/*
 * Service worker (Phase 7-2): lets the app open without a connection.
 *
 * It keeps ONLY the app itself: the page shell and the built files under
 * /build (hashed, never change), fonts and brand images. It never keeps API
 * answers, session routes or anything personal: data offline lives in the
 * encrypted store (resources/js/lib/offline), not here.
 *
 *  - page loads: network first, the last shell when offline;
 *  - /build/*: cache first (a new release has new file names);
 *  - everything else: straight to the network.
 */
const SHELL_CACHE = 'os-shell-v1';
const ASSET_CACHE = 'os-assets-v1';
const NEVER = [/^\/api\//, /^\/session\//, /^\/sanctum\//, /^\/payments\//, /^\/exports\//, /^\/internal\//];

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => ![SHELL_CACHE, ASSET_CACHE].includes(key)).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin || NEVER.some((pattern) => pattern.test(url.pathname))) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(SHELL_CACHE).then((cache) => cache.put('/', copy));
                    }
                    return response;
                })
                .catch(() => caches.open(SHELL_CACHE).then((cache) => cache.match('/')).then((cached) => cached ?? Response.error())),
        );
        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/brand/')) {
        event.respondWith(
            caches.open(ASSET_CACHE).then((cache) =>
                cache.match(request).then(
                    (cached) =>
                        cached ??
                        fetch(request).then((response) => {
                            if (response.ok) cache.put(request, response.clone());
                            return response;
                        }),
                ),
            ),
        );
    }
});
