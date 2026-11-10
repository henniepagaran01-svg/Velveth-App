// ═══════════════════════════════════════════════════════
//  Velveth Lending — Service Worker v1.1
//  Strategy: Cache-first for assets, Network-first for PHP pages
//  NOTE: bump the "v1" suffix below whenever icons/manifest change —
//  otherwise installed devices keep serving the old cached icons
//  (this is why the app icon/splash looked stale on some phones).
// ═══════════════════════════════════════════════════════

const CACHE_NAME    = 'vlc-app-v3';
const ASSETS_CACHE  = 'vlc-assets-v3';

// Static assets to pre-cache on install
const PRECACHE_ASSETS = [
  '/',
  '/shared.css',
  '/mobile-app.css',
  '/manifest.json',
  '/assets/icons/icon-192x192.png',
  '/assets/icons/icon-512x512.png',
  '/assets/images/logo.png',
  '/offline.php'
];

// ── INSTALL: pre-cache static assets ──────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(ASSETS_CACHE)
      .then(cache => cache.addAll(PRECACHE_ASSETS))
      .then(() => self.skipWaiting())
      .catch(err => console.log('[SW] Pre-cache error (non-fatal):', err))
  );
});

// ── ACTIVATE: clean up old caches ─────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k !== CACHE_NAME && k !== ASSETS_CACHE)
          .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── FETCH: smart routing strategy ─────────────────────
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET, chrome-extension, and cross-origin requests
  if (request.method !== 'GET') return;
  if (!url.origin.includes(self.location.origin)) return;
  if (url.pathname.startsWith('/api_')) return;  // always network for APIs
  if (url.pathname.startsWith('/chatbot')) return; // always network for chatbot

  // ── CSS, JS, Images, Icons → Cache-First ──
  const isStaticAsset = /\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf)$/i.test(url.pathname);

  if (isStaticAsset) {
    event.respondWith(
      caches.match(request).then(cached => {
        if (cached) return cached;
        return fetch(request).then(response => {
          if (!response || response.status !== 200) return response;
          const clone = response.clone();
          caches.open(ASSETS_CACHE).then(cache => cache.put(request, clone));
          return response;
        });
      })
    );
    return;
  }

  // ── PHP Pages → Network-Only (never cache) ──
  // These pages are tied to a PHP session (login state, dashboard data,
  // forms, etc.) — the Cache Storage API keys purely by URL and has no idea
  // about cookies or session state, so caching a "successful" response here
  // risks serving one customer's logged-in dashboard, or a stale login-error
  // page, back on a later visit (e.g. after logging out and back in, or on
  // a different account on the same device). Always hit the network; if
  // that fails (offline), fall back to the static offline page only — never
  // to a stale copy of the real page.
  if (url.pathname.endsWith('.php') || url.pathname === '/') {
    event.respondWith(
      fetch(request).catch(() => caches.match('/offline.php'))
    );
    return;
  }
});

// ── PUSH NOTIFICATIONS (future-ready) ─────────────────
self.addEventListener('push', event => {
  const data = event.data ? event.data.json() : {};
  const title   = data.title   || 'Velveth Lending';
  const options = {
    body:    data.body    || 'You have a new notification.',
    icon:    '/assets/icons/icon-192x192.png',
    badge:   '/assets/icons/icon-72x72.png',
    vibrate: [100, 50, 100],
    data:    { url: data.url || '/' },
    actions: [
      { action: 'view',    title: '👁 View',   icon: '/assets/icons/icon-72x72.png' },
      { action: 'dismiss', title: '✕ Dismiss', icon: '/assets/icons/icon-72x72.png' }
    ]
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  if (event.action === 'dismiss') return;
  const url = event.notification.data?.url || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(clientList => {
        for (const client of clientList) {
          if (client.url === url && 'focus' in client) return client.focus();
        }
        return clients.openWindow(url);
      })
  );
});
