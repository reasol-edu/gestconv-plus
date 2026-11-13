/*
 * Service worker de GestConv+.
 *
 *  - Avisos push (salida abierta, tareas de sanción, partes próximos a prescribir).
 *  - Sin conexión: solo una página de aviso y los recursos estáticos públicos. NUNCA se guarda en caché una
 *    página de la aplicación ni una respuesta con datos de estudiantes: son datos de menores y los equipos de
 *    la sala de profesores se comparten.
 */
const CACHE = 'gestconv-shell-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL, '/static/logo.svg'])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    // Navegaciones: siempre a la red; si no hay conexión, la página de aviso (nunca una copia de la pantalla).
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    // Solo recursos estáticos públicos con hash en el nombre (AssetMapper) o de /static: sin datos personales.
    const url = new URL(request.url);
    if (url.origin === self.location.origin && (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/static/'))) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }
                return response;
            }))
        );
    }
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = {};
    }
    event.waitUntil(self.registration.showNotification(data.title || 'GestConv+', {
        body: data.body || '',
        icon: '/static/web-app-manifest-192x192.png',
        badge: '/static/favicon-96x96.png',
        tag: data.tag || undefined,
        data: { url: typeof data.url === 'string' ? data.url : '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    // Solo rutas de la propia aplicación.
    const target = new URL(event.notification.data && event.notification.data.url ? event.notification.data.url : '/', self.location.origin);
    if (target.origin !== self.location.origin) {
        return;
    }
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if (client.url === target.href && 'focus' in client) {
                    return client.focus();
                }
            }
            return self.clients.openWindow(target.href);
        })
    );
});
