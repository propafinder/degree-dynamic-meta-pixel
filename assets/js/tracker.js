(function () {
  'use strict';

  if (!window.dmufTracker || !window.fetch || !window.URLSearchParams) {
    return;
  }

  function readCookie(name) {
    var prefix = name + '=';
    var parts = document.cookie ? document.cookie.split(';') : [];
    for (var i = 0; i < parts.length; i += 1) {
      var item = parts[i].trim();
      if (item.indexOf(prefix) === 0) {
        return item.substring(prefix.length);
      }
    }
    return '';
  }

  function post(url, body) {
    return window.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify(body)
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('DMUF request failed');
      }
      return response.json();
    });
  }

  function ensureMeta(pixelId) {
    if (!window.fbq) {
      window.fbq = function () {
        window.fbq.callMethod ? window.fbq.callMethod.apply(window.fbq, arguments) : window.fbq.queue.push(arguments);
      };
      if (!window._fbq) {
        window._fbq = window.fbq;
      }
      window.fbq.push = window.fbq;
      window.fbq.loaded = true;
      window.fbq.version = '2.0';
      window.fbq.queue = [];

      var script = document.createElement('script');
      script.async = true;
      script.src = 'https://connect.facebook.net/en_US/fbevents.js';
      var first = document.getElementsByTagName('script')[0];
      first.parentNode.insertBefore(script, first);
    }

    window.fbq('init', String(pixelId));
  }

  function trackCheckout(data) {
    if (!data || !data.attributed || !data.track || !data.pixelId || !data.eventId) {
      return;
    }

    ensureMeta(data.pixelId);
    window.fbq(
      'trackSingle',
      String(data.pixelId),
      'InitiateCheckout',
      { value: Number(data.value || 0), currency: String(data.currency || '') },
      { eventID: String(data.eventId) }
    );
  }

  var params = new window.URLSearchParams(window.location.search);
  var source = params.get('utm_source');
  var capture = Promise.resolve();

  if (source) {
    capture = post(window.dmufTracker.captureUrl, {
      utm_source: source,
      utm_medium: params.get('utm_medium') || '',
      utm_campaign: params.get('utm_campaign') || '',
      utm_content: params.get('utm_content') || '',
      utm_term: params.get('utm_term') || '',
      landing_url: window.location.href
    }).catch(function () {});
  }

  if (window.dmufTracker.isCheckout) {
    capture.then(function () {
      return post(window.dmufTracker.checkoutUrl, {
        page_url: window.location.href,
        fbp: readCookie('_fbp'),
        fbc: readCookie('_fbc')
      });
    }).then(trackCheckout).catch(function () {});
  }
}());
