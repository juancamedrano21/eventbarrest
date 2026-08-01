// App shell offline: el cascaron y los assets con hash se precachean al
// instalar; solo se cachean respuestas OK. Los DATOS nunca pasan por aqui:
// viven en Dexie y en la API.
const SHELL = 'pos-shell-v3';

self.addEventListener('install', (event) => {
    // El precache debe COMPLETARSE: si la red cae a mitad, el install falla
    // y el SW anterior sigue sirviendo su shell. Jamas se activa un shell
    // vacio encima de uno que funcionaba; el registro reintenta en la
    // proxima visita con red.
    event.waitUntil((async () => {
        const cache = await caches.open(SHELL);
        await cache.add('/pos');
        const manifest = await (await fetch('/build/manifest.json')).json();
        const assets = Object.values(manifest)
            .flatMap((entry) => [entry.file, ...(entry.css ?? [])])
            .map((file) => `/build/${file}`);
        await cache.addAll([...new Set(assets)]);
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        // Solo caches del POS: otro SW del mismo origen no es asunto nuestro.
        for (const key of await caches.keys()) {
            if (key.startsWith('pos-shell-') && key !== SHELL) await caches.delete(key);
        }
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.pathname.startsWith('/api/')) {
        return;
    }

    // Navegacion al POS (ruta exacta): red primero, cache como respaldo.
    if (event.request.mode === 'navigate' && url.pathname === '/pos') {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(SHELL).then((cache) => cache.put('/pos', copy));
                    }
                    return response;
                })
                .catch(() => caches.match('/pos'))
        );
        return;
    }

    // Assets compilados y estaticos del POS: cache primero.
    if (url.pathname.startsWith('/build/')
        || url.pathname === '/pos-manifest.webmanifest'
        || url.pathname === '/pos-icon.svg') {
        event.respondWith(
            caches.match(event.request).then((hit) => hit || fetch(event.request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(SHELL).then((cache) => cache.put(event.request, copy));
                }
                return response;
            }))
        );
    }
});
