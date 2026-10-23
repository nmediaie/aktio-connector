# Aktio Connector 0.2 — „SEO pod wyszukiwarki AI” (wariant B)

Data: 2026-10-23 · Status: decyzje D1–D8 zatwierdzone 2026-10-23 · Rozszerza `2026-10-22-aktio-connector-design.md` (C1–C11)
i wersję 0.1.2 (pola sprzedawcy). Repo wtyczki: `nmediaie/aktio-connector`.

## Cel i dlaczego

Właściciel chce sprzedawać Aktio Connector jako **darmową wtyczkę „SEO pod wyszukiwarki AI” (SEO 2.0)**,
która daje dostęp do płatnej usługi Aktio (agent, monitoring). Wtyczka ma zastąpić podstawę Yoasta na stronach,
które nie mają wtyczki SEO, naprawić braki typowe dla małych sklepów WooCommerce i — w odróżnieniu od klasycznych
wtyczek SEO — przyspieszyć obecność w wyszukiwarkach AI i pokazać jej efekt w liczbach.

Punkt odniesienia: audyt purenature.ie (2026-10-23, `~/projekty/audyty/purenature-2026-10-23.html`) — sklep
WooCommerce bez wtyczki SEO: język strony `pl-PL` przy angielskiej treści, 0 opisów meta i Open Graph, brak
marek/zwrotów/wysyłki w danych produktu, 57% zdjęć bez ALT, treści zastępcze w FAQ i „Informacjach o firmie”.
Wersja 0.2 ma umożliwić naprawę każdego technicznego punktu tego audytu.

## Decyzje właściciela (2026-10-23)

| # | Decyzja |
|---|---------|
| D1 | Wtyczka zawsze darmowa (katalog wordpress.org), wszystkie jej funkcje działają lokalnie bez konta. Płatna jest subskrypcja Aktio: agent i usługi wymagające naszych serwerów. |
| D2 | Wtyczka daje podstawę Yoasta (opis meta, Open Graph itd.), gdy na stronie nie ma wtyczki SEO; z wtyczką SEO niczego nie dubluje (zasada C5, wykrywanie per funkcja). |
| D3 | Zakres pierwszej wersji = wariant B: podstawowe SEO + kontrola języka + czyszczenie cache + agent (marki, ALT) + IndexNow + pomiar AI + FAQ + encja marki. |
| D5 | Pola „Tytuł SEO”/„Opis SEO” tylko gdy Aktio jest właścicielem `title`/`meta_description`: przy Yoaście/Rank Math boks się chowa, a agent nie zmienia tytułów i opisów (zapis `seo_title`/`seo_description` → odmowa z wyjaśnieniem). Pisanie do pól Yoasta — osobny krok po 0.2. |
| D6 | Agent może TWORZYĆ nowe marki (z nazw produktów) za zgodą WooCommerce `write` i po zatwierdzeniu listy; wymagany WooCommerce ≥ 9.6 (marki w rdzeniu i REST). Starszy WC lub marki z innych wtyczek / atrybutu `pa_brand` → agent tylko zgłasza, bez zapisu. |
| D7 | IndexNow (i kody weryfikacyjne) może włączyć właściciel w wp-admin **albo agent** przez `wp_visibility_set` po wyraźnym „tak” w rozmowie; readme opisuje usługę w „External services”. |
| D8 | Karta WordPress w Aktio w 0.2 NIE pokazuje nowych danych (metryki, SEO, cache, IndexNow) — są na ekranie wtyczki i dla agenta; wykres w Aktio razem z monitoringiem (wariant C). Bez zmian edge fn/web, bez `release.sh`. |
| D4 | Audyt dla klientów: raport dla właściciela sklepu, który ma go przekonać do usługi. Samoobsługowy formularz na aktioapp.com (adres + e-mail → raport mailem) — **później**, osobny projekt. |

## Cele / nie-cele / anty-cele

