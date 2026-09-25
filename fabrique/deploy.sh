#!/bin/bash
# Installe / met à jour la fabrique depuis GitHub sur le compte cPanel courant (config.php et data/ conservés).
set -e
BRANCH="${1:-claude/adsense-sites-network-analysis-bmtqb7}"
H="${HOME:-$(getent passwd "$(id -un)" | cut -d: -f6)}"
[ -n "$H" ] || { echo "HOME introuvable"; exit 1; }
DEST="$H/fabrique"
mkdir -p "$DEST"
cd "$DEST"
curl -sfL "https://codeload.github.com/guenver/karate-deploy/tar.gz/refs/heads/$BRANCH" \
  | tar xz --strip-components=2 --wildcards '*/fabrique/*'
mkdir -p data public/_m && chmod 750 data
[ -f config.php ] || echo "ATTENTION : config.php absent (copier config.sample.php)"
for f in app/*.php public/index.php cli/*.php; do php -l "$f" >/dev/null || exit 1; done
find data/cache -name '*.html' -delete 2>/dev/null || true
echo "fabrique à jour ($(date -u +%FT%TZ))"
