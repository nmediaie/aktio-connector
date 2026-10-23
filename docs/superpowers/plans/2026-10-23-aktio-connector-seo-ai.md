# Aktio Connector 0.2 — SEO pod wyszukiwarki AI — plan implementacji

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wtyczka 0.2.0 dostarcza podstawowe SEO bez Yoasta (meta, Open Graph/X, tytuły, noindex), język treści, czyszczenie cache, IndexNow z weryfikacją, pomiar AI, blok FAQ i rozszerzone dane firmy; agent Aktio dostaje masowy przegląd i poprawki katalogu (marki, ALT) oraz obsługę nowych ustawień.

**Architecture:** Część P to repo wtyczki `nmediaie/aktio-connector` (PHP, nowe moduły jako osobne pliki w `includes/`, wszystkie bramkowane `Detector::owns()`). Część A to repo Aktio: `integration-proxy` (nowe narzędzia na trasach `aktio/v1` i `wc/v3`), skill `wordpress-admin`, dokumentacja. Bez migracji bazy, bez zmian edge functions i web (D8).

**Tech Stack:** PHP ≥ 7.4, WordPress ≥ 6.2, WooCommerce ≥ 9.6 dla marek (D6), PHPUnit w wp-env; Node 22 ESM + Vitest (proxy).

**Spec:** `docs/superpowers/specs/2026-10-23-aktio-connector-seo-ai-design.md` (D1–D8)

## Global Constraints

- Każda funkcja SEO działa tylko, gdy `Detector::owns(<feature>)`; przy wtyczce SEO/Jetpack-OG nic nie dublujemy (D2). Nowe funkcje: `title`, `meta_description`, `open_graph`, `twitter_card`, `robots_meta`.
- Pola „Tytuł SEO”/„Opis SEO” tylko gdy Aktio jest właścicielem `title`/`meta_description`; inaczej boks ukryty, zapis przez REST → błąd `aktio_seo_owned_elsewhere` (D5).
- Meta: `_aktio_seo_title` (≤ 70 znaków), `_aktio_seo_description` (≤ 160 znaków); termy: te same klucze w term meta.
- Opis meta generowany: ≤ 155 znaków, bez HTML i shortcode’ów, cięcie na granicy słowa.
- IndexNow domyślnie wyłączony; włącza właściciel albo agent po „tak” (D7); nigdy przy `blog_public = 0` ani dla adresów `noindex`.
- Pomiar: bez cookies i identyfikatorów; IP tylko jako ulotny HMAC w transiencie 10 min do deduplikacji, nigdy w tabeli ani logach; tabela `{prefix}aktio_metrics`, retencja 400 dni, limit 200 ścieżek/dzień.
- Marki: tworzenie przez `POST wc/v3/products/brands` za zgodą WooCommerce `write`, wymagany WC ≥ 9.6; inaczej narzędzie zwraca `brands_unsupported` (D6).
- Zapisy masowe: ≤ 50 elementów na wywołanie, osobny dławik 10 wywołań/min/organizację, wynik per element z `previous`; opis narzędzia: lista w rozmowie i zapis po wyraźnym „tak”.
- Napisy UI wtyczki: EN źródło + pl_PL; Aktio: po polsku. Wtyczka: wszystkie funkcje bez konta (D1); readme „External services”: IndexNow, Aktio.
- Bez migracji, bez edge fn/web, bez `release.sh` (D8).
- Wtyczka: testy wp-env, `php -l` na php:7.4-cli, Plugin Check bez ERROR w plikach wydania. Aktio: worktree `/home/norbert/projekty/aktio-wt-seo-ai`, gałąź `feat/connector-seo-ai`.

## Review Focus

