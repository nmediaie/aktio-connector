# Changelog

## 0.2.1 — 2026-10-26

### Added
- Multilingual awareness (WPML, Polylang):
  - `GET aktio/v1/status` adds `languages` (`[{code,label}]`) and `multilingual` (`{plugin, langs_count, default_lang}` or `null`). Without a language plugin: `multilingual: null` and one default language from the site locale.
  - `GET aktio/v1/products/scan`: every product has `lang`; optional `lang` parameter; without it all languages are scanned, paged per language (the `per_page` limit is split between languages) with `languages: {code: {total, total_pages, limit}}`. A foreign language on a single-language site gives `400 multilang_not_supported`, an unknown language under WPML/Polylang `400 multilang_unknown_lang`.
  - `GET aktio/v1/media/missing-alt`: items get `lang` when WPML/Polylang is active.
  - `POST aktio/v1/indexnow/sitemap`: optional `urls` (string or list of strings; same host only). The response keeps `queued`, `sent`, `code` and adds `urls` (per URL), `languages` (per language) and `note`. Without `urls` the whole map is submitted as before (once per 24 h), now with the home page of every language.

### Compatibility
- Only fields were added; no existing field was removed or changed.
