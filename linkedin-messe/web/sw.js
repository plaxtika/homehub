// Service Worker: App-Oberfläche offline verfügbar halten. API-Aufrufe gehen immer ans Netz.
const VERSION = 'v1';
const SHELL = [
  './', 'index.html', 'manifest.webmanifest',
  'assets/styles.css', 'assets/app.js', 'assets/api.js', 'assets/store.js', 'assets/images.js',
  'assets/preview.js', 'assets/util.js',
  'assets/vendor/cropper.min.js', 'assets/vendor/cropper.min.css', 'assets/vendor/Sortable.min.js',
  'icons/icon.svg', 'icons/icon-192.png', 'icons/icon-512.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (/\/(api|oauth|cron)\.php$/.test(url.pathname)) return;

  // Fotos: einmal geladen, offline weiter anzeigbar.
  if (url.pathname.endsWith('/media.php')) {
    e.respondWith(caches.open(VERSION + '-media').then(async (c) => {
      const hit = await c.match(e.request);
      if (hit) return hit;
      const res = await fetch(e.request);
      if (res.ok) c.put(e.request, res.clone());
      return res;
    }));
    return;
  }

  // App-Shell: Netz zuerst (immer aktuell), offline aus dem Cache.
  e.respondWith(fetch(e.request).then((res) => {
    if (res.ok) caches.open(VERSION).then((c) => c.put(e.request, res.clone()));
    return res;
  }).catch(() => caches.match(e.request, { ignoreSearch: true }).then((r) => r || caches.match('index.html'))));
});