1. **Yoast nieaktywny, ale Jetpack z modułem Open Graph aktywny** — Aktio wypisuje meta description, ale NIE `og:*` (Jetpack je daje). Test w P1 i P3.
2. **Produkt bez zdjęcia i strona bez wyróżnionego obrazu** — `og:image` z logo firmy → obrazka domyślnego z ustawień → pominięty (nigdy pusty `content=""`). Test w P3.
3. **Strona prywatna (`blog_public = 0`) lub adres z `noindex`** — IndexNow nic nie wysyła, nawet przy włączonym module i „Zgłoś całą mapę”. Test w P6.
4. **Ten sam beacon wysłany 20 razy w minutę z jednego IP** — licznik +1; z różnych źródeł spoza listy AI — 0. Test w P7.
5. **Paczka 50 produktów, z których jeden nie istnieje** — 49 zapisanych, jeden z błędem w wyniku, `previous` dla zapisanych; całość nie wywraca narzędzia. Test w A2.

---

# Część P — wtyczka (`/home/norbert/projekty/aktio-connector`, od `c29708d` = v0.1.2)

Nowe pliki: `includes/Seo/Head.php` (meta/OG/X/tytuł/robots), `includes/Seo/Fields.php` (meta per obiekt + boks edytora), `includes/Language.php`, `includes/CachePurge.php`, `includes/IndexNow.php`, `includes/Metrics.php` (+ `assets/aktio-beacon.js`), `includes/Faq.php` (+ `blocks/faq/block.json`, `blocks/faq/index.js` bez bundlera), `includes/Catalog.php` (skan produktów i mediów dla agenta).

### Task P1: Detector — funkcje SEO i Jetpack

**Files:** Modify `includes/Detector.php` (FEATURES, map); Test `tests/DetectorTest.php`

**Interfaces:**
- Produces: `Detector::FEATURES` rozszerzone o `title`, `meta_description`, `open_graph`, `twitter_card`, `robots_meta`; `Detector::owns()` działa dla nich; `status.seo` = `features` ograniczone do tych 5 kluczy.

- [ ] **Step 1: Testy:** `test_seo_features_active_without_seo_plugin` (wszystkie 5 → `active`); `test_yoast_provides_all_seo_features` (Yoast, Rank Math, SEOPress, AIOSEO, TSF → `provided`); `test_jetpack_og_provides_open_graph_and_twitter_only` (Jetpack aktywny + `jetpack_enable_open_graph` → `open_graph`, `twitter_card` = `provided`, `meta_description` = `active`; Review Focus 1); `test_jetpack_without_og_module_provides_nothing`.
- [ ] **Step 2: Uruchom** → FAIL.
- [ ] **Step 3: Implementacja** w mapie (SEO wtyczki: 5 nowych kluczy `true`; nowy wpis Jetpack `jetpack/jetpack.php` z warunkiem `apply_filters('jetpack_enable_open_graph', Jetpack::is_module_active('publicize') || Jetpack::is_module_active('sharedaddy'))` za `class_exists`). `Rest::status` dodaje `seo`.
- [ ] **Step 4: Uruchom** → PASS. **Step 5: Commit** `feat: wykrywanie funkcji SEO (meta, OG, tytuł, robots) i Jetpacka`.

### Task P2: Ustawienia 0.2 i walidacja

**Files:** Modify `includes/Settings.php`, `includes/Rest.php` (`visibility`, `visibility_update`); Test `tests/SettingsTest.php`, `tests/RestVisibilityTest.php`

**Interfaces:**
- Produces nowe klucze ustawień (GET/POST `visibility.settings`), walidacja ścisła jak `merchant` (nieznany klucz/zły typ → 400 `aktio_invalid_params` z ścieżką):
  - `seo`: `{home_description: string ≤160, default_og_image: https URL|'' }`
  - `content_language`: `''` (= język witryny) albo BCP-47 `^[a-z]{2}(-[A-Z]{2})?$`
  - `indexnow`: `{enabled: bool (false), key: string — generowany, tylko do odczytu}`
  - `verification`: `{bing: ^[A-F0-9]{32}$|'' , google: ^[A-Za-z0-9_-]{20,100}$|''}`
  - `company` rozszerzone: `description ≤300`, `founding_date` `YYYY-MM-DD|''`, `contact_point: {telephone, email, contact_type: 'customer service', languages: string[] ≤5}`, `opening_hours: [{days: subset of Mo..Su, opens: HH:MM, closes: HH:MM}] ≤7` (tylko LocalBusiness), `geo: {lat, lng}|null`, `area_served: string ≤100`.
