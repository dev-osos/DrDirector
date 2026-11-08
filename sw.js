const VERSION = 'dr-director-v22';
const SHELL = ['./', 'index.html', 'assets/style.css', 'assets/app.js', 'manifest.json?v=22', 'icons/icon-192.png?v=22', 'icons/icon-512.png?v=22', 'icons/maskable-512.png?v=22'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;   // الخطوط والمصادر الخارجية: المتصفح يتولاها
  if (url.pathname.endsWith('/api.php')) return;                        // الـ API دائماً من الشبكة

  // stale-while-revalidate للملفات الثابتة
  e.respondWith(
    caches.open(VERSION).then(async (cache) => {
      const cached = await cache.match(req, { ignoreSearch: true });
      const net = fetch(req).then((res) => {
        if (res.ok) cache.put(req, res.clone());
        return res;
      }).catch(() => cached || (req.mode === 'navigate' ? cache.match('index.html') : undefined));
      return cached || net;
    })
  );
});
