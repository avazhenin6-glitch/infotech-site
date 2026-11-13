#!/usr/bin/env bash
# Каталог оборудования от дистрибьюторов: ночная синхронизация + запуск из админки.
#   sudo bash /opt/infotech-deploy/repo/server/supply-setup.sh pbk.spb.ru
# Повторный запуск безопасен.
set -euo pipefail
D="${1:-pbk.spb.ru}"
[[ $EUID -ne 0 ]] && { echo "Запустите через sudo"; exit 1; }
ROOT="/var/www/$D"; U="s_${D//[.-]/_}"; SYNC=/usr/local/sbin/infotech-sync.sh; NG="/etc/nginx/sites-available/$D"
[[ -d "$ROOT/public" ]] || { echo "Нет $ROOT/public — сначала установите сайт"; exit 1; }
command -v python3 >/dev/null || apt-get install -y -qq python3

# 1. Автовыкладка не должна стирать то, что формирует синхронизация на сервере
if [[ -f "$SYNC" ]] && ! grep -q 'exclude supply.js' "$SYNC"; then
  cp "$SYNC" "$SYNC.bak"
  sed -i 's|--exclude build.txt|--exclude build.txt --exclude supply.js --exclude tovar/ --exclude uploads/ --exclude sitemap-products.xml|' "$SYNC"
  grep -q 'exclude supply.js' "$SYNC" && echo "· автовыкладка: файлы каталога защищены" || { echo "!! не удалось изменить $SYNC"; exit 1; }
fi
mkdir -p "$ROOT/public/uploads/products" "$ROOT/public/tovar" "$ROOT/app/data/prices"
[[ -s "$ROOT/public/supply.js" ]] || printf 'window.SUPPLY=null;\n' > "$ROOT/public/supply.js"
chown -R "$U:$U" "$ROOT/public/uploads" "$ROOT/public/tovar" "$ROOT/public/supply.js" "$ROOT/app/data"
[[ -f "$ROOT/app/data/supply-secrets.json" ]] || { echo '{}' > "$ROOT/app/data/supply-secrets.json"; chown "$U:$U" "$ROOT/app/data/supply-secrets.json"; chmod 600 "$ROOT/app/data/supply-secrets.json"; }

# 2. nginx: каталог не кешировать, в папке фото не исполнять ничего
if [[ -f "$NG" ]] && ! grep -q 'location = /supply.js' "$NG"; then
  B="$NG.bak-$(date +%F-%H%M)"; cp "$NG" "$B"
  sed -i '/location = \/catalog.js/a\    location = /supply.js { add_header Cache-Control "no-cache"; }\n    location ^~ /uploads/ { expires 7d; access_log off; location ~ \\.(php|html?)$ { return 404; } }' "$NG"
  if nginx -t 2>/tmp/nginx-test.log; then systemctl reload nginx; echo "· nginx обновлён"; else cp "$B" "$NG"; echo "!! nginx -t: ошибка, конфигурация возвращена"; cat /tmp/nginx-test.log; fi
fi

# 3. systemd: ночью в 03:40 и по кнопке в админке (файл-флаг)
cat > /etc/systemd/system/supply-sync.service <<EOF
[Unit]
Description=Каталог оборудования $D: синхронизация с дистрибьюторами
After=network-online.target
[Service]
Type=oneshot
User=$U
ExecStartPre=/bin/rm -f $ROOT/app/data/supply-run.flag
ExecStart=/usr/bin/python3 $ROOT/tools/supply_sync.py run
TimeoutStartSec=6h
Nice=10
EOF
cat > /etc/systemd/system/supply-sync.timer <<EOF
[Unit]
Description=Каталог оборудования $D: ежедневно ночью
[Timer]
OnCalendar=*-*-* 03:40
RandomizedDelaySec=15min
Persistent=true
[Install]
WantedBy=timers.target
EOF
cat > /etc/systemd/system/supply-sync.path <<EOF
[Unit]
Description=Каталог оборудования $D: запуск из админки
[Path]
PathExists=$ROOT/app/data/supply-run.flag
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now supply-sync.timer supply-sync.path
echo
echo "Готово. Проверка источника:  sudo -u $U python3 $ROOT/tools/supply_sync.py probe --supplier polus"
echo "Тест на 100 товаров:          sudo -u $U python3 $ROOT/tools/supply_sync.py run --supplier polus --limit 100"
echo "Журнал:                       tail -f $ROOT/app/data/parser_sync.log"
