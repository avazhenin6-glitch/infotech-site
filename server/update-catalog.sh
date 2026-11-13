#!/usr/bin/env bash
# Новый прайс 1С → новый каталог на сайте.
#   1) скачайте свежий price_1c.xls и загрузите его на сервер через WinSCP, например в /home/aleks/
#   2) sudo bash ~/infotech-site/server/update-catalog.sh pbk.spb.ru /home/aleks/price_1c.xls
# Рядом с прайсом появится «Не вошли в каталог.xlsx» — что отсеялось и почему.
set -euo pipefail
D="${1:?Укажите домен}"; XLS="${2:?Укажите путь к price_1c.xls}"; ROOT="/var/www/$D"; U="s_${D//[.-]/_}"
VENV=/opt/infotech-tools
if [[ ! -x $VENV/bin/python ]]; then
  apt-get install -y -qq python3-venv >/dev/null
  python3 -m venv $VENV && $VENV/bin/pip install -q pandas python-calamine openpyxl
fi
OUT="$(dirname "$XLS")"
$VENV/bin/python "$ROOT/tools/update_catalog.py" "$XLS" --out "$OUT"
cp "$ROOT/public/catalog.js" "$ROOT/public/catalog.prev.js"
install -o "$U" -g "$U" -m 644 "$OUT/catalog.js" "$ROOT/public/catalog.js"
echo "Каталог на https://$D обновлён. Прежняя версия: $ROOT/public/catalog.prev.js"
