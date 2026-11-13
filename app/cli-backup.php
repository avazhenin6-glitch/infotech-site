<?php
// Консистентная копия базы заказов: php cli-backup.php /путь/куда.sqlite
if (PHP_SAPI !== 'cli') exit(1);
$src = __DIR__ . '/data/site.sqlite';
if (!is_file($src)) exit(0);
$to = $argv[1] ?? (__DIR__ . '/data/backup.sqlite');
$s = new SQLite3($src, SQLITE3_OPEN_READONLY);
$d = new SQLite3($to);
$s->backup($d);
$d->close(); $s->close();
echo "ok $to\n";
