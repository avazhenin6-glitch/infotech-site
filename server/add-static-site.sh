#!/usr/bin/env bash
# Размещение сайта ИНФОТЕХ (статический сайт + PHP для заказов) на сервере web.
# Аналог add-site.sh для WordPress, но без WordPress и без базы MariaDB (заказы хранятся в SQLite).
#
#   cd /tmp && sudo bash ~/infotech-site/server/add-static-site.sh pbk.spb.ru pbkservices@ya.ru
#
# Повторный запуск безопасен: файлы сайта обновятся, а настройки (app/config.php) и заказы (app/data) останутся.
set -euo pipefail

D="${1:-}"; EMAIL="${2:-}"
[[ -z "$D" || -z "$EMAIL" ]] && { echo "Использование: sudo bash add-static-site.sh ДОМЕН EMAIL_ДЛЯ_LETSENCRYPT"; exit 1; }
[[ $EUID -ne 0 ]] && { echo "Запустите через sudo"; exit 1; }

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT="/var/www/$D"
U="s_${D//[.-]/_}"
PHPV="$(ls /etc/php | sort -V | tail -1)"
SOCK="/run/php/php${PHPV}-fpm-${D}.sock"
CRED="/root/sites/${D}.credentials"
SITE_IP="45.135.94.14"

echo "== Сайт $D · пользователь $U · PHP $PHPV · исходники $SRC"

# PHP-модули, нужные сайту
apt-get install -y -qq rsync bind9-dnsutils "php${PHPV}-sqlite3" "php${PHPV}-curl" "php${PHPV}-mbstring" >/dev/null || true

# 1. Пользователь Linux без входа (от него работает PHP сайта)
id "$U" &>/dev/null || useradd --system --no-create-home --shell /usr/sbin/nologin "$U"

# 2. Файлы
mkdir -p "$ROOT/public" "$ROOT/app/data"
rsync -a --delete "$SRC/public/" "$ROOT/public/"
rsync -a --exclude config.php --exclude 'data/' "$SRC/app/" "$ROOT/app/"
mkdir -p "$ROOT/tools" && rsync -a "$SRC/tools/" "$ROOT/tools/"

# 3. Настройки и пароль админки (только при первом запуске)
NEW=0
if [[ ! -f "$ROOT/app/config.php" ]]; then
  NEW=1
  cp "$ROOT/app/config.example.php" "$ROOT/app/config.php"
  ADMIN_PASS="$(php -r 'echo bin2hex(random_bytes(8));')"
  HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PASS")"
  php -r '$f=$argv[1];$s=file_get_contents($f);
    $s=str_replace("\x27admin_password_hash\x27 => \x27\x27","\x27admin_password_hash\x27 => \x27".$argv[2]."\x27",$s);
    $s=str_replace("https://pbk.spb.ru","https://".$argv[3],$s);
    file_put_contents($f,$s);' "$ROOT/app/config.php" "$HASH" "$D"

  if [[ -t 0 ]]; then
    echo; echo "-- Уведомления о заказах (Enter — пропустить, заполните позже в $ROOT/app/config.php)"
    read -rp "Пароль приложения Яндекс-почты pbkservices@yandex.ru: " SMTP_PASS || true
    read -rp "Токен Telegram-бота (как в настройках vazhenin.consulting): " TG_TOKEN || true
    read -rp "ID чата Telegram: " TG_CHAT || true
    php -r '$f=$argv[1];$s=file_get_contents($f);$e=fn($v)=>str_replace(["\\","\x27"],["\\\\","\\\x27"],$v);
      if($argv[2]!=="") $s=preg_replace_callback("/(\x27pass\x27\s*=>\s*)\x27\x27/",fn($m)=>$m[1]."\x27".$e($argv[2])."\x27",$s,1);
      if($argv[3]!=="") $s=str_replace("\x27telegram_bot_token\x27 => \x27\x27","\x27telegram_bot_token\x27 => \x27".$e($argv[3])."\x27",$s);
      if($argv[4]!=="") $s=str_replace("\x27telegram_chat_id\x27   => \x27\x27","\x27telegram_chat_id\x27   => \x27".$e($argv[4])."\x27",$s);
      file_put_contents($f,$s);' "$ROOT/app/config.php" "${SMTP_PASS:-}" "${TG_TOKEN:-}" "${TG_CHAT:-}"
  fi

  mkdir -p /root/sites && chmod 700 /root/sites
  cat > "$CRED" <<EOF
# $D — создано $(date '+%d.%m.%Y %H:%M')
SITE_URL=https://$D
ADMIN_URL=https://$D/admin/
ADMIN_USER=admin
ADMIN_PASS=$ADMIN_PASS
LINUX_USER=$U
CONFIG=$ROOT/app/config.php
EOF
  chmod 600 "$CRED"
fi

# 4. Права: файлы — пользователю сайта; nginx читает только public; app/ закрыт от всех, кроме PHP сайта
chown -R "$U:$U" "$ROOT"
find "$ROOT/public" -type d -exec chmod 755 {} + ; find "$ROOT/public" -type f -exec chmod 644 {} +
chmod 750 "$ROOT/app" "$ROOT/tools"; chmod 770 "$ROOT/app/data"; chmod 640 "$ROOT/app/config.php"
chmod 755 "$ROOT"

