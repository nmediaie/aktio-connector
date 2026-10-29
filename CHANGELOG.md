# Changelog

## Unreleased

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
