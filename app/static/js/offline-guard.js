/* Hastama offline guard.
 *
 * Two jobs, both about "the connection to the system just went away":
 *
 * 1. Register the service worker (``/sw.js``) so a page load that cannot reach
 *    the server is answered with the cached outage guide instead of the
 *    browser's own error page.  Only possible in a secure context, i.e. on
 *    ``https://hastama.ir`` — the plain-HTTP laboratory address cannot use a
 *    worker, and does not need one: there the server is one hop away.
 *
 * 2. Notice the loss immediately while a session is already open
 *    (``offline`` event) and show the same guide in an overlay, so the user is
 *    told what happened and where the system is still reachable.  Saving the
 *    open page is never thrown away: the overlay is dismissible, and the guide
 *    is loaded in a frame rather than replacing the document.
 */
(function () {
  'use strict';

  var OVERLAY_ID = 'hastamaOfflineOverlay';
  var STYLE_ID = 'hastamaOfflineOverlayStyle';
  var GUIDE_URL = '/offline';
  var dismissed = false;

  function isSecureContext() {
    return typeof window !== 'undefined' && window.isSecureContext === true;
  }

  if ('serviceWorker' in navigator && isSecureContext()) {
    try {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {
        /* no worker: the overlay below still covers the open-tab case */
      });
    } catch (error) {
      /* registration is best effort */
    }
  }

  function injectStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent =
      '#' + OVERLAY_ID + '{position:fixed;inset:0;z-index:2147483000;' +
      'background:rgba(15,23,42,.62);display:flex;align-items:center;justify-content:center;' +
      'padding:16px;font-family:Vazirmatn,Tahoma,sans-serif}' +
      '#' + OVERLAY_ID + ' .hoa-box{position:relative;width:100%;max-width:640px;' +
      'height:min(600px,88vh);background:#fff;border-radius:18px;overflow:hidden;' +
      'box-shadow:0 30px 70px rgba(15,23,42,.35)}' +
      '#' + OVERLAY_ID + ' iframe{width:100%;height:100%;border:0;display:block;background:#fff}' +
      '#' + OVERLAY_ID + ' .hoa-close{position:absolute;top:10px;left:10px;z-index:2;' +
      'border:1px solid #e2e8f0;background:#fff;color:#334155;border-radius:10px;' +
      'padding:6px 12px;font:600 .8rem Vazirmatn,Tahoma,sans-serif;cursor:pointer}';
    document.head.appendChild(style);
  }

  function hide() {
    var overlay = document.getElementById(OVERLAY_ID);
    if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
  }

  function show() {
    if (dismissed || document.getElementById(OVERLAY_ID) || !document.body) return;
    injectStyle();
    var overlay = document.createElement('div');
    overlay.id = OVERLAY_ID;
    overlay.setAttribute('dir', 'rtl');
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-label', 'قطع ارتباط با سامانه');

    var box = document.createElement('div');
    box.className = 'hoa-box';

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'hoa-close';
    close.textContent = 'بستن';
    close.addEventListener('click', function () {
      dismissed = true;  // do not nag until the connection drops again
      hide();
    });

    var frame = document.createElement('iframe');
    frame.setAttribute('title', 'راهنمای قطع ارتباط');
    frame.src = GUIDE_URL;

    box.appendChild(close);
    box.appendChild(frame);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
  }

  window.addEventListener('offline', function () {
    dismissed = false;
    show();
  });

  window.addEventListener('online', function () {
    dismissed = false;
    hide();
  });

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin) return;
    if (event.data && event.data.hastamaOffline === 'back') {
      hide();
      window.location.reload();
    }
  });

  if (navigator.onLine === false) show();
})();
