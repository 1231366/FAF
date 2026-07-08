// FAF service worker — recebe notificações push e abre a app ao clicar.
self.addEventListener('push', (event) => {
    let data = { title: 'FAF.', body: 'Tens treino hoje. O asfalto espera.' };
    try { data = event.data.json(); } catch (e) {}
    event.waitUntil(self.registration.showNotification(data.title || 'FAF.', {
        body: data.body || '',
        icon: data.icon || undefined,
        badge: data.badge || undefined,
        data: { url: data.url || 'plan.php' },
        tag: data.tag || 'faf-reminder',
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data && event.notification.data.url ? event.notification.data.url : 'plan.php';
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
        for (const w of wins) {
            if (w.url.includes('plan.php') && 'focus' in w) return w.focus();
        }
        return clients.openWindow(url);
    }));
});
