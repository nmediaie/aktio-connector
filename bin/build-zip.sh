#!/usr/bin/env bash
# Buduje dist/aktio-connector.zip: katalog aktio-connector/ bez tests/, bin/, .github/, vendor/ itp.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/aktio-connector
rsync -a \
  --exclude '.git' --exclude '.github' --exclude 'tests' --exclude 'bin' \
  --exclude 'vendor' --exclude 'node_modules' --exclude 'dist' \
  --exclude '.wp-env.json' --exclude 'phpunit.xml.dist' --exclude 'composer.*' \
  --exclude 'package*.json' --exclude '.gitignore' --exclude '.phpunit.result.cache' \
  ./ dist/aktio-connector/
(cd dist && zip -qr aktio-connector.zip aktio-connector)
echo "dist/aktio-connector.zip"
