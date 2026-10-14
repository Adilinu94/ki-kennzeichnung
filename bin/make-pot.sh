#!/usr/bin/env bash
# Regenerates languages/ki-kennzeichnung.pot (needs gettext's xgettext).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="$(grep -m1 "Version:" ki-kennzeichnung.php | sed 's/.*Version: *//')"
find . -path ./node_modules -prune -o -path ./tests -prune -o -path ./.git -prune -o -name '*.php' -print | sort > /tmp/kikz-pot-files.txt
xgettext --language=PHP --from-code=UTF-8 --add-comments=translators \
  --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e --keyword=esc_attr__ --keyword=esc_attr_e \
  --keyword=_n:1,2 --keyword=_x:1,2c \
  --package-name="KI-Kennzeichnung" --package-version="$VERSION" \
  --msgid-bugs-address="https://github.com/Adilinu94/ki-kennzeichnung/issues" \
  -f /tmp/kikz-pot-files.txt -o languages/ki-kennzeichnung.pot
sed -i 's/nplurals=INTEGER; plural=EXPRESSION;/nplurals=2; plural=(n != 1);/; s/charset=CHARSET/charset=UTF-8/' languages/ki-kennzeichnung.pot
echo "$(grep -c '^msgid' languages/ki-kennzeichnung.pot) strings"
