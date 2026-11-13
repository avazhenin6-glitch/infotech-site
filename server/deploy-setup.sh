#!/usr/bin/env bash
# Автообновление сайта из GitHub (как vz-sync у vazhenin.consulting).
# Сервер каждые 2 минуты проверяет ветку main и сам выкладывает изменения на сайт.
#
#   sudo bash ~/infotech-site/server/deploy-setup.sh git@github.com:avazhenin6-glitch/infotech-site.git pbk.spb.ru
#
# Настройки (app/config.php) и заказы (app/data) никогда не перезаписываются.
set -euo pipefail
REPO="${1:?Укажите адрес репозитория git@github.com:...}"; D="${2:-pbk.spb.ru}"
[[ $EUID -ne 0 ]] && { echo "Запустите через sudo"; exit 1; }
ROOT="/var/www/$D"; U="s_${D//[.-]/_}"; DIR="/opt/infotech-deploy"
[[ -d "$ROOT" ]] || { echo "Сначала установите сайт: add-static-site.sh"; exit 1; }

apt-get install -y -qq git rsync >/dev/null
mkdir -p "$DIR"; chown "$U:$U" "$DIR"; chmod 750 "$DIR"

# 1. Ключ только для чтения (Deploy key)
if [[ ! -f "$DIR/key" ]]; then
  sudo -u "$U" ssh-keygen -q -t ed25519 -N '' -C "server web · $D" -f "$DIR/key"
fi
echo
echo "== Добавьте этот ключ в GitHub: репозиторий → Settings → Deploy keys → Add deploy key"
echo "   Title: server web · $D     Allow write access: НЕ отмечать"
echo
cat "$DIR/key.pub"
echo
read -rp "Добавили ключ? Нажмите Enter для проверки... " _ || true

export GIT_SSH_COMMAND="ssh -i $DIR/key -o IdentitiesOnly=yes -o UserKnownHostsFile=$DIR/known_hosts -o StrictHostKeyChecking=accept-new"
sudo -u "$U" env GIT_SSH_COMMAND="$GIT_SSH_COMMAND" git ls-remote "$REPO" >/dev/null || { echo "!! Нет доступа к $REPO — проверьте Deploy key и запустите снова."; exit 1; }
[[ -d "$DIR/repo/.git" ]] || sudo -u "$U" env GIT_SSH_COMMAND="$GIT_SSH_COMMAND" git clone -q "$REPO" "$DIR/repo"

# 2. Скрипт синхронизации
cat > /usr/local/sbin/infotech-sync.sh <<EOF
#!/usr/bin/env bash
# Синхронизация $D с GitHub. Запускается таймером infotech-sync от пользователя $U.
set -uo pipefail
export GIT_SSH_COMMAND="$GIT_SSH_COMMAND"
cd "$DIR/repo" || exit 1
STATUS="$ROOT/public/build.txt"
git fetch -q origin main 2>/tmp/infotech-sync.err || { echo "error fetch \$(date '+%F %T') \$(tail -1 /tmp/infotech-sync.err)" > "\$STATUS"; exit 1; }
NEW=\$(git rev-parse origin/main); OLD=\$(cat "$DIR/deployed" 2>/dev/null || true)
TODAY=\$(date +%F); BUILT=\$(cat "$DIR/built-date" 2>/dev/null || true)
# новая версия, принудительный запуск или новый день (отложенные статьи журнала)
[[ "\$NEW" == "\$OLD" && "\$TODAY" == "\$BUILT" && "\${1:-}" != "--force" ]] && exit 0
git reset -q --hard origin/main
if ! python3 tools/build_site.py >/tmp/infotech-build.log 2>&1; then
  echo "error build \$(date '+%F %T') \$(tail -1 /tmp/infotech-build.log)" > "\$STATUS"; git reset -q --hard origin/main; exit 1
fi
if rsync -a --delete --exclude build.txt --exclude supply.js --exclude tovar/ --exclude uploads/ --exclude sitemap-products.xml public/ "$ROOT/public/" \\
   && rsync -a --exclude config.php --exclude 'data/' app/ "$ROOT/app/" \\
   && rsync -a tools/ "$ROOT/tools/"; then
  find "$ROOT/public" -type d -exec chmod 755 {} + ; find "$ROOT/public" -type f -exec chmod 644 {} +
  echo "\$NEW" > "$DIR/deployed"; echo "\$TODAY" > "$DIR/built-date"
  echo "ok \$(git rev-parse --short HEAD) \$(date '+%F %T') \$(git log -1 --format=%s | head -c 120)" > "\$STATUS"
  echo "deployed \$(git rev-parse --short HEAD)"
else
  echo "error rsync \$(date '+%F %T')" > "\$STATUS"; exit 1
fi
EOF
chmod 755 /usr/local/sbin/infotech-sync.sh

# 3. Таймер: каждые 2 минуты
cat > /etc/systemd/system/infotech-sync.service <<EOF
[Unit]
Description=Обновление сайта $D из GitHub
After=network-online.target
[Service]
Type=oneshot
User=$U
ExecStart=/usr/local/sbin/infotech-sync.sh
EOF
cat > /etc/systemd/system/infotech-sync.timer <<EOF
[Unit]
Description=Проверка обновлений сайта $D каждые 2 минуты
[Timer]
OnBootSec=1min
OnUnitActiveSec=2min
[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now infotech-sync.timer
sudo -u "$U" /usr/local/sbin/infotech-sync.sh --force || true

echo
echo "== Готово. Сайт обновляется из $REPO (ветка main) каждые 2 минуты."
echo "   Статус:  https://$D/build.txt"
echo "   Журнал:  journalctl -u infotech-sync -n 30 --no-pager"
echo "   Сейчас:  sudo systemctl start infotech-sync"
