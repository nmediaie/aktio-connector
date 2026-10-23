# Aktio Connector

WordPress plugin: basic SEO, schema.org (JSON-LD), `llms.txt`, `robots.txt` rules for AI bots, IndexNow, AI visit
measurement, Site Health and updates over REST. It works on its own and is the bridge for the
[Aktio](https://aktioapp.com) agent. The user-facing description (features, privacy, external services, changelog)
is in [`readme.txt`](readme.txt), the wordpress.org format.

- **Latest build:** <https://github.com/nmediaie/aktio-connector/releases/latest/download/aktio-connector.zip>
- **Requirements:** WordPress 6.2+, PHP 7.4+; the WooCommerce features need WooCommerce (brands: 9.6+).
- **License:** GPLv2 or later.

## Layout

| Path | What |
|------|------|
| `aktio-connector.php` | Plugin header (`Version`), bootstrap |
| `includes/` | Classes in namespace `AktioConnector`: `Rest` (`aktio/v1`), `Detector` (who owns each SEO feature), `Schema/`, `Seo/`, `Crawlers` (robots.txt, llms.txt), `Health`, `Updates`, `Metrics`, `IndexNow`, `CachePurge`, `Language`, `Catalog`, `Faq`, `Settings`, `Admin/` |
| `assets/`, `blocks/faq` | Admin scripts, AI visit beacon, FAQ block |
| `languages/` | `aktio-connector.pot` + `pl_PL` (`.po`/`.mo`) |
| `tests/` | PHPUnit (WordPress test suite in wp-env) |
| `bin/build-zip.sh` | Release zip from the committed `HEAD` (`git archive`) |
| `docs/superpowers/` | Specs and plans (also mirrored in the Aktio repo) |

Rule of thumb: a feature the plugin adds (meta, Open Graph, schema, robots, llms.txt) is emitted **only** when
`Detector` says no other plugin, theme or physical file provides it. Do not add output that bypasses `Detector`.

## Development

Requires Docker and Node.

```bash
npm install
docker run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction --ignore-platform-req=ext-*
npx wp-env start            # WordPress + WooCommerce, http://localhost:8888 (admin / password)
npx wp-env run tests-cli --env-cwd=wp-content/plugins/aktio-connector vendor/bin/phpunit
```

Before a release, also check:

```bash
# PHP 7.4 syntax (the minimum version)
docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c 'find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" -print0 | xargs -0 -n1 php -l' | grep -v '^No syntax errors'
# Plugin Check: no ERROR in the files that ship
npx wp-env run cli wp plugin install plugin-check --activate
npx wp-env run cli wp plugin check aktio-connector
```

Translations: after changing strings regenerate `languages/aktio-connector.pot`
(`npx wp-env run cli --env-cwd=wp-content/plugins/aktio-connector wp i18n make-pot . languages/aktio-connector.pot`),
update `pl_PL.po` and compile the `.mo` (`wp i18n make-mo languages`).

## Release

1. Bump the version in three places: `Version:` in `aktio-connector.php`, `AKTIO_CONNECTOR_VERSION` in the same file, and `Stable tag` +
   `== Changelog ==` in `readme.txt`.
2. Merge to `main` (CI runs PHPUnit on every push and PR).
3. Tag and push: `git tag vX.Y.Z && git push origin vX.Y.Z`.
4. CI (`.github/workflows/ci.yml`, job `release`) builds `aktio-connector-X.Y.Z.zip` and a copy named
   `aktio-connector.zip`, and attaches both to the GitHub Release. The "latest" link above and the download button
   in Aktio always point to the newest release.

Files that must not ship are listed in `.gitattributes` (`export-ignore`); `bin/build-zip.sh` respects it.

## Aktio side

The plugin never calls Aktio. Aktio talks to the site over REST with an application password: the control plane
(`hermes-admin`) reads `aktio/v1/status` and `aktio/v1/metrics`, and the agent uses the `wp_*` / `wc_*` tools of the
integration proxy. Details: `docs/claude/agents/web-cli.md` §5d-decies in the Aktio repo. A REST change here usually
needs a matching change in the proxy allowlist there, and the plugin must be deployed to sites **before** Aktio
starts calling a new route.
