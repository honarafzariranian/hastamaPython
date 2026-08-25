/* Hastama Web Push service worker. Keep this file same-origin and scope it at /. */
self.addEventListener('push', function (event) {
    var fallback = { title: 'اعلان جدید هستما', body: 'یک اعلان جدید دریافت شد.' };
    var data = fallback;
    try { if (event.data) data = Object.assign({}, fallback, event.data.json()); } catch (_) {}
    var options = {
        body: data.body || data.content || fallback.body,
        icon: data.icon || '/static/images/newlogo.png',
        badge: data.badge || '/static/images/newlogo.png',
        tag: data.tag || ('hastama-' + (data.id || 'notification')),
        renotify: false,
        data: { url: data.url || data.action_url || '/user_panel' }
    };
    event.waitUntil(self.registration.showNotification(data.title || fallback.title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = new URL((event.notification.data && event.notification.data.url) || '/user_panel', self.location.origin).href;
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
            for (var i = 0; i < windows.length; i += 1) {
                var client = windows[i];
                if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                    return client.focus().then(function (focused) {
                        return focused && 'navigate' in focused ? focused.navigate(target) : focused;
                    });
                }
            }
            return clients.openWindow(target);
        })
    );
});
