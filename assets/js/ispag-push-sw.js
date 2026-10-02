/* Service worker des notifications push ISPAG CRM.
 * Servi par WordPress à /ispag-push-sw.js (voir ISPAG_WebPush_Handler::maybe_serve_service_worker). */

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'ISPAG', body: event.data ? event.data.text() : '' };
    }

    var options = {
        body: data.body || '',
        icon: data.icon || undefined,
        data: { url: data.url || '/' }
    };
    if (data.tag) {
        options.tag = data.tag;
    }
    event.waitUntil(self.registration.showNotification(data.title || 'ISPAG', options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
            // Onglet du CRM déjà ouvert : on le réutilise plutôt que d'en ouvrir un nouveau
            for (var i = 0; i < windows.length; i++) {
                var client = windows[i];
                if (new URL(client.url).origin === new URL(url, self.location.origin).origin && 'navigate' in client) {
                    return client.navigate(url).then(function (c) { return c && c.focus(); });
                }
            }
            return self.clients.openWindow(url);
        })
    );
});
