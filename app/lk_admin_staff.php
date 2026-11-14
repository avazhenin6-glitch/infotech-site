<?php
/* Кабинет менеджера: сотрудники (ФИО, должность, телефон, день рождения), мой профиль и пароль, внутренний чат. */
declare(strict_types=1);

const STAFF_ACTS = ['staff_save', 'staff_toggle', 'staff_pass', 'me_save', 'me_pass', 'chat_post'];

function staff_form_vals(): array {
    $bd = (string)($_POST['birthday'] ?? '');
    $phone = trim((string)($_POST['phone'] ?? ''));
    $np = $phone === '' ? '' : norm_phone($phone);
    if ($np === null) throw new RuntimeException('Телефон — в формате +7 (XXX) XXX-XX-XX');
    if ($bd !== '' && (!preg_match('/^\d{4}-\d\d-\d\d$/', $bd) || $bd > date('Y-m-d') || $bd < '1930-01-01')) throw new RuntimeException('Проверьте дату рождения');
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Проверьте e-mail');
    $name = clean($_POST['name'] ?? '', 150);
    if (mb_strlen($name) < 3) throw new RuntimeException('Укажите ФИО');
    return ['name' => $name, 'position' => clean($_POST['position'] ?? '', 100), 'phone' => $np ?: null, 'birthday' => $bd ?: null, 'email' => $email ?: null];
}
function staff_check_pass(string $p, string $p2): void {
    if ($p !== $p2) throw new RuntimeException('Пароли не совпадают');
    if (mb_strlen($p) < 8) throw new RuntimeException('Пароль — не меньше 8 символов');
}

function adm_staff_post(): void {
    $me = staff_me(); $pdo = staff_db(); $act = (string)$_POST['act'];
    $ajax = !empty($_POST['ajax']);
    try {
        switch ($act) {
            case 'chat_post':
                $room = (string)($_POST['room'] ?? 'general');
                if (!chat_can($room, (int)$me['id'])) throw new RuntimeException('Нет доступа к чату');
                $id = chat_post($me, $room, (string)($_POST['text'] ?? ''), $_FILES['file'] ?? null);
                if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'id' => $id]); exit; }
                lk_redirect('/lk/admin/?chat=' . urlencode($room));
            case 'me_save':
                $v = staff_form_vals();
                $pdo->prepare('UPDATE staff SET name=?, position=?, phone=?, birthday=?, email=? WHERE id=?')->execute([...array_values($v), $me['id']]);
                lk_flash('Профиль сохранён'); lk_redirect('/lk/admin/?me=1');
            case 'me_pass':
                if (!password_verify((string)($_POST['old'] ?? ''), (string)$me['pass_hash'])) throw new RuntimeException('Текущий пароль неверный');
                staff_check_pass((string)($_POST['pass'] ?? ''), (string)($_POST['pass2'] ?? ''));
                $pdo->prepare('UPDATE staff SET pass_hash=? WHERE id=?')->execute([password_hash((string)$_POST['pass'], PASSWORD_DEFAULT), $me['id']]);
                session_regenerate_id(true);
                lk_flash('Пароль изменён'); lk_redirect('/lk/admin/?me=1');
            case 'staff_save':
                if (!staff_is_admin()) throw new RuntimeException('Сотрудников добавляет администратор');
                $id = (int)($_POST['id'] ?? 0); $v = staff_form_vals();
                $role = isset(STAFF_ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'manager';
                if ($id === (int)$me['id']) $role = 'admin';   // себя не разжаловать
                if ($id) {
                    $pdo->prepare('UPDATE staff SET name=?, position=?, phone=?, birthday=?, email=?, role=? WHERE id=?')->execute([...array_values($v), $role, $id]);
                    lk_flash('Сотрудник сохранён');
                } else {
                    $login = mb_strtolower(trim((string)($_POST['login'] ?? '')));
                    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $login)) throw new RuntimeException('Логин — латиница, цифры, точка, дефис; от 3 символов');
                    if (staff_by_login($login) || $login === (string)cfg('admin_user')) throw new RuntimeException('Такой логин уже есть');
                    staff_check_pass((string)($_POST['pass'] ?? ''), (string)($_POST['pass'] ?? ''));
                    $pdo->prepare('INSERT INTO staff (login, pass_hash, name, position, phone, birthday, email, role, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([$login, password_hash((string)$_POST['pass'], PASSWORD_DEFAULT), ...array_values($v), $role, date('Y-m-d H:i:s')]);
                    $id = (int)$pdo->lastInsertId();
                    lk_flash("Сотрудник добавлен. Вход: https://pbk.spb.ru/lk/admin/, логин {$login}, пароль — тот, что вы задали. Пароль сотрудник может сменить в «Мой профиль».");
                }
                lk_redirect('/lk/admin/?staff=' . $id);
            case 'staff_pass':
                if (!staff_is_admin()) throw new RuntimeException('Пароль сбрасывает администратор');
                staff_check_pass((string)($_POST['pass'] ?? ''), (string)($_POST['pass'] ?? ''));
                $pdo->prepare('UPDATE staff SET pass_hash=? WHERE id=?')->execute([password_hash((string)$_POST['pass'], PASSWORD_DEFAULT), (int)$_POST['id']]);
                lk_flash('Новый пароль задан — передайте его сотруднику лично'); lk_redirect('/lk/admin/?staff=' . (int)$_POST['id']);
            case 'staff_toggle':
                if (!staff_is_admin()) throw new RuntimeException('Нет прав');
                $id = (int)$_POST['id'];
                if ($id === (int)$me['id']) throw new RuntimeException('Себя отключить нельзя');
                $pdo->prepare('UPDATE staff SET active=1-active WHERE id=?')->execute([$id]);
                lk_flash('Готово'); lk_redirect('/lk/admin/?staff=all');
        }
    } catch (RuntimeException $e) {
        if ($ajax) { header('Content-Type: application/json'); http_response_code(400); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit; }
        lk_flash($e->getMessage(), 'bad');
        lk_redirect($_SERVER['HTTP_REFERER'] ?? '/lk/admin/');
    }
}

