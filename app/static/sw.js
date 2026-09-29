/* Hastama service worker — the outage page when the user's own link is gone.
 *
 * The server cannot send anything to a browser that cannot reach it, so the one
 * case the application can never cover is "this workstation has no internet":
 * the browser shows its own error page instead.  This worker keeps a copy of the
 * outage guide (``/offline``, rendered by the server so it carries the current
 * laboratory address) and answers failed page loads with it.
 *
 * Deliberately narrow: only top level navigations are touched, and only when the
 * network actually fails — nothing else is ever served from the cache, so the
 * application keeps behaving exactly as before while the connection is up.
 */
'use strict';

var CACHE = 'hastama-offline-v1';
var OFFLINE_URL = '/offline';

self.addEventListener('install', function (event) {
  event.waitUntil((async function () {
    try {
      var response = await fetch(OFFLINE_URL, { cache: 'no-store' });
      if (response && response.ok) {
        var cache = await caches.open(CACHE);
        await cache.put(OFFLINE_URL, response.clone());
      }
    } catch (error) {
      /* first install while offline: the fallback below still answers */
    }
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', function (event) {
  event.waitUntil((async function () {
    var keys = await caches.keys();
    await Promise.all(keys.map(function (key) {
      return key === CACHE ? null : caches.delete(key);
    }));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET' || request.mode !== 'navigate') return;

  event.respondWith((async function () {
    try {
      var response = await fetch(request);
      var isGuide = new URL(request.url).pathname.indexOf(OFFLINE_URL) === 0;
      if (isGuide && response && response.ok) {
        // Refresh the stored guide whenever it is fetched successfully.
        var cache = await caches.open(CACHE);
        await cache.put(OFFLINE_URL, response.clone());
      }
      return response;
    } catch (error) {
      var cache = await caches.open(CACHE);
      var cached = await cache.match(OFFLINE_URL);
      if (cached) return cached;
      return new Response(
        '<!DOCTYPE html><html lang="fa" dir="rtl"><meta charset="utf-8">' +
          '<title>ارتباط با سامانه برقرار نیست</title>' +
          '<body style="font-family:Tahoma,sans-serif;padding:24px;text-align:center">' +
          '<h2>ارتباط با سامانه برقرار نیست</h2>' +
          '<p>اتصال اینترنت این رایانه قطع شده است. پس از برقراری اتصال، صفحه را دوباره باز کنید.</p>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
      );
    }
  })());
});
