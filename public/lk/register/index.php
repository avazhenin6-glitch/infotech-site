<?php
/* Регистрация: данные → код на e-mail → аккаунт (подтверждённый адрес защищает чужие заказы) */
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
lk_session();
if (lk_user()) lk_redirect('/lk/');
$err = ''; $step = 'form'; $v = ['name' => '', 'email' => '', 'phone' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lk_check_csrf();
    $v = ['name' => clean($_POST['name'] ?? '', 150), 'email' => mb_strtolower(trim((string)($_POST['email'] ?? ''))), 'phone' => (string)($_POST['phone'] ?? '')];
    if (lk_throttle('register', 10, 3600)) $err = 'Слишком много попыток. Попробуйте позже.';
    elseif (isset($_POST['code'])) {
        $p = lk_check_code($v['email'], (string)$_POST['code'], 'register');
        if ($p === null) { $err = 'Неверный или просроченный код'; $step = 'verify'; }
        else {
            $pdo = lk_db(); $u = lk_user_by_email($v['email']);
            if ($u) {   // аккаунт уже создан при заказе — дополняем
                $pdo->prepare('UPDATE users SET name=COALESCE(NULLIF(?,\'\'),name), phone=COALESCE(NULLIF(?,\'\'),phone), pass_hash=?, verified=1 WHERE id=?')
                    ->execute([$p['name'], $p['phone'], $p['hash'], $u['id']]);
                $uid = (int)$u['id'];
            } else {
                $pdo->prepare('INSERT INTO users (email, name, phone, pass_hash, verified, created_at) VALUES (?,?,?,?,1,?)')
                    ->execute([$v['email'], $p['name'], $p['phone'], $p['hash'], date('Y-m-d H:i:s')]);
                $uid = (int)$pdo->lastInsertId();
            }
            lk_login($uid); lk_flash('Добро пожаловать! Заказы, оформленные на этот e-mail, уже в кабинете.'); lk_redirect('/lk/');
        }
    } else {
        $phone = norm_phone($v['phone']); $pass = (string)($_POST['pass'] ?? '');
        if (mb_strlen($v['name']) < 2) $err = 'Укажите имя';
        elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err = 'Укажите корректный e-mail';
        elseif (!$phone) $err = 'Телефон в формате +7 (XXX) XXX-XX-XX';
        elseif (mb_strlen($pass) < 8) $err = 'Пароль — не короче 8 символов';
        elseif (empty($_POST['consent'])) $err = 'Нужно согласие на обработку персональных данных';
        elseif (($u = lk_user_by_email($v['email'])) && $u['pass_hash']) $err = 'Этот e-mail уже зарегистрирован. Войдите или восстановите доступ по коду.';
        elseif (lk_throttle('code:' . $v['email'], 5, 3600)) $err = 'Код уже отправлялся несколько раз. Попробуйте позже.';
        else {
            lk_send_code($v['email'], 'register', ['name' => $v['name'], 'phone' => $phone, 'hash' => lk_hash($pass)]);
            $step = 'verify';
        }
    }
}
$body = '<div class="auth cut"><a class="auth-logo" href="/"><img src="/logo-dark-s.png" alt="ИНФОТЕХ" height="40"></a><h1>Регистрация</h1>' . lk_trace()
    . ($err ? '<div class="flash bad">' . h($err) . '</div>' : '');
if ($step === 'verify') {
    $body .= '<div class="flash ok">Мы отправили код на ' . h($v['email']) . '. Введите его, чтобы подтвердить адрес.</div>'
        . '<form method="post">' . lk_csrf_field() . '<input type="hidden" name="email" value="' . h($v['email']) . '"><label>Код из письма<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></label><button class="btn">Подтвердить</button></form>';
} else {
    $body .= '<form method="post">' . lk_csrf_field()
        . '<label>Имя<input name="name" value="' . h($v['name']) . '" autocomplete="name" required></label>'
        . '<label>E-mail<input type="email" name="email" value="' . h($v['email']) . '" autocomplete="email" required></label>'
        . '<label>Телефон<input type="tel" name="phone" value="' . h($v['phone']) . '" placeholder="+7 (___) ___-__-__" autocomplete="tel" required></label>'
        . '<label>Пароль (от 8 символов)<input type="password" name="pass" minlength="8" autocomplete="new-password" required></label>'
        . '<label class="chk"><input type="checkbox" name="consent" value="1" required> Согласен на обработку персональных данных по <a href="/privacy.html" target="_blank">политике</a></label>'
        . '<button class="btn">Зарегистрироваться</button></form>'
        . '<p class="muted">Оформляли заказ на сайте? Аккаунт уже создан — <a href="/lk/login/?mode=code">войдите по коду на e-mail</a>.</p>';
}
$body .= '<p class="auth-foot">Уже есть аккаунт? <a href="/lk/login/">Войти</a> · <a href="/">На сайт</a></p></div><script src="/lk/lk.js"></script>';
lk_page('Регистрация', '', $body);
