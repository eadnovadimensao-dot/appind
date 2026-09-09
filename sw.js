const CACHE_NAME = 'igreja-manager-v1';

self.addEventListener('install', event => { self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(clients.claim()); });

self.addEventListener('push', function(event) {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch(e) {}
  const title   = data.title || 'Igreja Manager';
  const options = {
    body:     data.body || 'Você tem um novo aviso.',
    icon:     '/public/img/icon-192.png',
    badge:    '/public/img/icon-192.png',
    vibrate:  [200, 100, 200],
    tag:      data.tag || 'announcement',
    renotify: true,
    data:     { url: data.url || '/pages/communication/index.php' },
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  const url = event.notification.data.url || '/pages/communication/index.php';
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(windowClients => {
      for (const client of windowClients) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});
