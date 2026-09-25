#!/bin/bash
# Certificats Let's Encrypt (acme.sh, validation HTTP) installés dans cPanel via UAPI.
# Usage : ssl.sh issue <domaine> <docroot>   -> émet et installe
#         ssl.sh renew                        -> renouvelle et réinstalle ce qui a changé (cron)
set -e
HOME="${HOME:-$(getent passwd "$(id -un)" | cut -d: -f6)}"
export HOME
A="$HOME/.acme.sh"
if [ ! -x "$A/acme.sh" ]; then
  T=$(mktemp -d); curl -sfL https://github.com/acmesh-official/acme.sh/archive/master.tar.gz | tar xz -C "$T"
  (cd "$T"/acme.sh-master && ./acme.sh --install --home "$A" --nocron --accountemail "contact@guenver.com" >/dev/null)
  rm -rf "$T"
  "$A/acme.sh" --home "$A" --set-default-ca --server letsencrypt >/dev/null
fi
enc(){ php -r 'echo rawurlencode(file_get_contents($argv[1]));' "$1"; }
install_cert(){
  local D="$A/${1}_ecc"
  uapi --output=json SSL install_ssl domain="$1" cert="$(enc "$D/$1.cer")" key="$(enc "$D/$1.key")" cabundle="$(enc "$D/ca.cer")" | grep -o '"status":[01]' || true
}
case "$1" in
  issue)
    "$A/acme.sh" --home "$A" --issue -d "$2" -w "$3" --keylength ec-256 || [ $? -eq 2 ]
    install_cert "$2" ;;
  renew)
    for D in "$A"/*_ecc; do
      DOM=$(basename "$D" _ecc)
      NEW=$(openssl x509 -in "$D/$DOM.cer" -noout -enddate 2>/dev/null)
      "$A/acme.sh" --home "$A" --renew -d "$DOM" --ecc >/dev/null 2>&1 || true
      [ "$NEW" != "$(openssl x509 -in "$D/$DOM.cer" -noout -enddate 2>/dev/null)" ] && install_cert "$DOM"
    done ;;
  *) echo "usage: ssl.sh issue <domaine> <docroot> | renew"; exit 1 ;;
esac
