#!/usr/bin/env bash
# Обновить файлы сайта из папки пакета (после правок дизайна или нового catalog.js).
# Настройки и заказы не трогаются.   sudo bash ~/infotech-site/server/update-site.sh pbk.spb.ru [with-catalog]
set -euo pipefail
D="${1:?Укажите домен}"; SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; ROOT="/var/www/$D"
U="s_${D//[.-]/_}"; PHPV="$(ls /etc/php | sort -V | tail -1)"
[[ -d "$ROOT" ]] || { echo "Сайт $D не найден — сначала add-static-site.sh"; exit 1; }
/usr/local/sbin/backup-$D.sh || true
# catalog.js не трогаем: он обновляется отдельно скриптом update-catalog.sh. Чтобы залить каталог из пакета — добавьте третий аргумент: with-catalog
EXC=(--exclude catalog.js --exclude catalog.prev.js); [[ "${2:-}" == "with-catalog" ]] && EXC=()
rsync -a --delete "${EXC[@]}" "$SRC/public/" "$ROOT/public/"
rsync -a --exclude config.php --exclude 'data/' "$SRC/app/" "$ROOT/app/"
rsync -a "$SRC/tools/" "$ROOT/tools/"
chown -R "$U:$U" "$ROOT"; find "$ROOT/public" -type d -exec chmod 755 {} + ; find "$ROOT/public" -type f -exec chmod 644 {} +
systemctl reload "php${PHPV}-fpm"
echo "Обновлено: https://$D  (перед обновлением сделана резервная копия)"
