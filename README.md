# Сайт ИНФОТЕХ (pbk.spb.ru): установка на сервер web

Сайт ставится на ту же ВМ web (Ubuntu, 172.17.170.10), что и vazhenin.consulting, и по той же схеме:
регистратор домена → внешний IP 45.135.94.14 → проброс 80/443 на MikroTik (правило #4 уже есть) → nginx на ВМ web.
WordPress и MariaDB для этого сайта не нужны: сайт статический, а заказы и заявки принимает небольшой PHP-обработчик
и хранит их в базе SQLite в папке сайта.

## Что в архиве

| Папка / файл | Что это | Куда попадёт на сервере |
|---|---|---|
| public/index.html, catalog.js, logo-*.png | сайт и каталог 1С (3 680 позиций) | /var/www/pbk.spb.ru/public |
| public/api/submit.php | приём заказов и заявок | /var/www/pbk.spb.ru/public/api |
| public/admin/index.php | админка: заказы, заявки, статусы, выгрузка CSV | https://pbk.spb.ru/admin/ |
| app/ | настройки (config.php), общие функции, база заказов (data/site.sqlite) — закрыто от интернета | /var/www/pbk.spb.ru/app |
| tools/update_catalog.py | пересборка каталога из нового прайса 1С | /var/www/pbk.spb.ru/tools |
| tools/test-notify.php | проверка отправки почты и Telegram | /var/www/pbk.spb.ru/tools |
| server/add-static-site.sh | установка сайта (аналог add-site.sh) | запускается из архива |
| server/update-site.sh | обновление файлов сайта без потери заказов и настроек | запускается из архива |
| server/update-catalog.sh | новый прайс 1С → новый каталог на сайте | запускается из архива |

## Установка — 5 шагов

### 1. DNS
В панели регистратора домена pbk.spb.ru создайте записи:

| Запись | Тип | Значение |
|---|---|---|
| @ | A | 45.135.94.14 |
| www | A | 45.135.94.14 |

Старые A-записи на другие адреса удалите. Проверка (на сервере): `dig +short pbk.spb.ru @8.8.8.8` — должно вернуть 45.135.94.14.
MikroTik менять не нужно: nginx различает сайты по имени домена.

### 2. Загрузить архив на сервер
WinSCP → 172.17.170.10 (пользователь aleks) → положите infotech-site.zip в /home/aleks/. Затем в PuTTY:

    cd ~ && (command -v unzip >/dev/null || sudo apt install -y unzip) && unzip -o infotech-site.zip

### 3. Установить сайт

    cd /tmp && sudo bash ~/infotech-site/server/add-static-site.sh pbk.spb.ru pbkservices@ya.ru

Скрипт спросит три значения — их можно взять в WordPress vazhenin.consulting → Внешний вид → Настроить → «Контакты и заявки»
(или нажать Enter и заполнить позже в app/config.php):
* пароль приложения Яндекс-почты pbkservices@yandex.ru;
* токен Telegram-бота;
* ID чата Telegram.

Скрипт создаст пользователя Linux s_pbk_spb_ru, PHP-пул, конфиг nginx, выпустит сертификат Let's Encrypt
(если DNS уже указывает на 45.135.94.14), сгенерирует пароль админки и настроит ночную копию.
Если сертификат не выпустился из-за DNS — дождитесь обновления DNS и запустите ту же команду ещё раз: это безопасно.

### 4. Проверить уведомления

    sudo -u s_pbk_spb_ru php /var/www/pbk.spb.ru/tools/test-notify.php

Должно прийти письмо на pbkservices@yandex.ru и сообщение в Telegram. Telegram идёт через прокси Xray 127.0.0.1:10809 —
тот же, что у vazhenin.consulting. Если не приходит — `sudo tail /var/log/php-pbk.spb.ru.error.log`.

### 5. Проверить сайт
Откройте https://pbk.spb.ru, положите товар в корзину и оформите тестовый заказ. Заказ должен появиться
в https://pbk.spb.ru/admin/ (логин и пароль: `sudo cat /root/sites/pbk.spb.ru.credentials`), в почте и в Telegram.
Покупателю уходит письмо-подтверждение. Тестовый заказ переведите в статус «Отменён».

## Как работают заказы

1. Покупатель оформляет заказ: электронные поставки — только на e-mail; курьер или самовывоз предлагаются, только если в корзине есть коробки или оборудование.
2. Браузер отправляет состав заказа на /api/submit.php. Цены и наименования сервер берёт из своего catalog.js — подменить цену из браузера нельзя.
3. Заказ сохраняется в базе (номер вида ИТ-261113-25), уходит письмом менеджеру и в Telegram, покупателю — подтверждение.
4. В админке менеджер меняет статус: Новый → В работе → Счёт выставлен → Оплачен → Выполнен (или Отменён), пишет заметки, выгружает CSV для Excel.
Заявки из формы «Связаться» приходят так же, с номером вида З-261113-26.

Защита: скрытое поле-ловушка для ботов, не больше 8 отправок с одного IP за 10 минут (и лимит в nginx), проверка полей на сервере.

## Обновления

| Задача | Команда |
|---|---|
| Новый прайс 1С (загрузите price_1c.xls в /home/aleks/) | `sudo bash ~/infotech-site/server/update-catalog.sh pbk.spb.ru /home/aleks/price_1c.xls` |
| Новая версия дизайна/кода (распакуйте новый архив поверх) | `sudo bash ~/infotech-site/server/update-site.sh pbk.spb.ru` |
| То же, но залить и catalog.js из архива | `sudo bash ~/infotech-site/server/update-site.sh pbk.spb.ru with-catalog` |
| Настройки почты/Telegram | `sudo nano /var/www/pbk.spb.ru/app/config.php`, затем `sudo systemctl reload php8.5-fpm` |
| Сменить пароль админки | `php -r "echo password_hash('НОВЫЙ', PASSWORD_DEFAULT);"` → вставить в admin_password_hash в config.php |

update-catalog.sh при первом запуске ставит Python-пакеты в /opt/infotech-tools. Рядом с прайсом появится
«Не вошли в каталог.xlsx» — что отсеялось и почему. Прежний каталог сохраняется как catalog.prev.js.
Правила отбора (без зарубежных продуктов, NFR, сувениров; из 1С:Фреш — только Базовый, ПРОФ и апгрейд, без ERP) — в tools/update_catalog.py.

## Автообновление из GitHub

Как у vazhenin.consulting: код сайта живёт в закрытом репозитории **avazhenin6-glitch/infotech-site**, сервер web каждые 2 минуты проверяет ветку main и сам выкладывает изменения. Задачи по сайту ставятся Claude — он вносит правки в репозиторий, через 2 минуты они на pbk.spb.ru.

Подключение (один раз):

    sudo bash ~/infotech-site/server/deploy-setup.sh git@github.com:avazhenin6-glitch/infotech-site.git pbk.spb.ru

Скрипт покажет ключ — добавьте его в GitHub: репозиторий → Settings → Deploy keys → Add deploy key, **без** права записи. Затем нажмите Enter.

| Что | Где / команда |
|---|---|
| Статус последнего обновления | https://pbk.spb.ru/build.txt (ok <коммит> <время> <описание> или error …) |
| Журнал | `journalctl -u infotech-sync -n 30 --no-pager` |
| Обновить сейчас | `sudo systemctl start infotech-sync` |
| Откат правки | `git revert <коммит>` в репозитории — через 2 минуты сайт вернётся |
| Копия репозитория и ключ на сервере | /opt/infotech-deploy (работает от пользователя s_pbk_spb_ru, не от root) |

Обновляются public/, код в app/ и tools/. Настройки (app/config.php) и заказы (app/data) не трогаются никогда.
После подключения автообновления каталог 1С тоже обновляется через репозиторий: присылайте новый прайс Claude — update-site.sh и update-catalog.sh вручную больше не нужны (их изменения перезапишутся при следующей синхронизации).

## Файлы и пути

| Что | Где |
|---|---|
| Сайт | /var/www/pbk.spb.ru/public |
| Настройки (почта, Telegram, пароль админки) | /var/www/pbk.spb.ru/app/config.php |
| База заказов и заявок | /var/www/pbk.spb.ru/app/data/site.sqlite |
| Логин/пароль админки | /root/sites/pbk.spb.ru.credentials |
| Конфиг nginx | /etc/nginx/sites-available/pbk.spb.ru (+ /etc/nginx/conf.d/infotech-limits.conf) |
| PHP-пул | /etc/php/8.5/fpm/pool.d/pbk.spb.ru.conf |
| Логи | /var/log/nginx/pbk.spb.ru.access.log, .error.log, /var/log/php-pbk.spb.ru.error.log |
| Сертификат | /etc/letsencrypt/live/pbk.spb.ru/ |
| Резервные копии (ежедневно в 03:20, 14 дней) | /var/backups/sites/ГГГГ-ММ-ДД/pbk.spb.ru.sqlite и pbk.spb.ru.files.tar.gz |
| Скрипт копии и расписание | /usr/local/sbin/backup-pbk.spb.ru.sh, /etc/cron.d/backup-pbk.spb.ru |

Файл доступов назван .credentials, а не .env, чтобы общий скрипт копий WordPress-сайтов не принял сайт за WordPress с базой MariaDB.

## Восстановление

Сломался сайт, сервер цел — выберите дату копии (`ls /var/backups/sites`):

    B=/var/backups/sites/ДАТА
    sudo rm -rf /var/www/pbk.spb.ru
    sudo tar -xzf $B/pbk.spb.ru.files.tar.gz -C /var/www/
    sudo cp $B/pbk.spb.ru.sqlite /var/www/pbk.spb.ru/app/data/site.sqlite
    sudo chown -R s_pbk_spb_ru: /var/www/pbk.spb.ru
    sudo systemctl reload php8.5-fpm nginx

Пропала ВМ web — после восстановления сервера по паспорту vazhenin.consulting (сценарий 2) запустите add-static-site.sh,
затем команды выше с загруженной копией.

## Что осталось сделать

* ☐ DNS pbk.spb.ru → 45.135.94.14 (шаг 1).
* ☐ Контакты на сайте: сейчас стоят заглушки «+7 (000) 000-00-00» и «info@infoteh.example» — пришлите реальные.
* ☐ Реквизиты ООО «ИНФОТЕХ» в подвале (ИНН, ОГРН, адрес) и страница политики обработки персональных данных — форма собирает ФИО, телефон и e-mail.
* ☐ Онлайн-оплата картой: сейчас это вариант «оплата после подтверждения», платёжный шлюз не подключён.
* ☐ Копии заказов, как и копии WordPress-сайтов, лежат на той же ВМ — нужно копирование наружу.

## Журнал и сжатие (ноябрь 2026)
- Новая статья: добавить запись в `content/journal/posts.json` и файл `content/journal/<slug>.html`, затем `python3 tools/build_site.py` и push.
  Сервер (после повторного запуска `server/deploy-setup.sh`) собирает сайт сам — достаточно запушить только `content/journal/`.
- Сжатие и кеш для уже установленного сервера: `sudo bash /opt/infotech-deploy/repo/server/enable-gzip.sh pbk.spb.ru`.