function adm_staff_form(?array $s, bool $self = false): string {
    $v = fn($k) => h((string)($s[$k] ?? ''));
    $f = lk_csrf_field() . '<input type="hidden" name="act" value="' . ($self ? 'me_save' : 'staff_save') . '"><input type="hidden" name="id" value="' . (int)($s['id'] ?? 0) . '">';
    return '<form method="post" class="svcform">' . $f
        . '<div class="row"><label>ФИО<input name="name" value="' . $v('name') . '" placeholder="Иванов Иван Иванович" required></label><label>Должность<input name="position" value="' . $v('position') . '" placeholder="Менеджер по продажам"></label></div>'
        . '<div class="row"><label>Телефон<input type="tel" name="phone" value="' . $v('phone') . '" placeholder="+7 (___) ___-__-__"></label><label>Дата рождения<input type="date" name="birthday" value="' . $v('birthday') . '"></label>'
        . '<label>E-mail<input type="email" name="email" value="' . $v('email') . '"></label></div>'
        . (!$self && staff_is_admin() ? '<div class="row">' . (!$s ? '<label>Логин для входа<input name="login" pattern="[a-z0-9._\-]{3,32}" placeholder="ivanov" required></label><label>Пароль (от 8 символов)<input name="pass" minlength="8" required autocomplete="new-password"></label>' : '')
            . '<label>Роль<select name="role">' . implode('', array_map(fn($k, $t) => '<option value="' . $k . '"' . (($s['role'] ?? 'manager') === $k ? ' selected' : '') . '>' . $t . '</option>', array_keys(STAFF_ROLES), STAFF_ROLES)) . '</select></label></div>' : '')
        . '<button class="btn">' . ($s ? 'Сохранить' : 'Добавить сотрудника') . '</button></form>';
}