- `Settings::get()` zwraca efektywny `content_language` (pusty → `str_replace('_','-',get_locale())`).

- [ ] **Step 1: Testy** dla każdego klucza: domyślne, poprawny zapis częściowy, każdy błędny typ/zakres → 400 bez zmian, `indexnow.key` niezmienialny z zewnątrz, klucz IndexNow wygenerowany przy pierwszym `get()` (32 hex). **Step 2:** FAIL. **Step 3:** implementacja na wzór `merchant_errors`/`merchant_apply`. **Step 4:** PASS. **Step 5: Commit** `feat: ustawienia 0.2 (SEO, język, IndexNow, weryfikacja, firma)`.

### Task P3: Meta, Open Graph, X, tytuł, noindex

**Files:** Create `includes/Seo/Head.php`; Modify `aktio-connector.php`; Test `tests/SeoHeadTest.php`

**Interfaces:**
- Consumes: `Detector::owns()`, `Settings::get()`, `Graph::plain()`, `Language::current()` (P4 — do czasu P4 użyj `Settings::get()['content_language']`), `Fields::title_for()/description_for()` (P3b).
- Produces: `Seo\Head::description(): string`, `Seo\Head::og(): array<string,string>`, `Seo\Head::noindex(): bool`, `Seo\Head::noindex_for_post(WP_Post $p): bool` (używane przez P6); filtr `aktio_connector_og_tags`.

- [ ] **Step 1: Testy:** `test_description_order` (własny opis → krótki opis produktu → zajawka → treść; strona główna: `seo.home_description` → `blogdescription`; kategoria: opis terminu); `test_description_trimmed_to_155_on_word_boundary_without_html_shortcodes`; `test_og_tags_for_post_product_home` (`og:type` article/product/website; produkt ma `product:price:amount`, `product:price:currency`, `product:availability` = `in stock|out of stock|preorder`); `test_og_image_fallback_chain` (wyróżniony/główne zdjęcie → logo firmy → `default_og_image` → brak tagu; Review Focus 2); `test_og_image_has_width_height_when_attachment`; `test_twitter_card_summary_large_image`; `test_custom_title_via_pre_get_document_title`; `test_noindex_on_search_cart_checkout_account_attachments_empty_tags_single_author`; `test_noindex_urls_removed_from_wp_sitemap`; `test_nothing_when_feature_provided_elsewhere` (dla każdej z 5 funkcji); `test_password_protected_has_no_description_from_content`.
- [ ] **Step 2:** FAIL. **Step 3:** `wp_head` priorytet 1 (meta, OG, X, weryfikacja z P6 dołącza się tym samym hookiem); `wp_robots` filtr dla noindex; `wp_sitemaps_posts_query_args`/`wp_sitemaps_taxonomies_query_args`/`wp_sitemaps_add_provider` (users przy jednym autorze). Wszystkie wartości `esc_attr`. **Step 4:** PASS. **Step 5: Commit** `feat: opis meta, Open Graph, X, tytuł i noindex bez wtyczki SEO`.

### Task P3b: Pola SEO per obiekt i boks edytora

**Files:** Create `includes/Seo/Fields.php`, `assets/aktio-seo-panel.js` (panel w edytorze blokowym, `wp.plugins` + `wp.editPost.PluginDocumentSettingPanel`, bez bundlera); Test `tests/SeoFieldsTest.php`

