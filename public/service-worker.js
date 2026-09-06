const STATIC_CACHE = 'buildpusher-static-v1';
const STATIC_ASSETS = ['/favicon.svg', '/manifest.webmanifest'];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(STATIC_CACHE).then(cache => cache.addAll(STATIC_ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key !== STATIC_CACHE).map(key => caches.delete(key)))));
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET' || new URL(event.request.url).origin !== self.location.origin) return;
  if (STATIC_ASSETS.includes(new URL(event.request.url).pathname)) {
    event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request)));
  }
});

self.addEventListener('push', event => {
  const payload = event.data?.json() || { title: 'BuildPusher', message: 'A workspace event needs attention.', url: '/notifications' };
  event.waitUntil(self.registration.showNotification(payload.title || 'BuildPusher', {
    body: payload.message || '', icon: '/favicon.svg', badge: '/favicon.svg',
    data: { url: payload.url || '/notifications' }, tag: payload.tag || 'buildpusher-event',
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(clients.openWindow(event.notification.data?.url || '/notifications'));
});
