// Service worker JITU LEAD: menampilkan notifikasi push dan membuka halaman yang tepat saat diketuk.
// Sengaja tidak menyimpan halaman (cache): data CRM selalu diambil langsung dari server.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }

    event.waitUntil(self.registration.showNotification(data.title || 'JITU LEAD', {
        body: data.body || '',
        icon: '/images/icon-192.png',
        badge: '/images/badge-96.png',
        tag: data.tag || undefined,
        renotify: Boolean(data.tag),
        data: { url: data.url || '/app' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // Hanya alamat di situs ini yang dibuka.
    let target = new URL('/app', self.location.origin).href;
    try {
        const url = new URL((event.notification.data || {}).url || '/app', self.location.origin);
        if (url.origin === self.location.origin) target = url.href;
    } catch (e) { /* tetap /app */ }

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        for (const w of windows) {
            if (new URL(w.url).origin === self.location.origin && 'focus' in w) {
                return w.focus().then((c) => ('navigate' in c ? c.navigate(target) : c));
            }
        }
        return self.clients.openWindow(target);
    }));
});