**Interfaces:**
- Produces: `Fields::title_for(WP_Post|WP_Term): string`, `Fields::description_for(WP_Post|WP_Term): string`; meta zarejestrowane `register_post_meta('', '_aktio_seo_title'|'_aktio_seo_description', show_in_rest, single, string)` dla `post`, `page`, `product` i `register_term_meta` dla `category`, `post_tag`, `product_cat`, `product_tag`; `auth_callback`: `current_user_can('edit_post', $id)` **i** `Detector::owns('title'|'meta_description')` — inaczej REST zwraca błąd z kodem `aktio_seo_owned_elsewhere` (filtr `rest_pre_insert_*` / `sanitize_callback` zwracający `WP_Error`).

- [ ] **Step 1: Testy:** zapis przez `POST /wp/v2/posts/<id>` z `meta._aktio_seo_title` działa i przycina do 70/160; przy aktywnym Yoaście → błąd `aktio_seo_owned_elsewhere`, meta bez zmian (D5); produkt przez `wc/v3/products/<id>` z `meta_data` → zapis lub ten sam błąd; boks klasyczny (`add_meta_box`) i szybka edycja zapisują z nonce; panel blokowy zarejestrowany tylko gdy Aktio jest właścicielem. **Step 2–4.** **Step 5: Commit** `feat: tytuł i opis SEO dla wpisów, stron, produktów i kategorii`.

### Task P4: Język treści strony

**Files:** Create `includes/Language.php`; Modify `includes/Crawlers.php` (nagłówki llms.txt), `includes/Schema/ContentSchema.php` (`inLanguage`); Test `tests/LanguageTest.php`

**Interfaces:**
- Produces: `Language::current(): string` (np. `en-IE`), `Language::warning(): ?array{expected:string, detected:string}`, `Language::multilingual_plugin(): ?string`.

- [ ] **Step 1: Testy:** `language_attributes` na froncie = `lang="en-IE"` przy panelu `pl_PL`, a w panelu bez zmian; `og:locale` = `en_IE`; `inLanguage` w WebSite/WebPage; nagłówki `llms.txt` wg `Language::current()` (pl* → polskie, inne → angielskie); `warning` wykrywa `pl` przy ustawionym `en` (heurystyka: słowa funkcyjne z list pl/en/de/fr/es w treści 5 ostatnich publicznych wpisów/produktów, próg 60% przewagi) i zwraca `null` przy zgodności; przy aktywnym WPML/Polylang/TranslatePress Language nie zmienia `lang` i zwraca nazwę wtyczki; GTranslate bez osobnych adresów → `status.language_warning` = `gtranslate_js`. **Step 2–4.** **Step 5: Commit** `feat: język treści strony niezależny od języka panelu`.

### Task P5: Czyszczenie cache

**Files:** Create `includes/CachePurge.php`; Test `tests/CachePurgeTest.php`

**Interfaces:**
- Produces: `CachePurge::detected(): ?string`, `CachePurge::all(): bool`, `CachePurge::url(string $url): bool`, `CachePurge::last(): ?string` (ISO czas); hook na `aktio_connector_settings_updated`, `woocommerce_product_set_stock_status`, `woocommerce_variation_set_stock_status`.

- [ ] **Step 1: Testy** z atrapami funkcji/akcji każdej wtyczki (zdefiniowane w teście): Cache Enabler (`do_action('cache_enabler_clear_complete_cache')`, URL: `cache_enabler_clear_page_cache_by_url`), WP Rocket (`rocket_clean_domain`, `rocket_clean_files`), LiteSpeed (`litespeed_purge_all`, `litespeed_purge_url`), W3TC (`w3tc_flush_all`, `w3tc_flush_url`), WP Super Cache (`wp_cache_clear_cache`), WP Fastest Cache (`wpfc_clear_all_cache`), SiteGround (`sg_cachepress_purge_cache`), Breeze (`do_action('breeze_clear_all_cache')`), Cloudflare (`do_action('cloudflare_purge_everything')` jeśli zarejestrowane) — implementer weryfikuje nazwy w kodzie tych wtyczek i opisuje rozbieżności; zapis ustawień → `all()` raz; zmiana stanu produktu → `url(permalink)`; brak wtyczki cache → nic, bez błędu; wyjątek w wywołaniu połknięty. `status.cache` = `{plugin, last_purge}`. **Step 2–4.** **Step 5: Commit** `feat: czyszczenie cache po zmianach ustawień i stanu magazynowego`.

