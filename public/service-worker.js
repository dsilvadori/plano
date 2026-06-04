const CACHE_NAME = 'plano-vc-v2';
const ASSETS = [
    '/',
    '/dashboard',
    '/manifest.webmanifest',
    '/images/vencendo-concursos-logo-white.webp',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys
            .filter((key) => key !== CACHE_NAME)
            .map((key) => caches.delete(key)))).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    event.respondWith(
        caches.match(event.request).then((response) => {
            if (response) {
                return response;
            }

            return fetch(event.request).then((networkResponse) => {
                const responseClone = networkResponse.clone();

                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseClone));

                return networkResponse;
            }).catch(() => caches.match('/dashboard'));
        }),
    );
});
