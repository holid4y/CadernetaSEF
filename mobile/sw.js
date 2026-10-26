const CACHE = 'agenda-contabil-v9';

const FILES = [
    './',
    'index.html',
    'manifest.webmanifest',
    'css/app.css',
    'fonts/fraunces-latin-soft-normal.woff2',
    'fonts/literata-latin-opsz-normal.woff2',
    'icons/icon-192.png',
    'icons/icon-512.png',
    'icons/logo.svg',
    'js/app.js',
    'js/contacts.js',
    'js/forms.js',
    'js/calendar.js',
    'js/viewport.js',
    'js/config.js',
    'js/api.js',
    'js/curtain.js',
    'js/data.js',
    'js/domain.js',
    'js/profile.js',
    'js/queries.js',
    'js/router.js',
    'js/seed.js',
    'js/store.js',
    'js/theme.js',
    'js/theme-boot.js',
    'js/ui.js',
    'js/util.js',
    'js/views/agenda.js',
    'js/views/appointment.js',
    'js/views/clients.js',
    'js/views/home.js',
    'js/views/impact.js',
    'js/views/login.js',
    'js/views/newAppointment.js',
    'js/views/services.js',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(FILES)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

// Cached copy first so the app opens without signal; the network copy replaces it for next time.
self.addEventListener('fetch', (event) => {
    if (new URL(event.request.url).pathname.includes('/api/')) return;
    if (event.request.method !== 'GET' || new URL(event.request.url).origin !== location.origin) return;

    event.respondWith(
        caches.match(event.request).then((cached) => {
            const fresh = fetch(event.request)
                .then((response) => {
                    if (response.ok) caches.open(CACHE).then((cache) => cache.put(event.request, response.clone()));
                    return response;
                })
                .catch(() => cached);
            return cached ?? fresh;
        }),
    );
});
