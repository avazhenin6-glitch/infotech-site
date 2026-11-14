<?php
/* Профиль контактного лица и организации (несколько ООО/ИП на один аккаунт) */
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
$u = lk_require(); $uid = (int)$u['id']; $pdo = lk_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lk_check_csrf();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'profile') {
        $name = clean($_POST['name'] ?? '', 150); $phone = norm_phone((string)($_POST['phone'] ?? ''));
        if (mb_strlen($name) < 2 || !$phone) lk_flash('Укажите имя и телефон в формате +7 (XXX) XXX-XX-XX', 'bad');
        else { $pdo->prepare('UPDATE users SET name=?, phone=? WHERE id=?')->execute([$name, $phone, $uid]); lk_flash('Профиль сохранён'); }
    } elseif ($act === 'pass') {
        $new = (string)($_POST['new'] ?? '');
        if ($u['pass_hash'] && !password_verify((string)($_POST['old'] ?? ''), $u['pass_hash'])) lk_flash('Текущий пароль указан неверно', 'bad');
        elseif (mb_strlen($new) < 8) lk_flash('Новый пароль — не короче 8 символов', 'bad');
        else { $pdo->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([lk_hash($new), $uid]); session_regenerate_id(true); lk_flash('Пароль изменён'); }
    } elseif ($act === 'company') {
        $inn = preg_replace('/\D/', '', (string)($_POST['inn'] ?? ''));
        $c = ['inn' => $inn, 'kpp' => preg_replace('/\D/', '', (string)($_POST['kpp'] ?? '')), 'ogrn' => preg_replace('/\D/', '', (string)($_POST['ogrn'] ?? '')),
              'name' => clean($_POST['cname'] ?? '', 250), 'address' => clean($_POST['address'] ?? '', 500)];
        $cid = (int)($_POST['cid'] ?? 0);
        if (!in_array(strlen($inn), [10, 12], true) || mb_strlen($c['name']) < 3) lk_flash('Укажите ИНН (10 или 12 цифр) и наименование', 'bad');
        elseif ($cid) {
            $pdo->prepare('UPDATE user_companies SET inn=?, kpp=?, ogrn=?, name=?, address=? WHERE id=? AND user_id=?')->execute([...array_values($c), $cid, $uid]);
            lk_flash('Организация сохранена');
        } else {
            $pdo->prepare('INSERT INTO user_companies (user_id, inn, kpp, ogrn, name, address, created_at) VALUES (?,?,?,?,?,?,?)')->execute([$uid, ...array_values($c), date('Y-m-d H:i:s')]);
            lk_flash('Организация добавлена');
        }
    } elseif ($act === 'delcompany') {
        $pdo->prepare('DELETE FROM user_companies WHERE id=? AND user_id=?')->execute([(int)$_POST['cid'], $uid]);
        lk_flash('Организация удалена');
    }
    lk_redirect('/lk/profile/');
}
$edit = (int)($_GET['edit'] ?? 0);
$cards = '';
$ec = null;
foreach (lk_companies($uid) as $c) {
    if ((int)$c['id'] === $edit) $ec = $c;
    $cards .= '<div class="org cut"><b>' . h($c['name']) . '</b><span>ИНН ' . h($c['inn']) . ($c['kpp'] ? ' · КПП ' . h($c['kpp']) : '') . ($c['ogrn'] ? ' · ОГРН ' . h($c['ogrn']) : '') . '</span>'
        . ($c['address'] ? '<span class="muted">' . h($c['address']) . '</span>' : '')
        . '<div class="acts"><a class="btn small ghost" href="?edit=' . $c['id'] . '#company">Изменить</a><form method="post" class="inline" onsubmit="return confirm(\'Удалить организацию?\')">' . lk_csrf_field()
        . '<input type="hidden" name="act" value="delcompany"><input type="hidden" name="cid" value="' . $c['id'] . '"><button class="btn small ghost">Удалить</button></form></div></div>';
}
$v = fn($k) => h($ec[$k] ?? '');
$dadata = cfg('dadata_token') ? '<button type="button" class="btn small ghost" id="innFill">Заполнить по ИНН</button>' : '';
$body = '<h1>Профиль и организации</h1>' . lk_trace() . '<div class="grid2">'
    . '<div class="card cut"><h2>Контактное лицо</h2><form method="post">' . lk_csrf_field() . '<input type="hidden" name="act" value="profile">'
    . '<label>E-mail<input value="' . h($u['email']) . '" disabled></label><label>Имя<input name="name" value="' . h($u['name']) . '" required></label>'
    . '<label>Телефон<input type="tel" name="phone" value="' . h($u['phone']) . '" placeholder="+7 (___) ___-__-__" required></label><button class="btn">Сохранить</button></form></div>'
    . '<div class="card cut"><h2>' . ($u['pass_hash'] ? 'Сменить пароль' : 'Задать пароль') . '</h2><form method="post">' . lk_csrf_field() . '<input type="hidden" name="act" value="pass">'
    . ($u['pass_hash'] ? '<label>Текущий пароль<input type="password" name="old" autocomplete="current-password" required></label>' : '<p class="muted">Сейчас вы входите по коду на e-mail. Задайте пароль, чтобы входить быстрее.</p>')
    . '<label>Новый пароль (от 8 символов)<input type="password" name="new" minlength="8" autocomplete="new-password" required></label><button class="btn">Сохранить пароль</button></form></div></div>'
    . '<div class="card cut"><h2>Организации для счетов</h2>' . ($cards ?: '<p class="muted">Добавьте организацию — её реквизиты подставятся в заказы и счета.</p>') . '</div>'
    . '<div class="card cut" id="company"><h2>' . ($ec ? 'Изменить организацию' : 'Добавить организацию') . '</h2><form method="post" class="cform">' . lk_csrf_field()
    . '<input type="hidden" name="act" value="company"><input type="hidden" name="cid" value="' . ($ec['id'] ?? 0) . '">'
    . '<div class="row"><label>ИНН<input name="inn" id="cInn" value="' . $v('inn') . '" inputmode="numeric" maxlength="12" required></label>' . $dadata . '</div>'
    . '<label>Наименование<input name="cname" id="cName" value="' . $v('name') . '" required></label>'
    . '<div class="row"><label>КПП<input name="kpp" id="cKpp" value="' . $v('kpp') . '" inputmode="numeric" maxlength="9"></label><label>ОГРН / ОГРНИП<input name="ogrn" id="cOgrn" value="' . $v('ogrn') . '" inputmode="numeric" maxlength="15"></label></div>'
    . '<label>Юридический адрес<input name="address" id="cAddr" value="' . $v('address') . '"></label><button class="btn">' . ($ec ? 'Сохранить' : 'Добавить') . '</button>'
    . ($ec ? ' <a href="/lk/profile/">Отмена</a>' : '') . '</form></div><script>window.LKCSRF="' . lk_csrf() . '"</script><script src="/lk/lk.js"></script>';
lk_page('Профиль', 'profile', $body, $u);
