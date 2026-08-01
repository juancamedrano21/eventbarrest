// App shell offline: cache-first para los assets compilados y la pantalla
// del POS. Los DATOS nunca pasan por aqui: viven en Dexie y en la API.
const SHELL = 'pos-shell-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.pathname.startsWith('/api/')) {
        return;
    }

    // Navegacion al POS: red primero, cache como respaldo offline.
    if (event.request.mode === 'navigate' && url.pathname.startsWith('/pos')) {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(SHELL).then((cache) => cache.put('/pos', copy));
                    return response;
                })
                .catch(() => caches.match('/pos'))
        );
        return;
    }

    // Assets compilados: cache primero (van con hash en el nombre).
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/pos/')) {
        event.respondWith(
            caches.match(event.request).then((hit) => hit || fetch(event.request).then((response) => {
                const copy = response.clone();
                caches.open(SHELL).then((cache) => cache.put(event.request, copy));
                return response;
            }))
        );
    }
});
