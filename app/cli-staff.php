<?php
/* Сотрудники кабинета менеджера — служебные команды на сервере (sudo -u <пользователь сайта> или sudo):
   php app/cli-staff.php list
   php app/cli-staff.php verify <логин> [email|phone|all]   — отметить контакты подтверждёнными (если почта/SMS не работают)
   php app/cli-staff.php password <логин>                   — задать новый пароль (спросит в консоли) */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/lk.php';
$pdo = staff_db(); [$cmd, $login, $what] = array_pad(array_slice($argv, 1), 3, '');
$s = $login !== '' ? staff_by_login($login) : null;
if ($cmd === 'list') { foreach (staff_list(false) as $x) printf("%-14s %-32s %s %s e-mail:%s тел.:%s\n", $x['login'], $x['name'], $x['role'], $x['active'] ? '' : '[отключён]', $x['email_verified'] ? 'да' : 'нет', $x['phone_verified'] ? 'да' : 'нет'); exit; }
if (!$s) exit("Сотрудник «{$login}» не найден (php app/cli-staff.php list). Учётка admin появляется после первого входа.\n");
if ($cmd === 'verify') {
    $now = date('Y-m-d H:i:s');
    if (in_array($what, ['', 'all', 'email'], true)) $pdo->prepare('UPDATE staff SET email_verified=? WHERE id=?')->execute([$now, $s['id']]);
    if (in_array($what, ['', 'all', 'phone'], true)) $pdo->prepare('UPDATE staff SET phone_verified=? WHERE id=?')->execute([$now, $s['id']]);
    exit("Готово: контакты {$login} отмечены подтверждёнными\n");
}
if ($cmd === 'password') {
    echo 'Новый пароль (от 8 символов): '; system('stty -echo'); $p = trim((string)fgets(STDIN)); system('stty echo'); echo "\n";
    if (mb_strlen($p) < 8) exit("Слишком короткий\n");
    $pdo->prepare('UPDATE staff SET pass_hash=?, active=1 WHERE id=?')->execute([password_hash($p, PASSWORD_DEFAULT), $s['id']]);
    exit("Пароль для {$login} изменён\n");
}
echo "Команды: list | verify <логин> [email|phone|all] | password <логин>\n";
