# Degree Dynamic Meta Pixel

WordPress/WooCommerce plugin for strict UTM-only attribution and dynamic Meta Pixel + Conversions API routing.

## Updates

The plugin uses the native WordPress update screen and checks the latest public GitHub Release from `propafinder/degree-dynamic-meta-pixel`. Only the release ZIP asset named `degree-dynamic-meta-pixel-VERSION.zip` or `degree-dynamic-meta-pixel.zip` is accepted. Release checks are cached for six hours; automatic updates remain off unless enabled by a WordPress administrator.

## Telegram alerts

Telegram is optional and sends only two order outcomes for orders carrying this plugin's UTM attribution:

- `ОПЛАЧЕНО`: order number, UTM source, amount, currency and confirmed payment time.
- `НЕ ОПЛАЧЕНО`: order number, UTM source, amount, currency and order creation time.

Failed and cancelled orders are reported as unpaid immediately. Pending and on-hold orders are reported only after the configured delay, so a normal payment redirect does not produce a false unpaid alert. Paid events are queued immediately. Sending runs through WooCommerce Action Scheduler, with WordPress Cron as a fallback, and retries up to four times without blocking checkout or payment callbacks.

The bot token is masked in the settings screen and never included in reports. Telegram messages contain no customer names, email addresses, phone numbers or delivery addresses.

## What it tracks

- Recognized UTM visit: only when `utm_source` exactly matches an enabled rule.
- Checkout started: once per attributed UTM session.
- Checkout without an order: checkout started, but no WooCommerce order exists after the configured delay.
- Unpaid order: WooCommerce order exists but payment is not confirmed.
- Cancelled/failed/refunded order: shown locally and never sent as a Purchase.
- Paid order: WooCommerce confirms payment; Purchase is sent to the Pixel configured for that UTM source.

The plugin does not send PageView, ViewContent or AddToCart. It does not infer a source from referrer, `fbclid`, `_fbc`, IP address or a direct visit.

## Attribution rules

- Default attribution window: 7 days, configurable from 1 to 30 days.
- A direct visit never creates or overwrites attribution.
- A new recognized `utm_source` creates a new last-touch attribution session.
- Repeated loading of the same tagged URL within 10 minutes reuses the same session to avoid duplicate visits.
- An unknown UTM source sends no Meta event and clears an older recognized attribution, preventing a later source from being credited to the previous campaign.

## Meta events

- `InitiateCheckout`: browser Pixel and server CAPI use the same event ID.
- `Purchase`: browser Pixel on the paid thank-you page and server CAPI use `dmuf_purchase_ORDER_ID` for deduplication.
- Asynchronous payments are covered by server-side WooCommerce payment/status hooks even when the customer does not return to the thank-you page.
- CAPI retries failed Purchase requests up to five times. Tokens are never included in logs or reports.

## Data and removal

- UTM sessions are stored in the `{prefix}dmuf_sessions` table without names, emails, phone numbers or raw IP addresses.
- Order attribution is stored as `_dmuf_*` WooCommerce order metadata.
- Deactivation and deletion do not remove the table or order metadata.
- The plugin does not alter existing Meta Dynamic Pixel data and does not automatically import its noisy event log.

## Safe rollout

1. Install and activate the plugin. Tracking starts disabled.
2. Add exact rules such as `CapPrice -> Pixel ID + CAPI token`.
3. Use Meta Test Events with a temporary test code.
4. Enable tracking and test one tagged checkout and one test payment.
5. Verify that Pixel and CAPI show the same event ID.
6. Only then decide which older pixel implementation should remain active. This plugin never disables another plugin automatically.