### Task P6: IndexNow i kody weryfikacyjne

**Files:** Create `includes/IndexNow.php`; Test `tests/IndexNowTest.php`

**Interfaces:**
- Consumes: `Settings::get()['indexnow']`, `Seo\Head::noindex_for_post(WP_Post): bool` (P3).
- Produces: `IndexNow::queue(string $url)`, `IndexNow::flush(): array{sent:int, code:int|null}` (cron `aktio_connector_indexnow` co 5 min), `IndexNow::submit_sitemap(): array`, `IndexNow::log(int $n=10)`; plik klucza `/<key>.txt` przez rewrite; endpoint `POST aktio/v1/indexnow/sitemap` (`manage_options`).

- [ ] **Step 1: Testy** (HTTP przez `pre_http_request`): publikacja/aktualizacja/usunięcie wpisu, strony, produktu, terminu → URL w kolejce; flush wysyła jeden POST na `https://api.indexnow.org/indexnow` z `{host, key, keyLocation, urlList}` (≤ 10 000); wyłączony moduł → nic; `blog_public=0` → nic (Review Focus 3); adres `noindex`/szkic/hasło → pominięty; `/<key>.txt` zwraca klucz `text/plain`; `submit_sitemap` raz na 24 h (drugi raz → błąd `aktio_rate_limited`); dziennik 50 ostatnich; `<meta name="msvalidate.01">` i `google-site-verification` gdy ustawione (Google tylko gdy brak Site Kit — `google-site-kit/google-site-kit.php` aktywny → pomiń). **Step 2–4.** **Step 5: Commit** `feat: IndexNow i kody weryfikacyjne Bing/Google`.

### Task P7: Pomiar AI

**Files:** Create `includes/Metrics.php`, `assets/aktio-beacon.js`; Modify `uninstall.php`; Test `tests/MetricsTest.php`

**Interfaces:**
- Produces: tabela `{prefix}aktio_metrics` (`day DATE, kind VARCHAR(16) 'bot'|'referral', key VARCHAR(64), path VARCHAR(191) DEFAULT '', count INT`, unikalny `(day,kind,key,path)`), tworzona `dbDelta` przy aktywacji i przy `plugins_loaded` gdy wersja schematu < 1; `Metrics::record_bot(string $ua): void` (na `template_redirect` i przy `llms.txt`/`robots.txt`/sitemap), `POST aktio/v1/beacon` (publiczny, bez nonce), `GET aktio/v1/metrics?days=30` (`manage_options`) → `{bots: {name: [[day,count]...]}, referrals: {source: [[day,count]...]}, top_paths: [{path, count}] ≤10, note: 'cache_undercount'}`; cron dzienny czyści > 400 dni.
- Listy: boty `GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-User, PerplexityBot, Perplexity-User, bingbot, Applebot, Amazonbot, meta-externalagent, CCBot, Bytespider`; źródła `chatgpt.com, perplexity.ai, gemini.google.com, copilot.microsoft.com, claude.ai` (Referer host albo `utm_source`).

- [ ] **Step 1: Testy:** UA bota → +1 dla właściwej nazwy, UA zwykłej przeglądarki → 0; beacon z Referer `https://chatgpt.com/` → +1 `referral/chatgpt.com` ze ścieżką; źródło spoza listy → 0; 20 identycznych beaconów z jednego IP w 10 min → +1 (Review Focus 4); globalny limit 60 beaconów/min → 429; > 200 ścieżek dziennie → dalsze liczone bez ścieżki (`path=''`); nigdzie w tabeli ani opcjach nie ma IP (asercja na zawartości tabeli i `wp_options` z prefiksem `aktio`); retencja; odinstalowanie usuwa tabelę i crony; skrypt beaconu dołączany tylko na froncie, `defer`, ≤ 1 KB, bez zewnętrznych adresów. **Step 2–4.** **Step 5: Commit** `feat: pomiar wizyt botów AI i ruchu z wyszukiwarek AI`.

