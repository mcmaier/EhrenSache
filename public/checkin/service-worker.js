// Service Worker für PWA Offline-Support
const CACHE_NAME = 'checkin-app-v1';
const urlsToCache = [
  '/checkin/',
  '/checkin/index.html',
  '/checkin/css/style.css',
  '/checkin/js/app.js',
  '/checkin/manifest.json'
];

// Install Event
self.addEventListener('install', (event) => {
    console.log('Service Worker: Installing...');
    self.skipWaiting();
});

// Activate Event
self.addEventListener('activate', (event) => {
    console.log('Service Worker: Activating...');
    event.waitUntil(clients.claim());
});

// Fetch Event
self.addEventListener('fetch', (event) => {
    // Einfach durchreichen, kein Caching
    event.respondWith(fetch(event.request));
});

/*

// Installation - Cache static resources
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Cache opened');
        return cache.addAll(urlsToCache);
      })
  );
  self.skipWaiting();
});


// Activation - Clean up old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  self.clients.claim();
});

// Fetch - Network first, fallback to cache for UI
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  // API Requests: Network only (keine Cache)
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(event.request)
        .catch(() => {
          return new Response(
            JSON.stringify({ error: 'Offline - keine Verbindung zur API' }),
            { 
              status: 503,
              headers: { 'Content-Type': 'application/json' }
            }
          );
        })
    );
    return;
  }
  
  // Static Assets: Cache first, fallback to network
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        if (response) {
          return response;
        }
        return fetch(event.request)
          .then(response => {
            // Nur erfolgreiche GET Requests cachen
            if (!response || response.status !== 200 || response.type !== 'basic') {
              return response;
            }
            
            const responseToCache = response.clone();
            caches.open(CACHE_NAME)
              .then(cache => {
                cache.put(event.request, responseToCache);
              });
            
            return response;
          });
      })
  );
});
*/
