=== Aktio Connector ===
Contributors: nmmedia
Tags: seo, schema, llms.txt, ai, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Basic SEO, schema.org, llms.txt, robots.txt for AI bots, AI visit measurement and an optional WooCommerce affiliate programme.

== Description ==

Aktio Connector makes a WordPress site easier for search engines and AI bots to read, and lets you check its health remotely, but only on your terms. Every SEO feature works only when no other SEO plugin already provides it, so nothing is duplicated.

**What the plugin does**

* **schema.org structured data (JSON-LD):** the business (Organization or LocalBusiness, with description, founding date, contact point, opening hours, location and area served), the website, pages, articles and breadcrumbs. With WooCommerce also products (Product, ProductGroup, offers and ratings) and, if you choose to declare them, a default brand, a return policy and fallback shipping details.
* **Basic SEO without an SEO plugin (new in 0.2):** meta description, Open Graph and X (Twitter) cards, custom page title, and `noindex` for internal pages (search results, cart, checkout, account, attachments, empty tags, single-author archives, which are also removed from the WordPress sitemap). Each post, page, product, category and tag gets optional "SEO title" and "SEO description" fields (in the editor, in Quick Edit and on the term screens). If an SEO plugin (or Jetpack for Open Graph) already provides a feature, Aktio Connector stays out of the way.
* **Content language (new in 0.2):** the language of your content (for example `en-IE`) can differ from the language of the admin panel. It is used in `lang`, `og:locale`, schema `inLanguage` and llms.txt. The settings screen warns when the content seems to be in a different language than declared.
* **Cache purge (new in 0.2):** after you save the settings or a product stock status changes, the plugin clears the cache of a detected caching plugin (Cache Enabler, WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer, Breeze, Cloudflare). All active caching plugins are purged. A stock status change purges only the product page, and only for plugins that can clear a single URL (Cache Enabler, WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache); for the others it is skipped. There is also a "Clear now" button.
* **IndexNow (new in 0.2, off by default):** tells Bing and other participating search engines about new, changed and deleted public URLs. Includes a log and a "Submit the whole sitemap" button. Never used on sites that discourage search engines and never for `noindex` addresses. Bing and Google site verification codes can be entered in the settings.
* **AI visit measurement (new in 0.2):** counts visits of AI bots and visits coming from AI assistants and AI search (ChatGPT, Perplexity, Gemini, Copilot, Claude), without cookies. See the privacy section below.
* **Affiliate programme for WooCommerce (new in 0.3, off by default):** partners get a link (`?ref=CODE`) or a coupon; orders are attributed automatically (a partner's coupon wins over the cookie) and a commission is calculated on the order total without tax and shipping. A commission follows the order: completed starts a hold period (30 days by default), then it can be approved; a refund or cancellation reduces or rejects it, and a refund after payout shows as a separate clawback balance. The owner marks payouts by hand and exports CSV. Everything is under the **Affiliates** tab; no panel, accounts or emails for partners. The data stays in your shop. See "Privacy of the affiliate programme" below.
* **FAQ block and shortcode (new in 0.2):** the `aktio/faq` block and the `[aktio_faq]` shortcode (`Q: ...` / `A: ...` line by line) render questions and answers and add FAQPage schema.
* **Catalog scan for the Aktio agent (new in 0.2):** read-only REST routes list products (brands, images, missing ALT texts, description lengths) and images without ALT text, and a route to set ALT texts in bulk (up to 50 per call, with the previous value returned for every item). Available to authenticated users with the right capabilities.
* **Multilingual awareness (new in 0.2.1):** detects WPML and Polylang and reports the active languages (`languages`, `multilingual` in `aktio/v1/status`). The catalog scan returns the language of each product and accepts `lang`; media without ALT text carry a `lang` too; IndexNow submissions are reported per language and per URL. Without a multilingual plugin the site is a single language (the site locale).
* **robots.txt for AI bots:** appends a block for GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot, Google-Extended and Applebot-Extended at the end of robots.txt. Bots are allowed by default and you can block each one in the settings.
* **llms.txt:** serves `/llms.txt` with a short description of the business, a list of pages, recent posts and products. Password-protected and unpublished content is left out.
* **Site Health over REST:** Site Health test results and environment information available to logged-in administrators through `aktio/v1/health`.
* **Updates over REST with a log:** the list of available core, plugin and theme updates and a way to apply them through `aktio/v1/updates`. Every update is written to a log (the last 50 entries) shown in the settings. The plugin never rolls anything back automatically.

You edit everything under **Settings → Aktio Connector**, split into tabs: Overview (what is still worth filling in, which features are provided by this plugin and which by another SEO plugin or a physical file), Business, SEO, Store, Affiliates (with WooCommerce), Bots & indexing, AI visibility, Tools (cache, update log) and About Aktio.

**Privacy and security**

* The plugin sends no telemetry and does not require an account. The only outgoing connection is the optional IndexNow notification, which is off by default (see "External services").
* The affiliate programme is off by default and uses a cookie only after you turn it on (see "Privacy of the affiliate programme"). Everything else in the plugin uses no cookies.
* The REST endpoints require authentication (for example a WordPress application password) and capabilities matching the operation, such as `manage_options` or `update_plugins`. The only public endpoints are the AI visit beacon and, when the affiliate programme is on, the affiliate click route (`aktio/v1/ref`), both described below.
* The plugin stores no secrets. The IndexNow key is a public identifier that must be readable by search engines by design.

**Privacy of AI visit measurement**

* No cookies and no visitor identifiers are used.
* To avoid counting the same visit many times, a short-lived HMAC (keyed hash) of the visitor's IP address and landing path is kept in a transient for 10 minutes. The transient is stored in the database (in the options table, unless the site uses a persistent object cache) and expires on its own; expired ones are cleaned up daily. The IP address itself is never stored, and neither the hash nor the address is written to the metrics table or to any log.
* Behind Cloudflare or another reverse proxy the plugin sees the proxy's address instead of the visitor's, so different visitors to the same page can share one fingerprint within 10 minutes and be counted once. In that setup the referral numbers are an underestimate.
* Counts are stored in the `aktio_metrics` table (day, kind, source name, landing path, count). Rows older than 400 days are deleted automatically.
* At most 200 distinct landing paths are stored per day; further visits that day are counted without a path.
* Only visits from bots and AI sources on the built-in list are counted. A visit from any other source is not recorded at all.
* Page caches can hide bot visits, so the numbers may be lower than the real ones.
* Uninstalling the plugin removes the table and the scheduled tasks.

**Privacy of the affiliate programme**

This section applies only when you turn the affiliate programme on. It is separate from AI visit measurement above, which stays cookieless.

* **Cookie:** `aktio_ref`. Purpose: remember which affiliate referred the visitor so that an order placed later can be attributed to them. It holds the affiliate's code, a timestamp and a signature (`code.timestamp.HMAC`); it contains nothing about the visitor. It is set by the server with `HttpOnly` and `SameSite=Lax` (and `Secure` on HTTPS) only when a visitor opens an affiliate link, and lasts as long as the attribution window you choose (30 days by default, 1 to 365). A new affiliate link replaces it. A forged or expired cookie does not attribute an order.
* **No IP address is stored.** To count one visitor's repeated clicks once, a keyed hash (HMAC) of the visitor's IP address and the affiliate code is kept in a transient for 10 minutes and expires on its own. The address itself is never written anywhere. Daily click counts per affiliate are kept for 400 days, without any visitor identifier.
* **Consent:** the plugin has no consent banner. When you turn the programme on you must choose a mode. "WP Consent API": the link is tracked only after the visitor allows marketing cookies through a plugin that provides the WP Consent API (without such a plugin, links are not tracked; coupons still work). "Self-managed": you confirm that you are responsible for consent on your site, and the filter `aktio_connector_affiliate_can_track` lets you connect your own banner. In the self-managed mode the cookie is set as soon as an affiliate link is opened, so get the legal assessment right for your country (for example ePrivacy rules) before you use it.
* **Personal data of affiliates:** the name and optional email you enter for an affiliate, and the commissions of orders attributed to them (order number, amounts). WordPress's personal data export and erase tools cover an affiliate by email: erasing clears the name, email and contact ID, pauses the affiliate and keeps the commission amounts as a settlement record. The affiliate code is chosen by you and may be built from the name, so check it when you erase someone.
* **Orders:** the plugin reads the order total, billing email (only to detect an affiliate buying from their own link; it is not stored) and coupon codes through the WooCommerce order API. It adds no personal data to the order.
* **Uninstalling** the plugin keeps the affiliate tables (partners, commissions, click counts), because they are your settlement history, unless you tick "Delete all affiliate data when the plugin is deleted" in the Affiliates tab. The scheduled task and the short-lived transients are always removed.
* **Limits to know about:** the link works through a small script, so visitors with JavaScript turned off are not tracked. The cookie is set by the server in an HTTP header (not by JavaScript), which is the case Safari's tracking prevention treats most gently, but the cookie lifetime in Safari was not verified in testing (no WebKit browser was available), so in Safari the attribution may last shorter than the window you set. Click counts are an underestimate behind Cloudflare or another reverse proxy, because the 10-minute deduplication sees the proxy's address instead of the visitor's. A refund reduces the commission by its share of the goods: shipping and shipping tax are left out, so refunding shipping does not change the commission and refunding all the goods leaves none. Mark the refunded shipping line in the refund: a refund entered as a plain amount counts entirely as goods. Refunds are compared with the order daily for commissions up to 180 days old, including approved and paid ones; an older refund that WooCommerce did not report to the plugin (for example while the plugin was off) needs manual handling. A shop whose orders end in a custom status after "completed" approves commissions manually: approval works once the order has passed "completed", while the daily task still approves only orders in "completed". Commissions are not created for orders added by hand in the admin or for subscription renewals.

**Working with Aktio**

Aktio (aktioapp.com) is a tool for managing content and visibility of a site. If you use it, it connects to your site through REST with an application password, and Aktio initiates every connection. Without Aktio the plugin works fully on its own.

== External services ==

This plugin can connect to the following external services. Neither is required.

**IndexNow (optional, off by default)**

IndexNow (indexnow.org) is a protocol that lets a website tell search engines (for example Bing) which of its pages changed, so they can be crawled sooner.

* What it is used for: notifying search engines about new, updated or deleted public pages, products and categories.
* When: only after the site owner (or Aktio, with the owner's explicit consent) turns IndexNow on in the settings. Notifications are sent in the background about every 5 minutes, and when you click "Submit the whole sitemap" (at most once a day). Nothing is sent when the site is set to discourage search engines or for pages marked `noindex`.
* What data is sent: only the site host name, the IndexNow key (a random string generated by the plugin and also served publicly at `/<key>.txt` as the protocol requires) and the list of changed page URLs. No personal data, no content and no visitor data are sent.
* Service: https://api.indexnow.org/indexnow
* Terms of use: https://www.indexnow.org/terms
* Privacy policy: https://www.indexnow.org/privacy

**Aktio (optional)**

Aktio (https://aktioapp.com) is a service for managing the content and visibility of a site. The plugin never connects to Aktio by itself and sends nothing to it. If you use Aktio, Aktio connects to your site's REST API with an application password and initiates every connection. Without Aktio the plugin works fully on its own.

== Installation ==

1. Upload the `aktio-connector` directory to `/wp-content/plugins/` or install the plugin from the dashboard.
2. Activate it on the **Plugins** screen.
3. Fill in the business details under **Settings → Aktio Connector**.

== Frequently Asked Questions ==

= Does the plugin connect to any server or require an account? =

No account is needed. By default the plugin connects to no server and sends no telemetry. The only outgoing connection is the optional IndexNow notification (https://api.indexnow.org), which is off by default and sends only page URLs and the IndexNow key. A connection with Aktio, if you use it, is initiated only by Aktio, through your site's REST API.

= I already have an SEO plugin (Yoast SEO, Rank Math, All in One SEO and others). Will the data be duplicated? =

No. If an active SEO plugin or a physical file in the site directory already provides a feature (schema, llms.txt, title, meta description, Open Graph, X cards, robots meta), Aktio Connector does not duplicate it, and the "SEO title" and "SEO description" fields are hidden. Jetpack with Open Graph enabled provides only Open Graph and X cards. The settings screen shows who provides each feature. SEO plugins that are installed but not active are ignored.

= My SEO plugin builds its own robots.txt. Where does the AI bot block go? =

Aktio Connector does not replace the robots.txt generated by your SEO plugin; it only appends rules for the bots you set to "Block" (`User-agent: <bot>` with `Disallow: /`), between `# BEGIN Aktio Connector` and `# END Aktio Connector` comments. Allowed bots get no group of their own, so they follow your `User-agent: *` rules. If robots.txt already names a bot in a `User-agent:` line (for example a rule you set in the SEO plugin), Aktio Connector adds nothing for that bot, so your own rule is never overridden; change it in the SEO plugin instead. When no bot is blocked, robots.txt is left unchanged. A physical robots.txt file in the site directory takes precedence and Aktio Connector adds nothing to it.

= Are updates performed without my knowledge? =

No. Updates over REST are performed only by an authenticated user with the right capabilities, and each one is written to the log in the settings. The plugin never turns on automatic updates by itself: automatic updates are changed only by an authorized user, including Aktio over REST with the site owner's consent. The plugin does not roll changes back automatically, so make a backup before updating.

= Can I block AI bots? =

Yes. Under **Settings → Aktio Connector** choose "Block" next to any of the six bots. The block is not added when the site is set to discourage search engines.

= Does the plugin change what I type in the Merchant fields? =

The default brand goes through WordPress text sanitization: HTML tags are stripped and surrounding whitespace is trimmed (up to 100 characters are accepted). Other fields are not corrected: invalid values (for example a wrong country code or a negative cost) are rejected with an error and nothing is saved, because this data is declared to Google. An empty country means the country of your WooCommerce store, including when you change it later.

= Does AI visit measurement use cookies or store IP addresses? =

No cookies, no visitor identifiers and no stored IP addresses. See "Privacy of AI visit measurement" above. This is about AI visit measurement only: the optional affiliate programme, when you turn it on, sets the `aktio_ref` cookie as described in "Privacy of the affiliate programme".

= Does the affiliate programme work with a page cache? =

Yes. The link is read by a small script in the browser, so a cached page still sets the cookie. The `ref` parameter is removed from the address after the click, so the canonical URL and shared links stay clean.

= What happens to affiliate data when I delete the plugin? =

It stays in the database unless you tick "Delete all affiliate data when the plugin is deleted" in the Affiliates tab before deleting the plugin.

== Changelog ==

= 0.3.0 =
* New: affiliate programme for WooCommerce (off by default): partners with codes, links (`?ref=`) and coupons, attribution at classic and block checkout, commissions that follow order status (hold period, approval, refunds, clawback after payout), manual payouts, CSV export and the **Affiliates** tab in the settings.
* New: REST routes `aktio/v1/affiliates*` for Aktio, and the public click route `aktio/v1/ref`.
* New: WordPress personal data export and erase tools cover affiliates by email.
* New: "Privacy of the affiliate programme" section; AI visit measurement is unchanged and still cookieless.

= 0.2.2 =
* New: settings screen in tabs (Overview with a to-do list, Business, SEO, Store, Bots & indexing, AI visibility, Tools, About Aktio), pick lists instead of typed codes, opening hours and location fields, AI bots as switches with descriptions, Aktio logo.
* Fix: an empty WordPress site title falls back to the company name (title, Open Graph, schema.org, llms.txt) and the default WordPress tagline is ignored.
* Fix: meta description, Open Graph and canonical URL on the WooCommerce shop page; canonical URL on category and tag archives.
* Fix: sitemap without MailPoet system pages and with the users sitemap removed for single-author sites (pages no longer count); `/llms.txt` without a trailing-slash redirect.

= 0.2.1 =
* New: multilingual awareness (WPML, Polylang): `languages` and `multilingual` in the status, `lang` on catalog scan products (and an optional `lang` filter with per-language paging), `lang` on media without ALT text, and per-URL / per-language reports for IndexNow submissions (a URL or a list of URLs can be submitted).

= 0.2.0 =
* New: basic SEO without an SEO plugin: meta description, Open Graph, X cards, custom title and `noindex` for internal pages, with "SEO title" and "SEO description" fields for posts, pages, products and categories. Everything switches off for features already provided by an SEO plugin (or Jetpack Open Graph).
* New: content language independent of the admin language, with a warning when the content seems to be in another language.
* New: cache purge for popular caching plugins after saving the settings or a stock status change, plus a "Clear now" button.
* New: IndexNow (off by default) with a log and "Submit the whole sitemap", and Bing / Google site verification codes.
* New: cookieless measurement of AI bot visits and visits from AI assistants (`aktio/v1/metrics`).
* New: FAQ block and `[aktio_faq]` shortcode with FAQPage schema.
* New: extended business data in schema.org and llms.txt (description, founding date, contact point, opening hours, location, area served).
* New: catalog scan and bulk ALT text routes for the Aktio agent.
* New: settings screen with all of the above, Polish translation updated.

= 0.1.2 =
* WooCommerce: new Merchant section in the settings (also available to Aktio over `aktio/v1/visibility`): a default brand for products without their own brand, a declared return policy (`hasMerchantReturnPolicy`) and fallback shipping details (`shippingDetails`) used only when WooCommerce shipping zones give nothing. Both declarations are off by default and invalid values are rejected rather than corrected.
* Nothing from the Merchant section is added when an SEO plugin provides the product schema. Without WooCommerce the section only shows a note. An unset country follows the WooCommerce store country, and unknown fields in `merchant` are rejected.

= 0.1.1 =
* robots.txt: only bots set to "Block" get a group (`Disallow: /`); allowed bots follow `User-agent: *`, and bots already named in robots.txt (for example by an SEO plugin) are left untouched.
* Settings screen: when an SEO plugin generates robots.txt, the status says Aktio Connector adds AI-bot rules to it.
* WooCommerce: a single BreadcrumbList on product pages when Aktio Connector provides the content schema.
* Uninstall also removes the core auto-update option and the llms.txt cache.

= 0.1.0 =
* First release: schema.org, AI bots in robots.txt, llms.txt, Site Health and updates over REST with a log, settings screen.

== Upgrade Notice ==

= 0.3.0 =
Adds an optional affiliate programme (off by default). Three database tables are created on update. Nothing changes until you turn it on.

= 0.2.0 =
Adds basic SEO, IndexNow (off by default), AI visit measurement and more. A database table is created on update.
