// ============================================================
//  SERVICE WORKER - sw.js
//  Handles offline caching and PWA functionality
// ============================================================

const CACHE_NAME = 'petal-v1.0.0';
const ASSETS = [
  '/devweekendchallenge/',
  '/devweekendchallenge/index.php',
  '/devweekendchallenge/dashboard.php',
  '/devweekendchallenge/results.php',
  '/devweekendchallenge/promise_handler.php',
  '/devweekendchallenge/send_promise_email.php',
  '/devweekendchallenge/icons/icon-192.svg',
  '/devweekendchallenge/icons/icon-512.svg',
  'https://fonts.googleapis.com/css2?family=Quicksand:wght@300;400;500;600;700&display=swap'
];

// ============================================================
//  INSTALL EVENT - Cache assets
// ============================================================
self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function(cache) {
        console.log('📦 Service Worker: Caching assets...');
        return cache.addAll(ASSETS);
      })
      .then(function() {
        return self.skipWaiting();
      })
  );
});

// ============================================================
//  ACTIVATE EVENT - Clean up old caches
// ============================================================
self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(cacheNames) {
      return Promise.all(
        cacheNames.map(function(cacheName) {
          if (cacheName !== CACHE_NAME) {
            console.log('🗑️ Service Worker: Removing old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(function() {
      return self.clients.claim();
    })
  );
});

// ============================================================
//  FETCH EVENT - Serve from cache or network
// ============================================================
self.addEventListener('fetch', function(event) {
  event.respondWith(
    caches.match(event.request)
      .then(function(cachedResponse) {
        // Return cached response if found
        if (cachedResponse) {
          return cachedResponse;
        }
        
        // Otherwise fetch from network
        return fetch(event.request)
          .then(function(response) {
            // Don't cache if not a valid response
            if (!response || response.status !== 200 || response.type !== 'basic') {
              return response;
            }
            
            // Clone the response
            const responseToCache = response.clone();
            
            // Cache the fetched response
            caches.open(CACHE_NAME)
              .then(function(cache) {
                cache.put(event.request, responseToCache);
              });
            
            return response;
          })
          .catch(function() {
            // Offline fallback - return a simple offline page
            return new Response(
              '<html><body><h1>🌸 Petal</h1><p>You are offline. Please check your internet connection.</p></body></html>',
              { headers: { 'Content-Type': 'text/html' } }
            );
          });
      })
  );
});

// ============================================================
//  PUSH NOTIFICATION EVENT
// ============================================================
self.addEventListener('push', function(event) {
  const data = event.data ? event.data.json() : {};
  const title = data.title || '🌸 Petal Reminder';
  const options = {
    body: data.body || 'Remember your promise!',
    icon: '/devweekendchallenge/icons/icon-192.svg',
    badge: '/devweekendchallenge/icons/icon-192.svg',
    vibrate: [200, 100, 200],
    data: {
      url: data.url || '/devweekendchallenge/dashboard.php'
    },
    actions: [
      {
        action: 'open',
        title: '🌸 Open Petal'
      },
      {
        action: 'dismiss',
        title: 'Dismiss'
      }
    ]
  };
  
  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

// ============================================================
//  NOTIFICATION CLICK EVENT
// ============================================================
self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  
  if (event.action === 'dismiss') {
    return;
  }
  
  event.waitUntil(
    clients.matchAll({ type: 'window' })
      .then(function(clientList) {
        // Check if a window is already open
        for (let i = 0; i < clientList.length; i++) {
          const client = clientList[i];
          if (client.url.includes('/devweekendchallenge/') && 'focus' in client) {
            return client.focus();
          }
        }
        // Otherwise open a new window
        if (clients.openWindow) {
          return clients.openWindow('/devweekendchallenge/dashboard.php');
        }
      })
  );
});