**Cele**
- Strona bez wtyczki SEO dostaje po instalacji: opisy meta, Open Graph/X, poprawne tytuły i `noindex` stron technicznych — bez ręcznej pracy.
- Język treści strony zgłaszany wyszukiwarkom zgodny z treścią, niezależnie od języka panelu administratora.
- Zmiany treści widoczne dla Binga (a przez niego ChatGPT search i Copilota) w minutach, nie dniach.
- Właściciel widzi w panelu wtyczki, że boty AI odwiedzają stronę i że przychodzi ruch z ChatGPT/Perplexity/Gemini.
- Agent Aktio uzupełnia braki w całym katalogu (marki, ALT) masowo, za zatwierdzeniem listy.

**Nie-cele (0.2)**
- Analiza słów kluczowych, ocena czytelności, „zielone kropki” w edytorze, przekierowania, edytor `.htaccess` — to pełna wtyczka SEO.
- Feed produktów (Merchant Center, programy zakupowe AI), wersje stron w Markdown, `llms-full.txt`, monitoring odpowiedzi AI — wariant C, później.
- Formularz audytu na aktioapp.com (D4).
- Zmiana adresów URL (slugi) i przekierowania.

**Anty-cele**
- Zdublowane meta/OG obok Yoasta, Rank Math, Jetpacka lub motywu (dwa różne `og:image` to gorzej niż żaden).
- Wtyczka wysyła cokolwiek na zewnątrz bez wiedzy właściciela. Wyjątek jawny i wyłączalny: IndexNow (zgłoszenie adresów do Binga) — domyślnie wyłączony; włącza go właściciel w ustawieniach albo agent po wyraźnym „tak” (D7).
- Zbieranie danych osobowych odwiedzających w pomiarze (adresy IP, identyfikatory, cookies) — pomiar ma działać bez zgody cookie i bez RODO-problemu.
- Agent masowo zapisuje zmiany w sklepie bez zatwierdzonej listy.

## Moduły wtyczki

### M1. Podstawowe SEO (gdy brak wtyczki SEO)

Nowe funkcje w Detectorze (per funkcja, jak C5): `meta_description`, `open_graph`, `twitter_card`, `title`, `robots_meta`.
Dostawcy do wykrycia: Yoast, Rank Math, SEOPress, AIOSEO, The SEO Framework, Jetpack (moduł „Udostępnianie/Open Graph”
— sprawdzić jego filtr `jetpack_enable_open_graph`), motywy wypisujące OG (wykrywanie po wyjściu `wp_head` w tle nie
wchodzi w grę — tylko znane wtyczki + filtr `aktio_connector_seo_map`).

- **Opis meta:** własny opis wpisu/produktu/strony/kategorii (nowe pole, patrz niżej) → krótki opis produktu → zajawka →
  początek treści; bez HTML i shortcode’ów, ≤ 155 znaków, ucięte na granicy słowa. Strona główna: opis z ustawień
  wtyczki → opis witryny (`blogdescription`). Archiwa kategorii/tagów: opis terminu.
- **Open Graph + X (Twitter Card `summary_large_image`):** `og:title`, `og:description`, `og:url` (kanoniczny),
  `og:image` (wyróżniony obraz / główne zdjęcie produktu / logo firmy / obrazek domyślny z ustawień; z wymiarami),
  `og:type` (`website`, `article`, `product`), `og:locale` (z M2), `og:site_name`; dla produktów `product:price:amount`,
  `product:price:currency`, `product:availability`.
- **Tytuł:** bez zmian względem WordPressa, chyba że ustawiono własny tytuł dla strony (nowe pole) — wtedy filtr `pre_get_document_title`.
- **`noindex,follow`:** wyniki wyszukiwania, strony koszyka/zamówienia/konta WooCommerce, archiwa autorów (gdy jeden autor),
  puste archiwa tagów, strony załączników. Te same adresy wyłączone z `wp-sitemap.xml` (filtry rdzenia). Kanoniczne adresy
  zostawiamy rdzeniowi WP (już je wypisuje).
