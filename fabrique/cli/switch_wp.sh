#!/bin/bash
# Bascule un docroot WordPress existant sur la fabrique, sans rien supprimer (réversible).
# Usage : switch_wp.sh <docroot>          -> bascule
#         switch_wp.sh <docroot> --revert -> retour à WordPress
set -e
D="${1%/}"
[ -d "$D" ] || { echo "docroot introuvable"; exit 1; }
if [ "$2" = "--revert" ]; then
  [ -f "$D/.htaccess.wp" ] && mv -f "$D/.htaccess.wp" "$D/.htaccess"
  rm -f "$D/fabrique.php" "$D/_m"
  echo "revenu sur WordPress"; exit 0
fi
[ -f "$D/.htaccess.wp" ] || cp -p "$D/.htaccess" "$D/.htaccess.wp"
printf "<?php require '%s/fabrique/public/index.php';\n" "$HOME" > "$D/fabrique.php"
ln -sfn "$HOME/fabrique/public/_m" "$D/_m"
cat > "$D/.htaccess" <<'EOF'
# FABRIQUE — sauvegarde WordPress dans .htaccess.wp (revenir : cli/switch_wp.sh <docroot> --revert)
Options -Indexes +SymLinksIfOwnerMatch
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
RewriteRule .* - [E=HTTP_X_FABRIQUE_TOKEN:%{HTTP:X-Fabrique-Token}]
RewriteRule ^fabrique\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} -f
RewriteCond %{REQUEST_FILENAME} !\.(php|phtml|phar)$ [NC]
RewriteRule ^ - [L]
RewriteRule ^ fabrique.php [L]
</IfModule>
<IfModule mod_expires.c>
ExpiresActive On
ExpiresByType image/jpeg "access plus 1 year"
ExpiresByType image/png "access plus 1 year"
ExpiresByType image/webp "access plus 1 year"
</IfModule>
EOF
echo "basculé sur la fabrique : $D"
