<?php
// Служебное уведомление (без персональных данных): php cli-notify.php "Тема" "Текст"
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/lib.php';
$subject = $argv[1] ?? 'Уведомление сайта';
$text = $argv[2] ?? '';
$mail = send_mail((string)cfg('notify_emails'), $subject, $text) ? 'ok' : 'ошибка';
$tg = send_telegram($subject . "\n\n" . $text);
echo "почта: $mail; telegram: $tg\n";
