# Changelog

## 0.3.0 — 2026-11-04

### Added
- Affiliate programme for WooCommerce, off by default: partners (code, optional email, own rate, active/paused), links with `?ref=CODE`, coupons assigned in the coupon editor, attribution at classic and block checkout (coupon wins over cookie), commissions that follow the order status (hold period, approval after the hold, refunds, clawback after payout settled by hand), manual payouts with a reference, CSV export.
- **Affiliates** tab in Settings → Aktio Connector (only with WooCommerce, `manage_woocommerce` for the forms): settings with a required consent mode, partners with totals and links, link generator, commissions with bulk actions and filters, CSV export of all pages of the filter (formula cells neutralised), separate clawback balances.
- Field "Affiliate" in the coupon editor (same rules as REST: no taking another affiliate's coupon, no paused affiliate).
- REST `aktio/v1/affiliates*` (affiliates, commissions, link, report, settings) and the public `aktio/v1/ref` click route.
- WordPress personal data exporter and eraser for affiliates by email (erase clears name, email and contact ID, pauses the affiliate, keeps amounts).
- Option to delete all affiliate data on uninstall (off by default: the data is settlement history).

### Documentation
- readme.txt: "Privacy of the affiliate programme" (cookie `aktio_ref`, purpose, lifetime, no IP stored, 10-minute HMAC, consent modes, owner's duty in the self-managed mode), FAQ, known limits (click counts behind a proxy, refund share measured against the order total, custom order statuses, no tracking without JavaScript).
- AI visit measurement is unchanged and still cookieless.

## 0.2.2 — 2026-10-29

### Changed
- Settings screen rebuilt into tabs: Overview, Business, SEO, Store (with WooCommerce), Bots & indexing, AI visibility, Tools and About Aktio. Tabs work without JavaScript (`?tab=` links); with it they switch in place, support arrow keys and stay selected after saving. A validation error opens the tab with the field.
- Overview tab: a to-do list of what hurts how the site looks in search and AI answers (empty site title, default WordPress tagline, search engines discouraged, no logo, no business description, local business without an address), feature status with badges and links to robots.txt, llms.txt and the XML sitemap.
- Pick lists instead of typed codes: content language, address country and merchant countries (WooCommerce country list; the store country is the first option), business type as two described choices, customer service languages as checkboxes (up to 5).
- New fields for data the plugin already supported: opening hours (rows of days and times) and location (latitude, longitude), shown for a local business.
- AI bots as switches with the company behind each bot and what blocking it changes.
- Plain-language descriptions on every tab and card, sticky save bar with an unsaved-changes warning, character counters, Aktio logo and an About Aktio tab with a link to aktioapp.com.
- Polish translation completed (including three strings left untranslated in 0.2.1).

### Fixed
- Empty WordPress site title: `<title>`, `og:site_name`, `og:title` on the home page, `WebSite.name`, the home breadcrumb item, the untitled home `WebPage` and its llms.txt link fall back to the company name from the settings (an SEO plugin used to hide the empty title with its own setting).
- The default WordPress tagline ("Just another WordPress site", "Kolejna witryna WordPress") is no longer used as the home meta description, title tagline or llms.txt description.
- WooCommerce shop page (product archive): meta description, Open Graph and X tags come from the shop page (SEO fields, excerpt or content).
- `<link rel="canonical">` on views where WordPress core prints none: the shop, category/tag/product taxonomy archives (with paging) and a home page listing posts. Not printed on `noindex` views or when an SEO plugin provides `robots_meta`.
- The users sitemap is dropped by the same "single author" rule as the core users sitemap (pages no longer count as authored posts), so a page published by another user no longer exposes the author's archive URL.
- Sitemap: MailPoet system pages (`mailpoet_page`) are excluded; more post types can be excluded with the `aktio_connector_sitemap_excluded_post_types` filter.
- `/llms.txt` and the IndexNow key file are served without a 301 redirect to a trailing-slash URL.

## 0.2.1 — 2026-10-26

### Added
- Multilingual awareness (WPML, Polylang):
  - `GET aktio/v1/status` adds `languages` (`[{code,label}]`) and `multilingual` (`{plugin, langs_count, default_lang}` or `null`). Without a language plugin: `multilingual: null` and one default language from the site locale.
  - `GET aktio/v1/products/scan`: every product has `lang`; optional `lang` parameter; without it all languages are scanned, paged per language (the `per_page` limit is split between languages) with `languages: {code: {total, total_pages, limit}}`. A foreign language on a single-language site gives `400 multilang_not_supported`, an unknown language under WPML/Polylang `400 multilang_unknown_lang`.
  - `GET aktio/v1/media/missing-alt`: items get `lang` when WPML/Polylang is active.
  - `POST aktio/v1/indexnow/sitemap`: optional `urls` (string or list of strings; same host only). The response keeps `queued`, `sent`, `code` and adds `urls` (per URL), `languages` (per language) and `note`. Without `urls` the whole map is submitted as before (once per 24 h), now with the home page of every language.

### Compatibility
- Only fields were added; no existing field was removed or changed.
