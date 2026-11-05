#!/usr/bin/env bash
# Buduje dist/aktio-connector-<wersja>.zip z zatwierdzonego HEAD (git archive).
# Wykluczenia (tests/, bin/, vendor/ itd.) są w .gitattributes (export-ignore).
# Wymaga tylko git — bez rsync/zip. Wersja z nagłówka aktio-connector.php.
set -euo pipefail
cd "$(dirname "$0")/.."
version="$(sed -n 's/^ \* Version:[[:space:]]*//p' aktio-connector.php | head -1 | tr -d '[:space:]')"
[ -n "$version" ] || { echo "Nie znaleziono wersji w aktio-connector.php" >&2; exit 1; }
const_version="$(grep -oE "AKTIO_CONNECTOR_VERSION., .[0-9.]+" aktio-connector.php | head -1 | sed -E "s/.*, .//")"
[ "$const_version" = "$version" ] || { echo "NAGŁÓWEK ($version) != STAŁA ($const_version) — zrób jeden bump (nagłówek + AKTIO_CONNECTOR_VERSION)" >&2; exit 1; }
out="dist/aktio-connector-${version}.zip"
mkdir -p dist
rm -f "$out"
git archive --format=zip --prefix=aktio-connector/ -o "$out" HEAD
echo "$out"
