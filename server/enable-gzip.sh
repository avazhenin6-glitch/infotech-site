#!/usr/bin/env bash
# Включает сжатие (gzip) и кеширование стилей/скриптов/шрифтов для уже установленного сайта.
#   sudo bash /opt/infotech-deploy/repo/server/enable-gzip.sh pbk.spb.ru
# Безопасно: делает копию конфигурации и откатывает её, если nginx -t найдёт ошибку.
set -euo pipefail
D="${1:-pbk.spb.ru}"; F="/etc/nginx/sites-available/$D"
[[ $EUID -ne 0 ]] && { echo "Запустите через sudo"; exit 1; }
[[ -f "$F" ]] || { echo "Нет файла $F"; exit 1; }
if grep -q 'gzip_types' "$F"; then echo "Сжатие уже включено в $F"; exit 0; fi
B="$F.bak-$(date +%F-%H%M)"; cp "$F" "$B"
# сжатие — во все блоки server этого сайта; кеш /assets/ — рядом с правилом для catalog.js
sed -i "/^\s*server_name $D/a\\    gzip on; gzip_vary on; gzip_comp_level 5; gzip_min_length 1024;\\n    gzip_types text/css application/javascript application/json image/svg+xml text/plain application/xml;" "$F"
sed -i '/location = \/catalog.js/a\    location /assets/ { expires 30d; access_log off; }\n    location = /journal-posts.json { add_header Cache-Control "no-cache"; }' "$F"
if nginx -t 2>/tmp/nginx-test.log; then
  systemctl reload nginx
  echo "Готово: сжатие и кеширование включены. Копия старой конфигурации: $B"
else
  cp "$B" "$F"; echo "!! nginx -t нашёл ошибку — конфигурация возвращена как было:"; cat /tmp/nginx-test.log; exit 1
fi
