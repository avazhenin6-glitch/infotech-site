<?php
// Проверка уведомлений: sudo -u s_pbk_spb_ru php /var/www/pbk.spb.ru/tools/test-notify.php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/lib.php';
$text = "Проверка уведомлений сайта " . cfg('site_url') . " — " . date('d.m.Y H:i');
echo "E-mail → " . cfg('notify_emails') . ': ' . (send_mail((string)cfg('notify_emails'), 'Проверка сайта ИНФОТЕХ', $text) ? "отправлено\n" : "НЕ отправлено (смотрите /var/log/php-*.error.log или настройки smtp)\n");
$tg = send_telegram($text); echo "Telegram → " . ($tg === 'ok' ? "отправлено, проверьте чат" : $tg) . "\n";
echo "Каталог: " . count(catalog()) . " позиций\n";
echo "База: " . db()->query('SELECT COUNT(*) FROM orders')->fetchColumn() . " записей\n";
