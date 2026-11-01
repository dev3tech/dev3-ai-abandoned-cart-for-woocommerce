=== DEV3 AI Abandoned Cart & Win-back Emails for WooCommerce ===
Contributors: dev3tech
Tags: abandoned cart, cart recovery, woocommerce, win-back, sms
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capture abandoned carts, send AI-personalised recovery and win-back emails and SMS, and track recovered revenue.

== Description ==

DEV3 AI Abandoned Cart & Win-back Emails captures carts on the classic and block checkout (GDPR-consent aware), runs multi-step email and SMS sequences with unique coupons and one-click cart restore links, and shows exactly how much revenue came back.

With your own Google Gemini API key, DEV3 AI personalises each reminder for the shopper's cart. Every AI draft waits in a review queue until you approve it, unless you turn on auto-approve for that template. Without a key everything works on your approved templates.

= Features =

* Cart capture: classic checkout, block checkout (Store API + fields), logged-in customers, "Save my cart" block and shortcode.
* Consent modes: notice, opt-in checkbox, none. Separate SMS opt-in. Unsubscribe link and List-Unsubscribe headers in every email.
* Sequences: cart recovery and win-back, up to 10 steps each, email or SMS, per-step delays, minimum cart value.
* Templates: subject, preheader, heading, body, button, merge tags, live preview, test sends, quality checks, approval workflow.
* Unique single-use coupons per step (percent, fixed, free shipping), locked to the shopper's email, auto-cleanup.
* Recovery links restore items, quantities and variations and apply the coupon.
* Send-time tuning: moves sends to the shopper's best hour, outside quiet hours in their own timezone.
* Recovered-revenue dashboard: KPIs, abandoned vs recovered chart, step funnel, top abandoned products, recent recoveries.
* SMS through any Twilio-compatible API.
* HPOS and Cart/Checkout blocks compatible. Personal data exporter/eraser and retention cleanup.

= DEV3 AI features (Google Gemini) =

* Per-cart personalised copy, reviewed in the Review queue (or auto-approved per template).
* Write templates with DEV3 AI, including subject-line ideas.
* Build my sequence with DEV3 AI from your store's recovery numbers.
* Send-time insights from your open/click/order hours.
* Recovery insights: a plain-language read of your dashboard.

= Privacy =

Only the shopper's first name and cart products are sent to Google Gemini for personalised copy; emails and phone numbers are never sent. See docs/index.html for the full privacy notes.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload and activate it (WooCommerce must be active).
2. Open DEV3 AI Abandoned Cart → Settings to choose the consent mode and sending rules.
3. Optional: add your Google Gemini API key under API Config.

== Frequently Asked Questions ==

= Will AI-written emails go out without my approval? =
No. AI drafts wait in the Review queue. If one is not reviewed by its send time, the approved template copy is sent instead (or held, if you choose). Only templates with auto-approve on send AI copy without review.

= Does it work with the block checkout? =
Yes. Capture uses the Store API and the consent checkbox uses WooCommerce's additional checkout fields.

= Do I need a Gemini key? =
No. All recovery features work with your templates. The key adds personalisation and the AI assistants.

== Changelog ==

= 1.0.0 =
* Initial release.