### Task P8: Blok FAQ

**Files:** Create `includes/Faq.php`, `blocks/faq/block.json`, `blocks/faq/index.js`; Test `tests/FaqTest.php`

**Interfaces:**
- Produces: blok `aktio/faq` (atrybut `items: [{q, a}]`, render po stronie serwera: `<div class="aktio-faq"><details><summary>q</summary><div>a</div></details>…`), shortcode `[aktio_faq]` z zawartością w formacie `Q: …` / `A: …` linia po linii; węzeł `FAQPage` z `mainEntity` dodawany przez `Graph::FILTER` tylko gdy `Detector::owns('content_schema')`.

- [ ] **Step 1: Testy:** render bloku i shortcode’u (escaping, `wp_kses_post` w odpowiedzi); FAQPage z pytaniami w grafie jedna strona = jeden węzeł nawet przy dwóch blokach; przy Yoaście treść jest, schema brak; pusta lista → nic. **Step 2–4.** **Step 5: Commit** `feat: blok i shortcode FAQ ze schema FAQPage`.

### Task P9: Encja marki

**Files:** Modify `includes/Schema/ContentSchema.php`, `includes/Crawlers.php`, `includes/Seo/Head.php`; Test `tests/ContentSchemaTest.php`, `tests/CrawlersTest.php`

- [ ] **Step 1: Testy:** Organization/LocalBusiness dostaje `description`, `foundingDate`, `contactPoint` (`ContactPoint` z `contactType`, `availableLanguage`), dla LocalBusiness `openingHoursSpecification`, `geo` (`GeoCoordinates`), `areaServed` — tylko pola niepuste; `company.description` używany w `llms.txt` (linia `> …`) i jako zapasowy opis meta strony głównej (po `seo.home_description`, przed `blogdescription`). **Step 2–4.** **Step 5: Commit** `feat: rozszerzone dane firmy w schema, llms.txt i opisie strony`.

### Task P10: Dane katalogu dla agenta

**Files:** Create `includes/Catalog.php`; Modify `includes/Rest.php`; Test `tests/CatalogTest.php`

**Interfaces:**
- Produces: `GET aktio/v1/products/scan?page=&per_page≤100` (`edit_products`) → `{items: [{id, name, brands: string[], images: int, images_missing_alt: int, short_len, desc_len, sku, gtin, weight, categories: string[]}], total, total_pages, brands_supported: bool}` (`brands_supported` = `taxonomy_exists('product_brand')`); `GET aktio/v1/media/missing-alt?page=&per_page≤100` (`upload_files`) → `{items: [{id, url_thumb, title, filename, attached_to: {id, type, title}|null}], total}`; `POST aktio/v1/media/alt` (`upload_files` i `edit_post` dla każdego) body `{items: [{id, alt}] ≤50}` → `{results: [{id, ok, previous?, error?}]}`; ALT ≤ 300 znaków, bez HTML.

- [ ] **Step 1: Testy:** skan liczy zdjęcia produktu (główne + galeria) i braki ALT; `brands_supported=false` bez taksonomii; media/missing-alt pomija obrazy z ALT i nie-obrazy; zapis 3 ALT z jednym nieistniejącym id → 2 ok, 1 `aktio_not_found`, `previous` dla zapisanych; > 50 → 400; użytkownik bez `upload_files` → 403. **Step 2–4.** **Step 5: Commit** `feat: skan katalogu i masowe ALT dla agenta`.

### Task P11: Ekran ustawień, readme, wydanie 0.2.0

**Files:** Modify `includes/Admin/SettingsPage.php`, `readme.txt`, `aktio-connector.php`, `languages/*`; Test `tests/SettingsPageTest.php`