# 5. PHP-пул сайта
cat > "/etc/php/$PHPV/fpm/pool.d/$D.conf" <<EOF
[$D]
user = $U
group = $U
listen = $SOCK
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
php_admin_value[open_basedir] = $ROOT:/tmp
php_admin_value[upload_max_filesize] = 2M
php_admin_value[post_max_size] = 2M
php_admin_value[error_log] = /var/log/php-$D.error.log
php_admin_flag[log_errors] = on
php_admin_value[session.save_path] = $ROOT/app/data
EOF
touch "/var/log/php-$D.error.log"; chown "$U:$U" "/var/log/php-$D.error.log"

# 6. nginx
cat > /etc/nginx/conf.d/infotech-limits.conf <<'EOF'
limit_req_zone $binary_remote_addr zone=infotech_api:10m rate=10r/m;
EOF
cat > "/etc/nginx/sites-available/$D" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $D www.$D;
    gzip on; gzip_vary on; gzip_comp_level 5; gzip_min_length 1024;
    gzip_types text/css application/javascript application/json image/svg+xml text/plain application/xml;
    root $ROOT/public;
    index index.html;

    access_log /var/log/nginx/$D.access.log;
    error_log  /var/log/nginx/$D.error.log;

    if (\$host = www.$D) { return 301 https://$D\$request_uri; }

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    client_max_body_size 1m;

    location = / { try_files /index.html =404; add_header Cache-Control "no-cache"; }
    location = /index.html { add_header Cache-Control "no-cache"; }
    location = /catalog.js { add_header Cache-Control "no-cache"; }
    location /assets/ { expires 30d; access_log off; }
    location = /supply.js { add_header Cache-Control "no-cache"; }
    location ^~ /uploads/ { expires 7d; access_log off; location ~ \\.(php|html?)\$ { return 404; } }
    location = /journal-posts.json { add_header Cache-Control "no-cache"; }
    location ~* \.(png|jpg|jpeg|svg|webp|ico|woff2?)\$ { expires 7d; access_log off; }

    location = /api/submit.php {
        limit_req zone=infotech_api burst=5 nodelay;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$SOCK;
    }
    location = /api/v1/payments/callback {
        limit_req zone=infotech_api burst=20 nodelay;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root/api/payments-callback.php;
        fastcgi_pass unix:$SOCK;
    }
    location ^~ /admin/ {
        location ~ \.php\$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:$SOCK; }
        index index.php;
    }
    location ^~ /lk/ {
        client_max_body_size 21m;
        location ~ \.php\$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:$SOCK; }
        index index.php;
    }
    location ~ \.php\$ { return 404; }
    location ~ /\. { deny all; }
    location / { try_files \$uri \$uri/ =404; }
}
EOF
ln -sf "/etc/nginx/sites-available/$D" "/etc/nginx/sites-enabled/$D"
nginx -t
systemctl reload "php${PHPV}-fpm" nginx

# 7. HTTPS
DNSIP="$( (dig +short "$D" A @8.8.8.8 2>/dev/null || getent ahostsv4 "$D" | awk '{print $1}') | tail -1 || true)"
if [[ -f "/etc/letsencrypt/live/$D/fullchain.pem" ]]; then
  certbot --nginx -d "$D" -d "www.$D" --redirect --reinstall -n -m "$EMAIL" --agree-tos >/dev/null || true
  echo "HTTPS: сертификат уже есть, подключён к nginx"
elif [[ -z "$DNSIP" || "$DNSIP" == "$SITE_IP" ]]; then
  # если локальная проверка DNS ничего не вернула — пусть Let's Encrypt проверит сам
  certbot --nginx -d "$D" -d "www.$D" --redirect -n -m "$EMAIL" --agree-tos \
    || echo "!! HTTPS НЕ выпущен — проверьте, что A-записи @ и www ведут на $SITE_IP, и запустите скрипт ещё раз."
else
  echo "!! HTTPS НЕ выпущен: $D указывает на '$DNSIP', а должен на $SITE_IP."
  echo "   Исправьте A-записи @ и www → $SITE_IP, подождите обновления DNS и запустите скрипт ещё раз."
fi
systemctl reload nginx

# 8. Ночная копия заказов (SQLite) и файлов сайта — рядом с копиями остальных сайтов
cat > /usr/local/sbin/backup-$D.sh <<EOF
#!/usr/bin/env bash
set -e
B=/var/backups/sites/\$(date +%F); mkdir -p "\$B"
php "$ROOT/app/cli-backup.php" "\$B/$D.sqlite" || echo "sqlite backup failed"
tar -czf "\$B/$D.files.tar.gz" -C /var/www --exclude="$D/app/data/sess_*" "$D"
find /var/backups/sites -maxdepth 1 -type d -mtime +14 -exec rm -rf {} + 2>/dev/null || true
EOF
chmod 700 /usr/local/sbin/backup-$D.sh
echo "20 3 * * * root /usr/local/sbin/backup-$D.sh" > "/etc/cron.d/backup-$D"

echo
echo "== Готово: https://$D"
echo "   Админка заказов: https://$D/admin/   (логин и пароль: sudo cat $CRED)"
[[ $NEW -eq 1 ]] && echo "   Настройки почты и Telegram: sudo nano $ROOT/app/config.php"
echo "   Проверка отправки: sudo -u $U php $ROOT/tools/test-notify.php"
