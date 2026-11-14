<?php
/* Вход: e-mail + пароль или одноразовый код на e-mail */
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
lk_session();
if (isset($_GET['logout'])) { lk_logout(); lk_redirect('/lk/login/'); }
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '/lk/');
if (!preg_match('#^/lk/[\w/\-?=&%.]*$#', $next)) $next = '/lk/';
if (lk_user()) lk_redirect($next);

$err = ''; $info = ''; $mode = $_GET['mode'] ?? $_POST['mode'] ?? 'pass'; $email = mb_strtolower(trim((string)($_POST['email'] ?? $_GET['email'] ?? '')));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lk_check_csrf();
    if (lk_throttle('login', 20, 900)) $err = 'Слишком много попыток. Попробуйте через 15 минут.';
    elseif ($mode === 'pass') {
        $u = lk_user_by_email($email);
        usleep(300000);
        if ($u && $u['pass_hash'] && password_verify((string)($_POST['pass'] ?? ''), $u['pass_hash'])) {
            if (password_needs_rehash($u['pass_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT))
                lk_db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([lk_hash((string)$_POST['pass']), $u['id']]);
            lk_login((int)$u['id']); lk_redirect($next);
        }
        $err = 'Неверный e-mail или пароль. Можно войти по коду на почту.';
    } elseif ($mode === 'code' && empty($_POST['code'])) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Укажите e-mail';
        elseif (lk_throttle('code:' . $email, 5, 3600)) $err = 'Код уже отправлялся несколько раз. Попробуйте позже.';
        else {
            // одинаковый ответ для существующих и новых адресов — не раскрываем, есть ли аккаунт
            if (lk_user_by_email($email)) lk_send_code($email, 'login');
            $info = 'Если этот e-mail зарегистрирован или на него оформлялся заказ, мы отправили код. Проверьте почту (и папку «Спам»).';
            $mode = 'verify';
        }
    } elseif ($mode === 'code' || $mode === 'verify') {
        $u = lk_user_by_email($email);
        if ($u && lk_check_code($email, (string)$_POST['code'], 'login') !== null) {
            lk_db()->prepare('UPDATE users SET verified=1 WHERE id=?')->execute([$u['id']]);
            lk_login((int)$u['id']);
            if (!$u['pass_hash']) lk_flash('Вы вошли по коду. Задайте пароль в разделе «Профиль», чтобы входить без кода.');
            lk_redirect($next);
        }
        $err = 'Неверный или просроченный код'; $mode = 'verify';
    }
}
$f = fn($m) => '<input type="hidden" name="mode" value="' . $m . '"><input type="hidden" name="next" value="' . h($next) . '">' . lk_csrf_field();
$body = '<div class="auth cut">' . '<a class="auth-logo" href="/"><img src="/logo-dark-s.png" alt="ИНФОТЕХ" height="40"></a><h1>Вход в личный кабинет</h1>' . lk_trace()
  . ($err ? '<div class="flash bad">' . h($err) . '</div>' : '') . ($info ? '<div class="flash ok">' . h($info) . '</div>' : '')
  . '<div class="tabs"><a href="?mode=pass&next=' . urlencode($next) . '"' . ($mode === 'pass' ? ' class="on"' : '') . '>По паролю</a><a href="?mode=code&next=' . urlencode($next) . '"' . ($mode !== 'pass' ? ' class="on"' : '') . '>Код на e-mail</a></div>';
if ($mode === 'pass') {
    $body .= '<form method="post">' . $f('pass') . '<label>E-mail<input type="email" name="email" value="' . h($email) . '" autocomplete="username" required></label>'
        . '<label>Пароль<input type="password" name="pass" autocomplete="current-password" required></label><button class="btn">Войти</button></form>'
        . '<p class="muted">Забыли пароль или оформляли заказ без регистрации? <a href="?mode=code&next=' . urlencode($next) . '">Войдите по коду на e-mail</a>.</p>';
} elseif ($mode === 'code') {
    $body .= '<form method="post">' . $f('code') . '<label>E-mail<input type="email" name="email" value="' . h($email) . '" autocomplete="email" required></label><button class="btn">Получить код</button></form>'
        . '<p class="muted">Код подойдёт и для входа после оформления заказа на сайте: аккаунт создаётся автоматически.</p>';
} else {
    $body .= '<form method="post">' . $f('verify') . '<input type="hidden" name="email" value="' . h($email) . '"><label>Код из письма<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></label><button class="btn">Войти</button></form>'
        . '<p class="muted">Код отправлен на ' . h($email) . '. <a href="?mode=code&next=' . urlencode($next) . '">Отправить заново</a></p>';
}
$body .= '<p class="auth-foot">Нет аккаунта? <a href="/lk/register/">Зарегистрироваться</a> · <a href="/">На сайт</a></p></div>';
lk_page('Вход', '', $body);
