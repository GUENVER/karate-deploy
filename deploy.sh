#!/usr/bin/env bash
# deploy.sh — build + déploiement du front Lishan sur dev.adherents.
# Usage : npm run deploy   (ou ./deploy.sh)
set -euo pipefail

echo "-> Build Astro (output statique)..."
npm run build

echo "-> Build terminé. Contenu de dist/ :"
find dist -type f | sort

cat <<'NOTE'

-> Pour publier sur le serveur, pousse le contenu de dist/ dans :
   /home5/guenver/dev.adherents.lishan.fr/   (satellite LISHAN)

  En préservant l'arborescence (index.html, espace/index.html, assets/, etc.).
  Ne PAS écraser : /admin, /api, /medias, /cron, .htaccess.
NOTE

echo "OK — prêt à publier."
