/*
 * conversion-tracking.js — the shared GA4 conversion tracker for EVERY Chia brand.
 *
 * scope: Implements claude-shared/seo/conversion-tracking-standard.md. Fires the six
 *        standard events with the three required parameters. Brands copy this file
 *        verbatim into their own public/ and set BRAND only. They do not edit the
 *        event names — the standard is the authority.
 *
 * WHY THIS EXISTS (2026-08-13). Four of five brands had zero conversion tracking:
 * GA4 collected traffic and could not report a single enquiry, booking or purchase
 * because no site had a custom gtag() call anywhere. The second failure to avoid was
 * each brand inventing its own event names, so nothing could be compared. One file,
 * one vocabulary.
 *
 * Copy to:  <brand-site>/public/conversion-tracking.js
 * Load via: <script src="/conversion-tracking.js" defer></script>  (after gtag)
 * Then set: window.CONV_BRAND = "<registry key>" before this loads.
 */
(function () {
  'use strict';

  var BRAND = window.CONV_BRAND || 'unknown';

  // Fires nothing if gtag never loaded. A tracker must never throw on a page that
  // has consent blocking or an ad blocker — a broken tracker breaks the page.
  function send(event, offerId, surface, extra) {
    try {
      if (typeof window.gtag !== 'function') return;
      var payload = {
        brand: BRAND,
        offer_id: offerId || 'unspecified',
        surface: surface || detectSurface(),
      };
      if (extra) {
        for (var k in extra) {
          if (Object.prototype.hasOwnProperty.call(extra, k)) payload[k] = extra[k];
        }
      }
      window.gtag('event', event, payload);
    } catch (e) {
      /* never let tracking break the page */
    }
  }

  // Surface is derived from the path so every brand reports it the same way,
  // rather than each page hand-declaring a string that drifts.
  function detectSurface() {
    var p = (location.pathname || '/').toLowerCase();
    if (p === '/' || p === '') return 'home';
    if (p.indexOf('/pricing') === 0) return 'pricing';
    if (p.indexOf('/classes') === 0) return 'classes';
    if (p.indexOf('/programmes') === 0 || p.indexOf('/programs') === 0) return 'programmes';
    if (p.indexOf('/services') === 0) return 'services';
    if (p.indexOf('/book') === 0) return 'book';
    if (p.indexOf('/portal') === 0) return 'portal';
    if (p.indexOf('/insights/') === 0 || p.indexOf('/articles/') === 0 || p.indexOf('/blog/') === 0) return 'article';
    if (p.indexOf('/contact') === 0) return 'contact';
    return 'other';
  }

  // Guard against a double-fire when an element is both clicked and submitted,
  // or when a SPA-ish re-render rebinds a handler.
  var fired = Object.create(null);
  function once(key, fn) {
    if (fired[key]) return;
    fired[key] = true;
    fn();
    // Allow the same conversion again after a while (a genuine second enquiry).
    setTimeout(function () { delete fired[key]; }, 4000);
  }

  // ── contact_click ─────────────────────────────────────────────────────────
  // Auto-detected because a WhatsApp, tel: or mailto: link is unambiguous.
  // On JF this IS the primary conversion.
  document.addEventListener(
    'click',
    function (ev) {
      var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
      if (!a) return;
      var href = a.getAttribute('href') || '';

      if (/wa\.me|api\.whatsapp\.com|web\.whatsapp\.com/i.test(href)) {
        once('wa', function () { send('contact_click', 'whatsapp', null, { contact_method: 'whatsapp' }); });
        return;
      }
      if (/^tel:/i.test(href)) {
        once('tel', function () { send('contact_click', 'phone', null, { contact_method: 'phone' }); });
        return;
      }
      if (/^mailto:/i.test(href)) {
        once('mail', function () { send('contact_click', 'email', null, { contact_method: 'email' }); });
        return;
      }

      // ── offer_select ───────────────────────────────────────────────────────
      // Explicit only. Auto-detecting "a button that looks commercial" produces
      // false conversions, which are worse than none.
      var conv = a.getAttribute('data-conv');
      if (conv === 'offer_select') {
        once('offer-' + (a.getAttribute('data-offer') || 'x'), function () {
          send('offer_select', a.getAttribute('data-offer'), a.getAttribute('data-surface'));
        });
      }
    },
    true,
  );

  // Non-anchor offer buttons (a <button> opening a modal, for instance).
  document.addEventListener(
    'click',
    function (ev) {
      var el = ev.target && ev.target.closest ? ev.target.closest('[data-conv="offer_select"]:not(a)') : null;
      if (!el) return;
      once('offerb-' + (el.getAttribute('data-offer') || 'x'), function () {
        send('offer_select', el.getAttribute('data-offer'), el.getAttribute('data-surface'));
      });
    },
    true,
  );

  // ── offer_view ────────────────────────────────────────────────────────────
  // Declared per page with <body data-offer-page="trial-1month"> or any element
  // carrying data-offer-view. Never inferred.
  function fireOfferViews() {
    var host = document.querySelector('[data-offer-page]');
    if (host) send('offer_view', host.getAttribute('data-offer-page'), null);
    var els = document.querySelectorAll('[data-offer-view]');
    for (var i = 0; i < els.length; i++) {
      send('offer_view', els[i].getAttribute('data-offer-view'), els[i].getAttribute('data-surface'));
    }
  }

  // ── lead_submit / begin_checkout / purchase ───────────────────────────────
  // Exposed for a site's own success handler to call, because only the site knows
  // whether its fetch() actually succeeded. Firing on click would count failures
  // as conversions.
  window.convTrack = {
    lead: function (offerId, surface, extra) { send('lead_submit', offerId, surface, extra); },
    checkout: function (offerId, surface, extra) { send('begin_checkout', offerId, surface, extra); },
    purchase: function (offerId, surface, extra) { send('purchase', offerId, surface, extra); },
    offerSelect: function (offerId, surface, extra) { send('offer_select', offerId, surface, extra); },
    offerView: function (offerId, surface, extra) { send('offer_view', offerId, surface, extra); },
    _send: send,
  };

  // Forms marked data-conv="lead_submit" that submit normally (no fetch).
  document.addEventListener(
    'submit',
    function (ev) {
      var f = ev.target;
      if (!f || f.getAttribute('data-conv') !== 'lead_submit') return;
      once('form-' + (f.getAttribute('data-offer') || 'x'), function () {
        send('lead_submit', f.getAttribute('data-offer'), f.getAttribute('data-surface'));
      });
    },
    true,
  );

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fireOfferViews);
  } else {
    fireOfferViews();
  }
})();
