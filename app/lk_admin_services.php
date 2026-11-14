<?php
/* Кабинет менеджера, этап 2: услуги клиентов, часы сопровождения, обращения. Подключается из public/lk/admin/index.php после входа. */
declare(strict_types=1);

function adm_server_tariffs(): array {
    static $t = null;
    if ($t === null) {
        $own = json_decode((string)@file_get_contents(APP_DIR . '/../tools/own_prices.json'), true) ?: [];
        $t = [];
        foreach ($own['server_tariffs'] ?? [] as $x)
            $t['INF-SRV-' . $x['id']] = ['name' => $x['name'], 'cpu' => $x['cpu'], 'ram' => $x['ram'], 'disk' => $x['disk'], 'users' => $x['max_users'], 'price' => $x['price'],
                                        'disk_type' => $x['line'] === 'Премиум' ? 'SAS SSD / NVMe' : ''];
        foreach ($own['support_tariffs'] ?? [] as $x)
            $t['INF-SUP-' . $x['n']] = ['name' => 'Сопровождение, тариф ' . $x['n'] . ' (' . $x['hours'] . ' ч/мес)', 'hours' => $x['hours'], 'price' => $x['fee']];
    }
    return $t;
}

/* ---------- POST: услуги, часы, обращения ---------- */
function adm_services_post(): void {
    $pdo = svc_db(); $act = (string)($_POST['act'] ?? ''); $now = date('Y-m-d H:i:s');
    if ($act === 'svc_save') {
        $id = (int)($_POST['sid'] ?? 0);
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $u = lk_user_by_email($email);
        if (!$u && filter_var($email, FILTER_VALIDATE_EMAIL)) {   // клиента ещё нет в кабинете — создаём, войдёт по коду на e-mail
            $pdo->prepare('INSERT INTO users (email, name, created_at) VALUES (?,?,?)')->execute([$email, clean($_POST['uname'] ?? '', 150), $now]);
            $u = lk_user_by_email($email);
        }
        if (!$u) { lk_flash('Укажите e-mail клиента', 'bad'); lk_redirect('/lk/admin/?svc=' . ($id ?: 'new')); }
        $kind = isset(SVC_KINDS[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'server';
        $tariff = (string)($_POST['tariff'] ?? '');
        $tt = adm_server_tariffs()[$tariff] ?? null;
        $cfg = ['cpu' => (int)($_POST['cpu'] ?? 0) ?: ($tt['cpu'] ?? 0), 'ram' => (int)($_POST['ram'] ?? 0) ?: ($tt['ram'] ?? 0),
                'disk' => (int)($_POST['disk'] ?? 0) ?: ($tt['disk'] ?? 0), 'users' => (int)($_POST['users'] ?? 0) ?: ($tt['users'] ?? 0),
                'disk_type' => clean($_POST['disk_type'] ?? '', 40) ?: ($tt['disk_type'] ?? '')];
        $months = (int)($_POST['period'] ?? 1); if (!isset(SVC_PERIODS[$months])) $months = 1;
        $price = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['price'] ?? '0'));
        if (!$price && $tt) { $code = $kind === 'server' ? $tariff . '-' . SVC_PERIODS[$months] : $tariff; $price = (float)(catalog()[$code]['price'] ?? $tt['price']) * ($kind === 'support' ? $months : 1); }
        $hours = (float)str_replace(',', '.', (string)($_POST['hours'] ?? '0')) ?: (float)($tt['hours'] ?? 0);
        $paid = preg_match('/^\d{4}-\d\d-\d\d$/', (string)($_POST['paid_until'] ?? '')) ? $_POST['paid_until'] : null;
        $vals = [$u['id'], (int)($_POST['company'] ?? 0) ?: null, $kind, clean($_POST['title'] ?? '', 150) ?: ($tt['name'] ?? SVC_KINDS[$kind]), $tariff, $months, $price,
                 json_encode($cfg, JSON_UNESCAPED_UNICODE), $hours, in_array($_POST['vpn'] ?? '', ['Активен', 'Отключён'], true) ? $_POST['vpn'] : 'Активен',
                 isset(SVC_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : 'Активен', $paid, clean($_POST['note'] ?? '', 2000), $now];
        if ($id) {
            $pdo->prepare('UPDATE services SET user_id=?, company_id=?, kind=?, title=?, tariff_code=?, period_months=?, price=?, config_json=?, hours_month=?, vpn_status=?, status=?, paid_until=?, note=?, updated_at=? WHERE id=?')
                ->execute([...$vals, $id]);
        } else {
            $pdo->prepare('INSERT INTO services (user_id, company_id, kind, title, tariff_code, period_months, price, config_json, hours_month, vpn_status, status, paid_until, note, updated_at, created_at, started) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([...$vals, $now, date('Y-m-d')]);
            $id = (int)$pdo->lastInsertId();
        }
        lk_flash('Услуга сохранена'); lk_redirect('/lk/admin/?svc=' . $id);
    }
    if ($act === 'svc_hours') {
        $sid = (int)$_POST['sid'];
        $d = preg_match('/^\d{4}-\d\d-\d\d$/', (string)($_POST['date'] ?? '')) ? $_POST['date'] : date('Y-m-d');
        $h = round((float)str_replace(',', '.', (string)($_POST['hours'] ?? '0')), 2);
        if ($h > 0 && $h <= 24) {
            $pdo->prepare('INSERT INTO service_hours (service_id, work_date, hours, description, created_at) VALUES (?,?,?,?,?)')->execute([$sid, $d, $h, clean($_POST['desc'] ?? '', 500), $now]);
            lk_flash('Часы записаны');
        } else lk_flash('Укажите часы: от 0,1 до 24', 'bad');
        lk_redirect('/lk/admin/?svc=' . $sid);
    }
    if ($act === 'svc_hours_del') {
        $pdo->prepare('DELETE FROM service_hours WHERE id=? AND service_id=?')->execute([(int)$_POST['hid'], (int)$_POST['sid']]);
        lk_redirect('/lk/admin/?svc=' . (int)$_POST['sid']);
    }
    if ($act === 'svc_renew') {
        $s = svc_get((int)$_POST['sid']);
        if ($s) { $o = svc_create_renewal($s, 'manager'); lk_redirect('/lk/admin/?n=' . urlencode($o['number'])); }
    }
    if ($act === 'tk_reply' || $act === 'tk_status') {
        $t = tk_get((int)$_POST['tid']);
        if (!$t) lk_redirect('/lk/admin/?tickets=1');
        $u = $pdo->query('SELECT * FROM users WHERE id=' . (int)$t['user_id'])->fetch();
        if ($act === 'tk_reply') {
            $text = clean($_POST['text'] ?? '', 6000);
            try {
                if (mb_strlen($text) < 2) throw new RuntimeException('Напишите ответ');
                tk_add_msg((int)$t['id'], 'manager', $text, $_FILES['file'] ?? null);
                if (!empty($_POST['close'])) $pdo->prepare("UPDATE tickets SET status='Закрыто' WHERE id=?")->execute([$t['id']]);
                send_mail($u['email'], "Ответ по обращению {$t['number']}", "Здравствуйте" . ($u['name'] ? ", {$u['name']}" : '') . "!\n\n$text\n\n"
                    . "Обращение в личном кабинете: " . LK_SITE . "/lk/support/?t={$t['id']}\nОтветить можно там же.\n\nООО «ИНФОТЕХ», 8 (812) 928-68-84");
                lk_flash('Ответ отправлен клиенту');
            } catch (RuntimeException $e) { lk_flash($e->getMessage(), 'bad'); }
        } elseif (isset(TICKET_ST[$_POST['status'] ?? ''])) {
            $pdo->prepare('UPDATE tickets SET status=?, updated_at=? WHERE id=?')->execute([$_POST['status'], $now, $t['id']]);
            lk_flash('Статус обращения изменён');
        }
        lk_redirect('/lk/admin/?t=' . $t['id']);
    }
}

/* ---------- страницы ---------- */
function adm_services_get(): void {
    $pdo = svc_db();
    if (isset($_GET['tickets'])) {
        $only = $_GET['tickets'] === 'open';
        $rows = '';
        foreach ($pdo->query('SELECT t.*, u.email, u.name FROM tickets t JOIN users u ON u.id=t.user_id ' . ($only ? "WHERE t.status IN ('Открыто')" : '') . ' ORDER BY (t.status=\'Открыто\') DESC, t.updated_at DESC LIMIT 300') as $t)
            $rows .= '<tr><td><a href="?t=' . $t['id'] . '"><b>' . h($t['number']) . '</b></a></td><td>' . h($t['name']) . '<br><span class="muted">' . h($t['email']) . '</span></td><td>' . h($t['topic']) . '<br><span class="muted">' . h($t['subject']) . '</span></td>'
                . '<td>' . lk_date($t['updated_at'], true) . '</td><td><span class="badge ' . (TICKET_ST[$t['status']] ?? 'wait') . '">' . h($t['status']) . '</span></td></tr>';
        mgr_page('Обращения', '<h1>Обращения клиентов</h1><div class="tabs"><a href="?tickets=1"' . ($only ? '' : ' class="on"') . '>Все</a><a href="?tickets=open"' . ($only ? ' class="on"' : '') . '>Ждут ответа</a></div>'
            . '<div class="card cut"><table class="tbl"><thead><tr><th>Номер</th><th>Клиент</th><th>Тема</th><th>Обновлено</th><th>Статус</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5" class="muted">Обращений нет</td></tr>') . '</tbody></table></div>');
    }
    if (isset($_GET['t'])) {
        $t = tk_get((int)$_GET['t']);
        if (!$t) mgr_page('Не найдено', '<h1>Обращение не найдено</h1>');
        $u = $pdo->query('SELECT * FROM users WHERE id=' . (int)$t['user_id'])->fetch();
        $opts = implode('', array_map(fn($s) => '<option' . ($s === $t['status'] ? ' selected' : '') . '>' . h($s) . '</option>', array_keys(TICKET_ST)));
        $f = lk_csrf_field() . '<input type="hidden" name="tid" value="' . $t['id'] . '">';
        mgr_page('Обращение ' . $t['number'], '<p><a href="?tickets=1">← Все обращения</a></p><h1>' . h($t['number']) . ' <span class="badge ' . (TICKET_ST[$t['status']] ?? 'wait') . '">' . h($t['status']) . '</span></h1>'
            . '<div class="card cut"><p class="meta"><span><b>' . h($u['name']) . '</b> ' . h($u['email']) . ' ' . h($u['phone']) . '</span><span>' . h($t['topic']) . ' — ' . h($t['subject']) . '</span>'
            . '<a href="?as=' . $u['id'] . '">кабинет клиента</a></p>' . tk_thread($t, '/lk/file.php?m=') . '</div>'
            . '<div class="card cut"><h2>Ответ клиенту</h2><form method="post" enctype="multipart/form-data">' . $f . '<input type="hidden" name="act" value="tk_reply">'
            . '<label>Сообщение (уйдёт письмом и появится в кабинете)<textarea name="text" rows="5" required></textarea></label><label>Вложение<input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png"></label>'
            . '<label class="chk"><input type="checkbox" name="close" value="1"> Закрыть обращение после ответа</label><button class="btn">Отправить</button></form>'
            . '<form method="post" class="filters" style="margin-top:16px">' . $f . '<input type="hidden" name="act" value="tk_status"><label>Статус<select name="status">' . $opts . '</select></label><button class="btn ghost">Сменить</button></form></div>');
    }
    if (isset($_GET['services'])) {
        $rows = '';
        foreach ($pdo->query("SELECT s.*, u.email, u.name FROM services s JOIN users u ON u.id=s.user_id ORDER BY (s.status='Закрыт'), COALESCE(s.paid_until,'9999') LIMIT 500") as $s) {
            $d = svc_days_left($s);
            $rows .= '<tr><td><a href="?svc=' . $s['id'] . '"><b>' . h($s['title']) . '</b></a><br><span class="muted">' . h(SVC_KINDS[$s['kind']]) . '</span></td><td>' . h($s['name']) . '<br><span class="muted">' . h($s['email']) . '</span></td>'
                . '<td>' . lk_date($s['paid_until']) . ($d !== null ? '<br><span class="muted">' . ($d < 0 ? 'просрочено ' . abs($d) . ' дн.' : $d . ' дн.') . '</span>' : '') . '</td>'
                . '<td>' . ($s['kind'] === 'support' && $s['hours_month'] ? str_replace('.', ',', (string)svc_hours_used((int)$s['id'])) . ' / ' . str_replace('.', ',', (string)(float)$s['hours_month']) . ' ч' : '—') . '</td><td>' . svc_badge($s) . '</td></tr>';
        }
        mgr_page('Услуги', '<h1>Услуги клиентов</h1><div class="acts" style="margin:0 0 16px"><a class="btn" href="?svc=new">Подключить услугу</a></div>'
            . '<div class="card cut"><table class="tbl"><thead><tr><th>Услуга</th><th>Клиент</th><th>Оплачено до</th><th>Часы в месяце</th><th>Статус</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5" class="muted">Услуг пока нет</td></tr>') . '</tbody></table></div>'
            . '<p class="muted">Напоминания о продлении уходят клиентам автоматически за 7, 3 и 1 день; за 7 дней создаётся заказ на продление, и вам приходит письмо — выставьте счёт в 1С и прикрепите его к заказу. После статуса «Оплачен» срок услуги продлевается сам.</p>');
    }
    if (isset($_GET['svc'])) {
        $s = $_GET['svc'] === 'new' ? null : svc_get((int)$_GET['svc']);
        $u = $s ? $pdo->query('SELECT * FROM users WHERE id=' . (int)$s['user_id'])->fetch() : (isset($_GET['uid']) ? $pdo->query('SELECT * FROM users WHERE id=' . (int)$_GET['uid'])->fetch() : null);
        $c = $s ? svc_config($s) : [];
        $v = fn($k, $d = '') => h((string)($s[$k] ?? $d));
        $sel = fn($name, $opts, $cur) => '<select name="' . $name . '">' . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($opts), $opts)) . '</select>';
        $tariffs = ['' => '— своя услуга —'] + array_map(fn($t) => $t['name'], adm_server_tariffs());
        $companies = ['0' => '— без организации —'];
        if ($u) foreach (lk_companies((int)$u['id']) as $co) $companies[$co['id']] = $co['name'] . ' (ИНН ' . $co['inn'] . ')';
        $form = '<form method="post" class="svcform">' . lk_csrf_field() . '<input type="hidden" name="act" value="svc_save"><input type="hidden" name="sid" value="' . ($s['id'] ?? 0) . '">'
            . '<div class="row"><label>E-mail клиента<input type="email" name="email" value="' . h($u['email'] ?? '') . '" required></label><label>Имя (если клиента ещё нет)<input name="uname" value="' . h($u['name'] ?? '') . '"></label>'
            . '<label>Организация' . $sel('company', $companies, $s['company_id'] ?? 0) . '</label></div>'
            . '<div class="row"><label>Вид' . $sel('kind', SVC_KINDS, $s['kind'] ?? 'server') . '</label><label>Тариф' . $sel('tariff', $tariffs, $s['tariff_code'] ?? '') . '</label>'
            . '<label>Название<input name="title" value="' . $v('title') . '" placeholder="Из тарифа или своё"></label></div>'
            . '<div class="row"><label>Период оплаты' . $sel('period', [1 => '1 мес.', 3 => '3 мес.', 6 => '6 мес.', 12 => '12 мес.'], $s['period_months'] ?? 1) . '</label>'
            . '<label>Сумма за период, ₽<input name="price" value="' . $v('price') . '" placeholder="Из тарифа"></label><label>Оплачено до<input type="date" name="paid_until" value="' . $v('paid_until') . '"></label></div>'
            . '<div class="row"><label>vCPU<input name="cpu" value="' . h((string)($c['cpu'] ?? '')) . '"></label><label>RAM, ГБ<input name="ram" value="' . h((string)($c['ram'] ?? '')) . '"></label><label>Диск, ГБ<input name="disk" value="' . h((string)($c['disk'] ?? '')) . '"></label>'
            . '<label>Тип диска<input name="disk_type" value="' . h((string)($c['disk_type'] ?? '')) . '" placeholder="SSD / NVMe"></label><label>Пользователей<input name="users" value="' . h((string)($c['users'] ?? '')) . '"></label></div>'
            . '<div class="row"><label>Канал OpenVPN' . $sel('vpn', ['Активен' => 'Активен', 'Отключён' => 'Отключён'], $s['vpn_status'] ?? 'Активен') . '</label><label>Статус' . $sel('status', array_combine(array_keys(SVC_STATUS), array_keys(SVC_STATUS)), $s['status'] ?? 'Активен') . '</label>'
            . '<label>Часов в месяц (сопровождение)<input name="hours" value="' . h((string)(float)($s['hours_month'] ?? 0) ?: '') . '" placeholder="Из тарифа"></label></div>'
            . '<label>Заметка для клиента<textarea name="note" rows="2">' . $v('note') . '</textarea></label><button class="btn">' . ($s ? 'Сохранить' : 'Подключить') . '</button></form>';
        $extra = '';
        if ($s) {
            $open = svc_open_renewal($s);
            $extra .= '<div class="card cut"><h2>Продление</h2>' . ($open ? '<p>Открыт заказ <a href="?n=' . urlencode($open['number']) . '">' . h($open['number']) . '</a> — ' . lk_badge($open['status']) . '</p>'
                : '<form method="post">' . lk_csrf_field() . '<input type="hidden" name="sid" value="' . $s['id'] . '"><button class="btn ghost" name="act" value="svc_renew">Создать заказ на продление</button></form>')
                . ($s['promised_until'] ? '<p class="muted">Обещанный платёж до ' . lk_date($s['promised_until']) . '</p>' : '') . '</div>';
            if ($s['kind'] === 'support') {
                $rows = '';
                foreach (svc_hours_list((int)$s['id']) as $hr)
                    $rows .= '<tr><td>' . lk_date($hr['work_date']) . '</td><td>' . h($hr['description']) . '</td><td>' . str_replace('.', ',', (string)(float)$hr['hours']) . ' ч</td><td><form method="post" class="inline">' . lk_csrf_field()
                        . '<input type="hidden" name="act" value="svc_hours_del"><input type="hidden" name="sid" value="' . $s['id'] . '"><input type="hidden" name="hid" value="' . $hr['id'] . '"><button class="link">удалить</button></form></td></tr>';
                $extra .= '<div class="card cut"><h2>Часы специалиста — ' . date('m.Y') . ': ' . str_replace('.', ',', (string)svc_hours_used((int)$s['id'])) . ' из ' . str_replace('.', ',', (string)(float)$s['hours_month']) . ' ч</h2>'
                    . ($rows ? '<table class="tbl"><tbody>' . $rows . '</tbody></table>' : '<p class="muted">В этом месяце работ не было.</p>')
                    . '<form method="post" class="filters" style="margin-top:14px">' . lk_csrf_field() . '<input type="hidden" name="act" value="svc_hours"><input type="hidden" name="sid" value="' . $s['id'] . '">'
                    . '<label>Дата<input type="date" name="date" value="' . date('Y-m-d') . '"></label><label>Часы<input name="hours" placeholder="1,5" required></label><label style="flex:3">Что сделано (клиент видит)<input name="desc" required></label><button class="btn">Записать</button></form></div>';
            }
        }
        mgr_page('Услуга', '<p><a href="?services=1">← Все услуги</a></p><h1>' . ($s ? h($s['title']) . ' ' . svc_badge($s) : 'Подключить услугу') . '</h1>'
            . ($u ? '<p class="muted">Клиент: ' . h($u['email']) . ' · <a href="?as=' . $u['id'] . '">кабинет клиента</a></p>' : '')
            . '<div class="card cut">' . $form . '</div>' . $extra);
    }
}
