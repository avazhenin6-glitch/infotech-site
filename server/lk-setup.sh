#!/usr/bin/env bash
# Личный кабинет pbk.spb.ru/lk/: разрешить PHP в /lk/ (как в /admin/), загрузки до 20 МБ, каталог для документов.
#   sudo bash /opt/infotech-deploy/repo/server/lk-setup.sh pbk.spb.ru
# Повторный запуск безопасен; при ошибке nginx конфигурация возвращается как было.
set -euo pipefail
D="${1:-pbk.spb.ru}"
[[ $EUID -ne 0 ]] && { echo "Запустите через sudo"; exit 1; }
ROOT="/var/www/$D"; U="s_${D//[.-]/_}"; NG="/etc/nginx/sites-available/$D"
[[ -f "$NG" ]] || { echo "Нет $NG"; exit 1; }
SOCK=$(grep -o 'unix:[^;]*' "$NG" | head -1)
[[ -n "$SOCK" ]] || { echo "Не нашёл сокет PHP-FPM в $NG"; exit 1; }
mkdir -p "$ROOT/app/data/lk-files"; chown -R "$U:$U" "$ROOT/app/data"; chmod 750 "$ROOT/app/data/lk-files"
if ! grep -q 'location ^~ /lk/' "$NG"; then
  B="$NG.bak-$(date +%F-%H%M)"; cp "$NG" "$B"
  BLOCK="    location ^~ /lk/ {\n        client_max_body_size 21m;\n        location ~ \\\\.php\$ { include snippets/fastcgi-php.conf; fastcgi_pass $SOCK; }\n        index index.php;\n    }"
  # вставляем перед каждым запретом PHP «location ~ \.php$ { return 404; }» (в блоках 80 и 443)
  sed -i "/location ~ \\\\\\.php\\\$ { return 404; }/i\\$BLOCK" "$NG"
  if grep -q 'location ^~ /lk/' "$NG" && nginx -t 2>/tmp/nginx-test.log; then systemctl reload nginx; echo "· nginx: /lk/ подключён"
  else cp "$B" "$NG"; echo "!! nginx: не удалось, конфигурация возвращена"; cat /tmp/nginx-test.log 2>/dev/null; exit 1; fi
fi
# PHP-FPM пула сайта: загрузка файлов до 20 МБ
POOL=$(grep -l "\[$U\]" /etc/php/*/fpm/pool.d/*.conf 2>/dev/null | head -1 || true)
if [[ -n "$POOL" ]] && ! grep -q 'upload_max_filesize' "$POOL"; then
  printf 'php_admin_value[upload_max_filesize] = 20M\nphp_admin_value[post_max_size] = 21M\n' >> "$POOL"
  systemctl reload "$(basename "$(dirname "$(dirname "$(dirname "$POOL")")")" | sed 's/^/php/;s/$/-fpm/')" 2>/dev/null || systemctl reload php*-fpm 2>/dev/null || true
  echo "· PHP: загрузки до 20 МБ"
fi
# ежедневные напоминания о продлении услуг и снятие просроченных резервов (09:05)
cat > /etc/systemd/system/lk-daily.service <<UNIT
[Unit]
Description=Личный кабинет $D: напоминания о продлении и резервы
[Service]
Type=oneshot
User=$U
ExecStart=/usr/bin/php $ROOT/app/cli-lk-daily.php
UNIT
cat > /etc/systemd/system/lk-daily.timer <<UNIT
[Unit]
Description=Личный кабинет $D: ежедневно
[Timer]
OnCalendar=*-*-* 09:05
Persistent=true
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload; systemctl enable --now lk-daily.timer >/dev/null 2>&1 && echo "· напоминания: ежедневно в 09:05"
echo "Готово: https://$D/lk/  (менеджер: https://$D/lk/admin/ — логин и пароль как в /admin/)"
