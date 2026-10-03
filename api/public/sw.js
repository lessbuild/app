/* BuildPusher service worker: shows push notifications for alerts and opens them. */
self.addEventListener('push', (event) => {
    let message = {};
    try {
        message = event.data ? event.data.json() : {};
    } catch (error) {
        message = { title: 'BuildPusher', body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(message.title || 'BuildPusher', {
        body: message.body || '',
        tag: message.tag || undefined,
        renotify: Boolean(message.tag),
        requireInteraction: message.urgent === true,
        data: { url: message.url || '/' },
        icon: '/favicon.ico',
        badge: '/favicon.ico',
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        for (const client of windows) {
            if (client.url === url && 'focus' in client) return client.focus();
        }

        return self.clients.openWindow(url);
    }));
});