- [ ] **Step 1: Testy:** nowe sekcje (SEO: opis strony głównej, domyślny obrazek OG z biblioteki mediów; Język treści + ostrzeżenie; Firma rozszerzona; IndexNow: przełącznik z opisem usługi, dziennik, „Zgłoś całą mapę”; Weryfikacja; Cache: wykryta wtyczka, ostatnie czyszczenie, przycisk „Wyczyść teraz”; Pomiar AI: tabela 30 dni + top ścieżki + nota o cache) — nonce, `manage_options`, escaping; stan funkcji pokazuje nowe klucze z alertami `provided_by`. **Step 2–4.**
- [ ] **Step 5:** `readme.txt`: opis funkcji 0.2, sekcja „External services” (IndexNow: co, kiedy, dane = adresy URL, regulamin/polityka indexnow.org; Aktio: opcjonalne połączenie), sekcja prywatności pomiaru; wersja 0.2.0 (stała, nagłówek, Stable tag, changelog); `.pot/.po/.mo` (pl_PL komplet); pełny PHPUnit, `php -l` 7.4, Plugin Check bez ERROR w plikach wydania; tag lokalny `v0.2.0`, zip `dist/aktio-connector-0.2.0.zip`. **Commit** `chore: wersja 0.2.0`.

---

# Część A — Aktio (`/home/norbert/projekty/aktio-wt-seo-ai`, gałąź `feat/connector-seo-ai` od `origin/main`)

### Task A1: Proxy WordPress — nowe ustawienia, pola SEO, metryki, ALT

**Files:** Modify `integration-proxy/lib/wordpress.js`, `integration-proxy/wordpress.test.js`

**Interfaces:**
- Consumes: kontrakt P2, P3b, P6, P7, P10.
- Produces: `visibilityBody` przyjmuje i ściśle waliduje `seo`, `content_language`, `indexnow` (tylko `enabled`), `verification`, rozszerzone `company` (zasady jak P2); `wp_visibility_get` projekcja nowych pól; `wp_post_update`/`wp_page_update` przyjmują `seo_title` (≤70) i `seo_description` (≤160) → `meta._aktio_seo_*`, błąd strony `aktio_seo_owned_elsewhere` → `seo_owned_elsewhere` z wyjaśnieniem; nowe narzędzia: `wp_catalog_scan` (`GET aktio/v1/products/scan`, odczyt; brak Connectora → `connector_missing`), `wp_metrics_get` (`GET aktio/v1/metrics`, `days` 1–400), `wp_indexnow_submit_sitemap` (zapis, dławik zapisu), `wp_media_scan` (`GET aktio/v1/media/missing-alt`), `wp_media_alt_batch` (`POST aktio/v1/media/alt`, zgoda `publish`, ≤50, dławik batch 10/min); `REST_ALLOW` rozszerzone wyłącznie o te trasy (zakotwiczone, bez parametrów w ścieżce).
- Opisy narzędzi zapisu (`wp_visibility_set` dla `indexnow.enabled`, `wp_media_alt_batch`): pokaż dokładne wartości / listę w rozmowie i czekaj na wyraźne „tak” (D7, anty-cel).

- [ ] **Step 1: Testy:** walidacja każdego nowego pola (poprawne przechodzą dokładnie, błędne → `invalid_params`, zero fetch); `wp_media_alt_batch` bez zgody `publish` → odmowa przed ruchem i brak w `tools/list`; > 50 → `invalid_params`; wynik per element przepuszczony z `previous`; `wp_metrics_get` projekcja przycięta; `REST_ALLOW` odrzuca `/aktio/v1/media/alt/../x`, `DELETE`. **Step 2:** `(cd integration-proxy && npx vitest run wordpress.test.js)` → FAIL. **Step 3–4.** **Step 5: Commit** `feat(integration-proxy): narzędzia Aktio Connector 0.2 (SEO, język, IndexNow, metryki, ALT)`.

### Task A2: Proxy WooCommerce — skan katalogu, paczki, marki