- **Pola per obiekt:** „Tytuł SEO” i „Opis SEO” jako metadane wpisu/produktu/strony (`_aktio_seo_title`, `_aktio_seo_description`)
  i terminu (`term meta`); edycja w małym boksie w edytorze (klasyczny i blokowy) oraz w szybkiej edycji; zapis przez REST
  rdzenia (`register_post_meta` z `show_in_rest`, uprawnienie `edit_post`) — dzięki temu agent ustawi je istniejącymi
  narzędziami `wp_post_update`/`wp_page_update`/`wc_product_update` (do sprawdzenia przy planie: czy WC REST przepuszcza
  `meta_data` — jeśli tak, `wc_product_update` dostaje pola `seo_title`/`seo_description`).

### M2. Język treści strony

- Ustawienie „Język treści strony” (np. `en-IE`), domyślnie = język witryny WordPress. Wpływa wyłącznie na front:
  `language_attributes` (`<html lang>`), `og:locale`, `inLanguage` w schema, nagłówki `llms.txt` (dziś zależą od `get_locale()`).
  Panel administratora zostaje w języku użytkownika.
- Ostrzeżenie na ekranie wtyczki i w statusie dla Aktio, gdy język treści wygląda na inny niż ustawiony (prosta
  heurystyka na 5 ostatnich wpisach/produktach: najczęstsze słowa funkcyjne pl/en/de/…; bez zewnętrznych usług).
- `hreflang`: tylko gdy wykryta wtyczka wielojęzyczna (WPML, Polylang, TranslatePress, GTranslate w trybie z osobnymi
  adresami) — wtedy nic nie robimy (one to robią); bez niej `hreflang` nie jest potrzebny. GTranslate w trybie JS
  (jak purenature) → informacja, że tłumaczenia nie są widoczne dla wyszukiwarek.

### M3. Czyszczenie cache (0.1.3 wchłonięte tutaj)

- Po zapisaniu ustawień wtyczki: „wyczyść wszystko” przez oficjalne hooki/funkcje znanych wtyczek cache — Cache Enabler,
  WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer, Breeze,
  Cloudflare (wtyczka) — każde wywołanie za `function_exists`/`has_action`, błędy połykane.
- Zmiana stanu magazynowego / statusu dostępności produktu (`woocommerce_product_set_stock_status`,
  `woocommerce_variation_set_stock_status`) → czyszczenie strony tego produktu (gdzie wtyczka cache ma API „jeden URL”), inaczej nic.
- Ekran i status pokazują wykrytą wtyczkę cache i czas ostatniego czyszczenia.

### M4. IndexNow

- Włączane checkboxem (domyślnie wyłączone, D-anty-cel) z wyjaśnieniem: „zgłasza adresy nowych i zmienionych stron do
  Binga i innych wyszukiwarek IndexNow; nie wysyła treści ani danych odwiedzających”.
- Klucz generowany lokalnie, serwowany pod `/<klucz>.txt` (rewrite jak `llms.txt`).
- Zgłoszenie (`api.indexnow.org/indexnow`, POST z listą URL) przy publikacji/aktualizacji/usunięciu wpisu, strony,
  produktu i terminu — zbierane w kolejkę i wysyłane paczką co ≤ 5 min (WP-Cron), bez blokowania zapisu; tylko adresy
  publiczne i indeksowalne (nie `noindex` z M1). Przycisk „Zgłoś całą mapę strony” (jednorazowo, z limitem).
- Dziennik ostatnich zgłoszeń (czas, liczba adresów, kod odpowiedzi) na ekranie i w statusie.
- Pomoc w weryfikacji Bing Webmaster Tools: pole na kod weryfikacyjny (`msvalidate.01`) — wypisywany w `<head>`.
  Analogicznie Google Search Console (`google-site-verification`), jeśli brak Site Kit / wtyczki SEO.

### M5. Pomiar widoczności w AI

