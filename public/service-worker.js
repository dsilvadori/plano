const CACHE_NAME = 'plano-vc-v2';
const ASSETS = [
    '/',
    '/manifest.webmanifest',
    '/images/vencendo-concursos-logo-white.webp',
];

const isLocalhost = self.location.hostname === 'localhost' || self.location.hostname === '127.0.0.1';
const disabledHosts = new Set([
    'localhost',
    '127.0.0.1',
    'dev.vencendoconcursos.com.br',
]);
const isServiceWorkerDisabled = disabledHosts.has(self.location.hostname);

const isStaticAssetRequest = (requestUrl) => {
    return requestUrl.origin === self.location.origin && !requestUrl.pathname.startsWith('/login') &&
        !requestUrl.pathname.startsWith('/dashboard') &&
        !requestUrl.pathname.startsWith('/admin') &&
        !requestUrl.pathname.startsWith('/livewire') &&
        !requestUrl.pathname.startsWith('/logout') &&
        !requestUrl.pathname.startsWith('/profile');
};

if (isServiceWorkerDisabled) {
    self.addEventListener('install', () => self.skipWaiting());
    self.addEventListener('activate', (event) => {
        event.waitUntil(
            caches.keys().then((keys) => Promise.all(keys.map((key) => caches.delete(key)))).then(() => self.clients.claim()),
        );
    });
    self.addEventListener('fetch', (event) => {
        event.respondWith(fetch(event.request));
    });
} else {
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

        if (event.request.mode === 'navigate') {
            event.respondWith(fetch(event.request));
            return;
        }

        const requestUrl = new URL(event.request.url);

        if (!isStaticAssetRequest(requestUrl)) {
            event.respondWith(fetch(event.request));
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
                });
            }),
        );
    });
}
