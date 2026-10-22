=== Aktio Connector ===
Contributors: nmmedia
Tags: schema, llms.txt, robots.txt, ai, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schema.org, robots.txt i llms.txt dla botów AI, Site Health i aktualizacje przez REST — samodzielnie lub we współpracy z Aktio.

== Description ==

Aktio Connector pomaga stronie WordPress być czytelną dla wyszukiwarek i botów AI oraz pozwala zdalnie, ale tylko na Twoich zasadach, sprawdzać jej stan.

**Co robi wtyczka**

* **Dane strukturalne schema.org (JSON-LD):** firma (Organization lub LocalBusiness), witryna, strony, artykuły i okruszki. Dla WooCommerce także produkty (Product, ProductGroup, oferty i oceny).
* **robots.txt dla botów AI:** na końcu robots.txt dopisuje blok dla GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot, Google-Extended i Applebot-Extended. Domyślnie boty są dopuszczone, każdego możesz zablokować w ustawieniach.
* **llms.txt:** generuje plik `/llms.txt` z listą stron, ostatnich wpisów i produktów. Nie zawiera treści chronionych hasłem ani nieopublikowanych.
* **Site Health przez REST:** wyniki testów Site Health i informacje o środowisku dostępne dla zalogowanych administratorów przez `aktio/v1/health`.
* **Aktualizacje przez REST z dziennikiem:** lista dostępnych aktualizacji rdzenia, wtyczek i motywów oraz ich wykonywanie przez `aktio/v1/updates`. Każda aktualizacja trafia do dziennika (ostatnie 50 wpisów) widocznego w ustawieniach. Wtyczka niczego nie cofa automatycznie.

Wszystkie dane firmy i ustawienia botów edytujesz w **Ustawienia → Aktio Connector**. Ekran pokazuje też, które funkcje dostarcza wtyczka, a które inna wtyczka SEO albo fizyczny plik, oraz dziennik aktualizacji.

**Prywatność i bezpieczeństwo**

* Wtyczka nie łączy się z żadnym serwerem, nie wysyła telemetrii i nie wymaga konta.
* Punkty REST wymagają uwierzytelnienia (np. hasła aplikacji WordPress) i uprawnień odpowiednich do operacji, np. `manage_options` czy `update_plugins`.
* Wtyczka nie przechowuje żadnych sekretów.

**Współpraca z Aktio**

Aktio (aktioapp.com) to narzędzie do zarządzania treścią i widocznością strony. Jeśli je masz, łączy się z Twoją stroną przez REST za pomocą hasła aplikacji — to Aktio inicjuje każde połączenie. Bez Aktio wtyczka działa w pełni samodzielnie.

== Installation ==

1. Wgraj katalog `aktio-connector` do `/wp-content/plugins/` albo zainstaluj wtyczkę z panelu.
2. Włącz ją w menu **Wtyczki**.
3. Uzupełnij dane firmy w **Ustawienia → Aktio Connector**.

== Frequently Asked Questions ==

= Czy wtyczka łączy się z jakimś serwerem albo wymaga konta? =

Nie. Wtyczka nie łączy się z żadnym serwerem, nie wysyła telemetrii i nie wymaga konta. Działa w całości na Twojej stronie. Ewentualne połączenie z Aktio inicjuje wyłącznie Aktio, przez REST API Twojej strony.

= Mam już wtyczkę SEO (Yoast SEO, Rank Math, All in One SEO i inne). Czy dane się zdublują? =

Nie. Jeśli aktywna wtyczka SEO albo fizyczny plik w katalogu witryny dostarcza daną funkcję (schema, robots.txt, llms.txt), Aktio Connector jej nie dubluje. Ekran ustawień pokazuje, kto dostarcza każdą z funkcji. Wtyczki SEO, które są zainstalowane, ale nieaktywne, są pomijane.

= Wtyczka SEO tworzy własny robots.txt. Gdzie trafia blok botów AI? =

Blok botów AI jest dopisywany na końcu robots.txt. Jeśli wtyczka SEO już definiuje reguły dla tych samych botów, obowiązuje pierwsza pasująca grupa reguł dla danego bota — czyli ta, która znajduje się wyżej w pliku (zwykle ta z wtyczki SEO). Wtedy ustawienie w Aktio Connector może nie zadziałać dla tego bota; zmień regułę w wtyczce SEO. Fizyczny plik robots.txt w katalogu witryny ma pierwszeństwo i Aktio Connector niczego do niego nie dopisuje.

= Czy aktualizacje wykonują się bez mojej wiedzy? =

Nie. Aktualizacje przez REST wykonuje tylko uwierzytelniony użytkownik z odpowiednimi uprawnieniami, a każda trafia do dziennika w ustawieniach. Wtyczka nie włącza automatycznych aktualizacji na własną rękę i nie cofa zmian automatycznie — przed aktualizacją zrób kopię zapasową.

= Czy mogę zablokować boty AI? =

Tak. W **Ustawienia → Aktio Connector** przy każdym z sześciu botów wybierz „Zablokuj”. Blok nie jest dopisywany, gdy strona jest ustawiona jako niewidoczna dla wyszukiwarek.

== Changelog ==

= 0.1.0 =
* Pierwsze wydanie: schema.org, boty AI w robots.txt, llms.txt, Site Health i aktualizacje przez REST z dziennikiem, ekran ustawień.