- **Wizyty botów AI:** licznik dzienny per bot (GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-User,
  PerplexityBot, Perplexity-User, Google-Extended nie odwiedza — pominąć; Bingbot, Applebot, Amazonbot, Meta-ExternalAgent,
  CCBot, Bytespider) — rozpoznawanie po User-Agent, bez IP. Liczone po stronie PHP: **ograniczenie znane** — strony
  serwowane z cache (wtyczka cache, CDN) nie przechodzą przez PHP i nie zostaną policzone; `llms.txt`, `robots.txt` i mapy
  strony przechodzą zawsze. Ekran mówi to wprost („liczby są dolną granicą przy włączonym cache”).
- **Ruch z wyszukiwarek AI:** licznik dzienny wizyt ludzi przychodzących z chatgpt.com, perplexity.ai, gemini.google.com,
  copilot.microsoft.com, claude.ai (nagłówek Referer i/lub `utm_source`) — przez lekki skrypt w przeglądarce wysyłający
  `navigator.sendBeacon` do endpointu REST wtyczki (działa także przy cache strony); bez cookies i bez identyfikatora —
  zapisujemy tylko (dzień, źródło, ścieżka lądowania) z limitem 200 ścieżek/dzień.
  Ochrona przed nabijaniem (decyzja Claude'a): globalny limit wywołań endpointu na minutę dla całej strony + ulotny skrót
  (HMAC z solą wtyczki) adresu IP i ścieżki w transiencie na 10 minut, wyłącznie do odrzucania powtórzeń — nigdy nie trafia do
  tabeli metryk ani logów; liczy się tylko wizyta z Referer/`utm_source` z listy źródeł AI. Readme opisuje to w sekcji prywatności.
- Przechowywanie: własna tabela `{prefix}aktio_metrics` (dzień, typ, klucz, licznik) z retencją 400 dni; czyszczona przy
  odinstalowaniu.
- Ekran: wykres 30/90 dni (boty per nazwa, ruch per źródło), top 10 stron lądowania z AI. Dane dostępne dla Aktio przez
  `GET aktio/v1/metrics` (uprawnienie `manage_options`) — agent/raport subskrypcji je czyta.

### M6. FAQ

- Blok Gutenberga „FAQ (Aktio)” (pytanie + odpowiedź, lista) i krótki kod `[aktio_faq]` dla Elementora/edytora klasycznego
  — renderują dostępny HTML (`<details>`/nagłówki) i dokładają do grafu schema `FAQPage` (tylko gdy Aktio jest właścicielem
  `content_schema`; Yoast ma własny blok FAQ — wtedy nasz blok wyświetla treść bez schema).
- Agent może utworzyć/uzupełnić stronę FAQ szkicem (istniejące `wp_page_create` jako draft) w formacie bloku.

### M7. Encja marki

- Rozszerzenie danych firmy (`company`): profile społecznościowe (`same_as` już jest), `contactPoint` (telefon, e-mail,
  typ „customer service”, języki), dla `LocalBusiness`: godziny otwarcia, współrzędne (opcjonalne), obszar obsługi;
  `foundingDate`, `description` (krótki opis firmy — używany także w `llms.txt` i opisie meta strony głównej).
- Ekran: sekcja „Firma” rozbudowana; agent ustawia przez `wp_visibility_set` (`company` rozszerzone, ścisła walidacja jak `merchant`).

## Agent Aktio (proxy + skill)

- **Masowe marki (D6):** marki tworzone przez `POST wc/v3/products/brands` (allowlista proxy, zgoda `write`) tylko z zatwierdzonej listy; przypisanie przez batch. Nowe narzędzie `wc_products_scan` (tylko odczyt, stronicowane, ≤ 100 produktów na wywołanie):
  per produkt — id, nazwa, marka (taksonomia `product_brand`), liczba zdjęć, liczba zdjęć bez ALT, długość opisów, SKU, GTIN,
  waga, kategorie. Agent proponuje marki (z nazw, z listy marek sklepu), pokazuje listę, po „tak” zapisuje.
- **Zapis masowy:** nowe narzędzie `wc_products_batch_update` (zgoda `write` WooCommerce) na REST `wc/v3/products/batch`
  (≤ 50 produktów na wywołanie; pola: marka, kategorie, opisy, `seo_title`/`seo_description` jeśli M1 je udostępni)
  z `previous` dla każdego produktu; osobny, mniejszy dławik (np. 10 wywołań/min) zamiast liczenia każdego produktu w limicie 30/min.
- **ALT masowo:** `wp_media_scan` (odczyt: media bez ALT, do 100 na stronę, z adresem miniatury i tytułem/produktem, do
  którego należą) + `wp_media_batch_update` (zgoda `publish`, jak `wp_media_update`; ≤ 50 na wywołanie, tylko `alt_text`,
  `previous`). ALT agent pisze z kontekstu (nazwa produktu, tytuł pliku) — bez analizy obrazu w 0.2.
- **Zasada zatwierdzania (anty-cel):** opisy narzędzi batch każą przedstawić listę zmian w rozmowie (lub jako załącznik/tabelę)
  i zapisać dopiero po wyraźnym „tak”; wynik zawiera `previous` do cofnięcia całej paczki.
- Skill `wordpress-admin` dostaje sekcję „Audyt sklepu” (kolejność: scan → propozycja → zatwierdzenie → batch → podsumowanie).

## Dane dla Aktio (REST wtyczki)

- `GET aktio/v1/status` rozszerzony o: `seo` (stan funkcji M1 per funkcja, jak `features`), `content_language`,
  `language_warning`, `cache` (wtyczka, ostatnie czyszczenie), `indexnow` (włączone, ostatnie zgłoszenie).
- `GET aktio/v1/metrics?days=30` — liczniki M5.
- `GET/POST aktio/v1/visibility` — rozszerzone o ustawienia M1 (domyślny obrazek OG, opis strony głównej),
  M2 (język treści), M4 (IndexNow on/off, kody weryfikacyjne), M7 (firma).

## Zgodność z wordpress.org (D1)

Wszystkie funkcje działają bez konta. Połączenie z Aktio opisane w readme jako opcjonalna usługa zewnętrzna. IndexNow i
Aktio opisane w sekcji „External services” readme (wymóg katalogu): co, kiedy, jakie dane, link do regulaminu i polityki
prywatności usług. Skrypt pomiaru ruchu z AI ładowany z wtyczki (bez zewnętrznych CDN).

## Sukces

- purenature.ie po instalacji 0.2 i 15 minutach ustawień: opisy meta i OG na 100% stron i produktów, `lang="en-IE"`,
  pełne dane produktów (marka po akcji agenta), Rich Results Test bez ostrzeżeń poza opiniami, `llms.txt` i IndexNow aktywne.
- Po 2 tygodniach: w panelu wtyczki widać wizyty botów AI i (jeśli są) wejścia z wyszukiwarek AI.
- Na stronie z Yoastem (plantacja, Easy English): 0 zdublowanych meta/OG po aktualizacji do 0.2.

## Kolejność wdrożenia i pilot

Wtyczka 0.2 → proxy (nowe narzędzia) + skill → host (`hermes-admin` pull + restart, sudo Norberta) → bez migracji, bez
`release.sh` (D8). Pilot:
plantacja i Easy English (z Yoastem — test „nie dubluj”), następnie purenature.ie (bez wtyczki SEO — pełny zakres) po
zgodzie właściciela sklepu i podłączeniu do Aktio.

## Otwarte

- Format raportu metryk w subskrypcji (cotygodniowy e-mail?) — razem z monitoringiem z wariantu C.

## Zostawione budującemu

Dokładne reguły heurystyki języka, lista hooków wtyczek cache (zweryfikować w ich kodzie), kształt odpowiedzi
`metrics`, architektura boksu SEO w edytorze, migracja tabeli metryk, kolejka IndexNow, testy. Kolejność modułów w planie.