**Files:** Modify `integration-proxy/lib/woocommerce.js`, `integration-proxy/woocommerce.test.js`

**Interfaces:**
- Produces: (skan katalogu jest w A1 jako `wp_catalog_scan` — trasa wtyczki wymaga poświadczeń WordPress, nie klucza WC) `wc_brands_list` (`GET wc/v3/products/brands`), `wc_brands_create` (`POST wc/v3/products/brands`, zgoda `write`, nazwy ≤ 100, ≤ 50 na wywołanie, istniejąca nazwa → zwraca istniejące id zamiast duplikatu), `wc_products_batch_update` (`POST wc/v3/products/batch` z `update: [{id, brands?, categories?, short_description?, description?, meta_data? (tylko _aktio_seo_*)}]` ≤ 50, zgoda `write`, najpierw `GET` stanów do `previous`); WC < 9.6 lub brak taksonomii → `brands_unsupported` (D6); dławik batch 10/min osobno od limitu 30/min.

- [ ] **Step 1: Testy:** paczka 50 z jednym nieistniejącym id → 49 ok + 1 błąd, `previous` dla 49 (Review Focus 5); 51 → `invalid_params`; bez `write` → brak narzędzi zapisu w `tools/list` i odmowa przed ruchem; `wc_brands_create` z istniejącą nazwą (wielkość liter bez znaczenia) → istniejące id, bez POST; `brands_unsupported` przy 404 trasy marek; `REST_ALLOW` tylko dodane trasy. **Step 2–4.** **Step 5: Commit** `feat(integration-proxy): skan katalogu, masowe zmiany produktów i marki WooCommerce`.

### Task A3: Skill, dokumentacja, bezpieczeństwo

**Files:** Modify `hermes-admin/lib/skills/wordpress-admin/SKILL.md` (+ `references/`), `docs/claude/agents/web-cli.md` §5d-decies, `integration-proxy/README.md`, `SECURITY.md` (nowy F-xxx), `docs/claude/agents/integrations.md`

- [ ] **Step 1:** Skill: sekcja „Audyt sklepu” (kolejność: `wp_catalog_scan` + `wp_media_scan` → tabela braków → propozycje marek i ALT → zatwierdzenie → `wc_brands_create` → `wc_products_batch_update` / `wp_media_alt_batch` paczkami po 50 → podsumowanie z liczbami i informacją o cofnięciu przez `previous`); kiedy proponować IndexNow i weryfikację Bing; jak czytać `wp_metrics_get` (dolna granica przy cache); D5 — przy Yoaście tytuły/opisy zmienia właściciel w Yoaście.
- [ ] **Step 2:** SECURITY F-xxx: publiczny endpoint beaconu (limit, ulotny HMAC IP, brak danych osobowych), zapisy masowe (≤50, dławik, `previous`), tworzenie marek, IndexNow jako usługa zewnętrzna włączana świadomie; README i web-cli: nowe narzędzia, kody, kolejność wdrożenia (wtyczka 0.2.0 → proxy do `/opt` + restart proxy i gatewayów → `hermes-admin` pull + restart → zapis konfiguracji agenta; bez `release.sh`, D8).
- [ ] **Step 3:** `./scripts/check-pr.sh` → OK. **Commit** `docs: Aktio Connector 0.2 — narzędzia, skill audytu sklepu, F-xxx`.

### Task A4: Pilot

- [ ] **Step 1:** Wtyczka 0.2.0 na plantacji i Easy English (z Yoastem): 0 zdublowanych meta/OG (`curl` + liczba `og:title` = 1), ekran pomiaru działa, IndexNow wyłączony do decyzji.
- [ ] **Step 2:** Po zgodzie właściciela purenature.ie i podłączeniu do Aktio: ustawienia (język `en-IE`, firma, sprzedawca), audyt sklepu przez agenta (marki, ALT), Rich Results Test, `lang` w źródle, OG w debugerze Facebooka.
- [ ] **Step 3:** Wynik do wiki (`~/wiki`, wg `AGENTS.md`).