function adm_staff_get(): void {
    $me = staff_me();
    if (isset($_GET['chat_file'])) {
        $st = staff_db()->prepare('SELECT * FROM staff_msgs WHERE id=?'); $st->execute([(int)$_GET['chat_file']]); $m = $st->fetch();
        if (!$m || !$m['file'] || !chat_can($m['room'], (int)$me['id']) || !is_file(STAFF_FILES . '/' . $m['file'])) { http_response_code(404); exit('Файл не найден'); }
        $inline = str_starts_with((string)$m['mime'], 'image/') || $m['mime'] === 'application/pdf';
        header('Content-Type: ' . ($inline ? $m['mime'] : 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($m['fname']));
        readfile(STAFF_FILES . '/' . $m['file']); exit;
    }
    if (isset($_GET['chat_api'])) {
        header('Content-Type: application/json'); header('Cache-Control: no-store');
        $room = (string)($_GET['room'] ?? 'general');
        if (!chat_can($room, (int)$me['id'])) { http_response_code(403); echo '{"ok":false}'; exit; }
        $msgs = array_map(fn($m) => chat_msg_json($m, (int)$me['id']), chat_messages($room, (int)($_GET['after'] ?? 0)));
        if ($msgs) chat_mark_read((int)$me['id'], $room);
        staff_db()->prepare('UPDATE staff SET last_seen=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $me['id']]);
        $unread = [];
        foreach (chat_rooms($me) as $r => $x) $unread[$r] = $x['unread'];
        echo json_encode(['ok' => true, 'msgs' => $msgs, 'unread' => $unread], JSON_UNESCAPED_UNICODE); exit;
    }
    if (isset($_GET['chat'])) {
        $room = (string)$_GET['chat'];
        if ($room === '' || $room === '1') $room = 'general';
        if (!chat_can($room, (int)$me['id'])) $room = 'general';
        $rooms = chat_rooms($me);
        if (!isset($rooms[$room])) $room = 'general';
        chat_mark_read((int)$me['id'], $room);
        $list = '';
        foreach ($rooms as $r => $x) {
            $online = $x['who'] && $x['who']['last_seen'] && strtotime($x['who']['last_seen']) > time() - 120;
            $list .= '<a class="room' . ($r === $room ? ' on' : '') . '" href="?chat=' . urlencode($r) . '" data-room="' . h($r) . '"><span><b>' . h($x['title']) . '</b>' . ($online ? ' <i class="dot ok" title="в сети"></i>' : '') . '<br><small>' . h($x['sub']) . '</small></span>'
                . '<em class="unread"' . ($r !== $room && $x['unread'] ? '' : ' hidden') . '>' . $x['unread'] . '</em></a>';
        }
        $who = $rooms[$room]['who'];
        $head = '<h2>' . h($rooms[$room]['title']) . '</h2>' . ($who ? '<p class="muted">' . h($who['position'] ?: '') . ($who['phone'] ? ' · <a href="tel:' . preg_replace('/[^\d+]/', '', $who['phone']) . '">' . h($who['phone']) . '</a>' : '') . '</p>' : '<p class="muted">Сообщения видят все сотрудники</p>');
        $msgs = json_encode(array_map(fn($m) => chat_msg_json($m, (int)$me['id']), chat_messages($room)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        mgr_page('Чат', '<h1>Чат сотрудников</h1><div class="chat"><nav class="rooms">' . $list . '</nav><section class="card cut thread">' . $head
            . '<div class="msgs" id="msgs"></div>'
            . '<form method="post" enctype="multipart/form-data" class="send" id="send">' . lk_csrf_field() . '<input type="hidden" name="act" value="chat_post"><input type="hidden" name="room" value="' . h($room) . '">'
            . '<textarea name="text" rows="2" placeholder="Сообщение… Enter — отправить, Shift+Enter — новая строка"></textarea>'
            . '<label class="clip" title="Прикрепить файл до 20 МБ">📎<input type="file" name="file" hidden></label><button class="btn">Отправить</button></form>'
            . '<p class="muted small">Номера заказов (ИТ-261114-3) становятся ссылками. Персональные данные клиентов в чате — только по делу: переписка хранится на нашем сервере.</p></section></div>'
            . '<script>(function(){var room=' . json_encode($room) . ',box=document.getElementById("msgs"),f=document.getElementById("send"),last=0;'
            . 'function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",\'"\':"&quot;"}[c]})}'
            . 'function add(list){var bottom=box.scrollHeight-box.scrollTop-box.clientHeight<60;list.forEach(function(m){if(m.id<=last)return;last=m.id;var d=document.createElement("div");d.className="msg"+(m.mine?" mine":"");'
            . 'var file="";if(m.file){file=m.file.img?\'<a href="\'+m.file.url+\'" target="_blank"><img src="\'+m.file.url+\'" alt=""></a>\':\'<a class="att" href="\'+m.file.url+\'">📄 \'+esc(m.file.name)+\' (\'+Math.max(1,Math.round(m.file.size/1024))+\' КБ)</a>\'}'
            . 'd.innerHTML=\'<div class="meta"><b>\'+esc(m.author)+\'</b> \'+esc(m.time)+\'</div><div class="txt">\'+m.html+\'</div>\'+file;box.appendChild(d)});if(bottom||list.length&&list[list.length-1].mine)box.scrollTop=box.scrollHeight}'
            . 'add(' . $msgs . ');box.scrollTop=box.scrollHeight;'
            . 'function poll(){fetch("?chat_api=1&room="+encodeURIComponent(room)+"&after="+last,{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(!j.ok)return;add(j.msgs);'
            . 'var tot=0;document.querySelectorAll(".rooms .room").forEach(function(a){var n=j.unread[a.dataset.room]||0,e=a.querySelector(".unread");if(a.dataset.room===room)n=0;tot+=n;e.textContent=n;e.hidden=!n});'
            . 'var b=document.getElementById("chatBadge");if(b){b.textContent=tot;b.hidden=!tot}}).catch(function(){})}setInterval(poll,4000);'
            . 'var ta=f.querySelector("textarea");ta.addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();f.requestSubmit()}});'
            . 'f.addEventListener("submit",function(e){e.preventDefault();var fd=new FormData(f);fd.append("ajax","1");if(!ta.value.trim()&&!f.file.files.length)return;'
            . 'fetch("/lk/admin/",{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(!j.ok){alert(j.error||"Не отправлено");return}ta.value="";f.file.value="";poll()})});'
            . 'f.file.addEventListener("change",function(){ta.placeholder=f.file.files.length?"Файл: "+f.file.files[0].name+" — добавьте подпись или нажмите «Отправить»":"Сообщение…"});ta.focus()})();</script>');
    }
    if (isset($_GET['me'])) {
        mgr_page('Мой профиль', '<h1>Мой профиль</h1><p class="muted">Логин: <b>' . h($me['login']) . '</b> · ' . h(STAFF_ROLES[$me['role']] ?? '') . '</p>'
            . '<div class="card cut">' . adm_staff_form($me, true) . '</div>'
            . '<div class="card cut"><h2>Сменить пароль</h2><form method="post" class="svcform">' . lk_csrf_field() . '<input type="hidden" name="act" value="me_pass">'
            . '<div class="row"><label>Текущий пароль<input type="password" name="old" required autocomplete="current-password"></label><label>Новый (от 8 символов)<input type="password" name="pass" minlength="8" required autocomplete="new-password"></label>'
            . '<label>Ещё раз<input type="password" name="pass2" minlength="8" required autocomplete="new-password"></label></div><button class="btn">Сменить пароль</button></form></div>');
    }
    if (isset($_GET['staff'])) {
        $id = (int)$_GET['staff'];
        if ($_GET['staff'] === 'new' || ctype_digit((string)$_GET['staff'])) {
            $s = $_GET['staff'] === 'new' ? null : staff_get($id);
            if ($_GET['staff'] !== 'new' && !$s) { http_response_code(404); mgr_page('Сотрудник', '<h1>Сотрудник не найден</h1>'); }
            $admin = staff_is_admin();
            $extra = '';
            if ($s && $admin) {
                $extra = '<div class="card cut"><h2>Доступ</h2><p class="muted">Логин: <b>' . h($s['login']) . '</b> · последний вход: ' . ($s['last_login'] ? h(date('d.m.Y H:i', strtotime($s['last_login']))) : 'ещё не входил') . '</p>'
                    . '<form method="post" class="filters">' . lk_csrf_field() . '<input type="hidden" name="act" value="staff_pass"><input type="hidden" name="id" value="' . $s['id'] . '"><label>Новый пароль (от 8 символов)<input name="pass" minlength="8" required autocomplete="new-password"></label><button class="btn ghost">Задать пароль</button></form>'
                    . ($s['id'] != $me['id'] ? '<form method="post" style="margin-top:12px">' . lk_csrf_field() . '<input type="hidden" name="act" value="staff_toggle"><input type="hidden" name="id" value="' . $s['id'] . '"><button class="btn ghost">' . ($s['active'] ? 'Отключить доступ' : 'Вернуть доступ') . '</button></form>' : '') . '</div>';
            }
            mgr_page('Сотрудник', '<p><a href="?staff=all">← Все сотрудники</a></p><h1>' . ($s ? h($s['name'] ?: $s['login']) : 'Новый сотрудник') . '</h1>'
                . ($admin ? '<div class="card cut">' . adm_staff_form($s) . '</div>' . $extra
                    : '<div class="card cut"><div class="kv"><span>Должность</span><b>' . h($s['position']) . '</b><span>Телефон</span><b>' . h($s['phone']) . '</b><span>День рождения</span><b>' . h(staff_bday_text($s['birthday'])) . '</b><span>E-mail</span><b>' . h($s['email']) . '</b></div></div>'));
        }
        $rows = '';
        foreach (staff_list(false) as $s) {
            $online = $s['active'] && $s['last_seen'] && strtotime($s['last_seen']) > time() - 120;
            $rows .= '<tr' . ($s['active'] ? '' : ' class="muted"') . '><td><a href="?staff=' . $s['id'] . '"><b>' . h($s['name'] ?: $s['login']) . '</b></a>' . ($online ? ' <i class="dot ok" title="в сети"></i>' : '') . '<br><span class="muted">' . h($s['position'] ?: STAFF_ROLES[$s['role']] ?? '') . ($s['active'] ? '' : ' · доступ отключён') . '</span></td>'
                . '<td>' . ($s['phone'] ? '<a href="tel:' . preg_replace('/[^\d+]/', '', $s['phone']) . '">' . h($s['phone']) . '</a>' : '—') . '</td><td>' . h(staff_bday_text($s['birthday']) ?: '—') . '</td>'
                . '<td>' . ($s['email'] ? '<a href="mailto:' . h($s['email']) . '">' . h($s['email']) . '</a>' : '—') . '</td>'
                . '<td>' . ($s['id'] != $me['id'] && $s['active'] ? '<a href="?chat=' . urlencode(chat_dm((int)$me['id'], (int)$s['id'])) . '">написать</a>' : '') . '</td></tr>';
        }
        $bd = '';
        foreach (staff_birthdays(14) as $b) $bd .= '<li><b>' . h($b['s']['name'] ?: $b['s']['login']) . '</b> — ' . h(staff_bday_text($b['s']['birthday'])) . ($b['in'] === 0 ? ' (сегодня!)' : ($b['in'] === 1 ? ' (завтра)' : " (через {$b['in']} дн.)")) . '</li>';
        mgr_page('Сотрудники', '<h1>Сотрудники</h1>' . (staff_is_admin() ? '<p><a class="btn" href="?staff=new">Добавить сотрудника</a></p>' : '')
            . ($bd ? '<div class="card cut"><h2>Ближайшие дни рождения</h2><ul>' . $bd . '</ul></div>' : '')
            . '<div class="card cut"><table class="tbl"><thead><tr><th>Сотрудник</th><th>Телефон</th><th>День рождения</th><th>E-mail</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    }
}
