<?php
/* Кабинет менеджера ИНФОТЕХ: заказы клиентов, статусы, счета/УПД/акты, ключи, просмотр кабинета глазами клиента */
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
lk_session();
$pdo = lk_db();

if (isset($_GET['logout'])) { unset($_SESSION['mgr'], $_SESSION['as'], $_SESSION['staff']); lk_redirect('/lk/admin/'); }
if (!empty($_SESSION['mgr']) && !staff_me()) unset($_SESSION['mgr']);   // сессия без сотрудника (старый вход) — войти заново
if (isset($_GET['stopas'])) { unset($_SESSION['as']); lk_redirect('/lk/admin/'); }
if (empty($_SESSION['mgr'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        lk_check_csrf();
        usleep(400000);
        if (lk_throttle('mgr', 10, 900)) $err = 'Слишком много попыток, попробуйте через 15 минут';
        elseif ($st = staff_auth(mb_strtolower(trim((string)($_POST['user'] ?? ''))), (string)($_POST['pass'] ?? ''))) {
            lk_db()->prepare('DELETE FROM hits WHERE ip=?')->execute(['mgr:' . client_ip()]);   // считаем только неудачные попытки
            session_regenerate_id(true); $_SESSION['mgr'] = 1; $_SESSION['staff'] = (int)$st['id'];
            staff_db()->prepare('UPDATE staff SET last_login=?, last_seen=? WHERE id=?')->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $st['id']]);
            if (!$st['phone'] && !$st['position']) { lk_flash('Заполните, пожалуйста, свой профиль: ФИО, должность, телефон, дату рождения'); lk_redirect('/lk/admin/?me=1'); }
            lk_redirect('/lk/admin/');
        } else $err = 'Неверный логин или пароль';
    }
    lk_page('Вход менеджера', '', '<div class="auth cut"><h1>Кабинет менеджера</h1>' . lk_trace() . ($err ? '<div class="flash bad">' . h($err) . '</div>' : '')
        . '<form method="post">' . lk_csrf_field() . '<label>Логин<input name="user" autocomplete="username" required></label><label>Пароль<input type="password" name="pass" autocomplete="current-password" required></label><button class="btn">Войти</button></form>'
        . '<p class="muted">Вход для сотрудников ИНФОТЕХ. Учётные записи выдаёт администратор.</p></div>');
}
unset($_SESSION['as']);   // в кабинете менеджера — не «от имени клиента»
require APP_DIR . '/lk_admin_services.php';
require APP_DIR . '/lk_admin_payments.php';
require APP_DIR . '/lk_admin_billing.php';
require APP_DIR . '/lk_admin_staff.php';

function mgr_page(string $title, string $body): never {
    $me = staff_me(); $unread = $me ? chat_unread_total($me) : 0;
    $bd = '';
    foreach (staff_birthdays(0) as $b) $bd .= '<div class="flash ok">Сегодня день рождения у ' . h($b['s']['name'] ?: $b['s']['login']) . ($b['s']['position'] ? ' (' . h($b['s']['position']) . ')' : '') . ' — поздравьте в <a href="/lk/admin/?chat=general">общем чате</a>!</div>';
    $nav = '<a href="/lk/admin/?chat=general">Чат <em class="unread" id="chatBadge"' . ($unread ? '' : ' hidden') . '>' . $unread . '</em></a><a href="/lk/admin/">Заказы</a><a href="/lk/admin/?billing=1">Аренда серверов</a><a href="/lk/admin/?payments=1">Оплаты Сбербанк</a><a href="/lk/admin/?services=1">Услуги клиентов</a><a href="/lk/admin/?tickets=1">Обращения</a><a href="/lk/admin/?leads=1">Заявки с сайта</a><a href="/lk/admin/?users=1">Клиенты</a><a href="/admin/?supply=1">Каталог оборудования</a><a href="/lk/admin/?staff=all">Сотрудники</a><a href="/lk/admin/?me=1">Мой профиль</a><a href="/lk/admin/?logout=1">Выйти</a>';
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($title) . ' — менеджер ИНФОТЕХ</title><link rel="stylesheet" href="/assets/lk.css?v=' . @filemtime(PUBLIC_DIR . '/assets/lk.css') . '"></head><body class="lk mgr">'
        . '<aside class="side"><a class="brand" href="/lk/admin/"><img src="/logo-light-s.png" alt="ИНФОТЕХ" height="34"></a><div class="who"><b>' . h($me ? ($me['name'] ?: $me['login']) : 'Кабинет менеджера') . '</b>' . ($me && $me['position'] ? '<br><small>' . h($me['position']) . '</small>' : '') . '</div><nav>' . $nav . '</nav></aside>'
        . '<main class="work">' . $bd . lk_flash() . $body . '</main></body></html>';
    exit;
}
function mail_client(array $o, string $subject, string $text, ?array $att = null): void {
    if (!$o['email']) return;
    $msg = "Здравствуйте" . ($o['name'] ? ", {$o['name']}" : '') . "!\n\n$text\n\nЗаказ в личном кабинете: " . LK_SITE . '/lk/orders/?n=' . urlencode($o['number'])
         . "\nВход — по паролю или по коду на этот e-mail.\n\nООО «ИНФОТЕХ», 8 (812) 928-68-84, info@pbk.spb.ru";
    $att ? send_mail_attach($o['email'], $subject, $msg, $att[0], $att[1], $att[2]) : send_mail($o['email'], $subject, $msg);
}

/* ---------- действия по заказу ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lk_check_csrf();
    if (in_array($_POST['act'] ?? '', STAFF_ACTS, true)) adm_staff_post();
    if (in_array($_POST['act'] ?? '', BILL_ADMIN_ACTS, true)) adm_billing_post();
    if (in_array($_POST['act'] ?? '', ['pay_import', 'pay_bind', 'pay_ignore'], true)) adm_payments_post();
    if (in_array($_POST['act'] ?? '', ['svc_save', 'svc_hours', 'svc_hours_del', 'svc_renew', 'tk_reply', 'tk_status'], true)) adm_services_post();
    $st = $pdo->prepare('SELECT * FROM orders WHERE id=?'); $st->execute([(int)($_POST['id'] ?? 0)]);
    $o = $st->fetch();
    if (!$o) lk_redirect('/lk/admin/');
    $back = $o['type'] === 'order' ? '/lk/admin/?n=' . urlencode($o['number']) : '/lk/admin/?leads=1';
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'status' && isset(LK_ST[$_POST['status'] ?? ''])) {
        $new = $_POST['status'];
        $pdo->prepare('UPDATE orders SET status=?, manager_note=?, updated_at=? WHERE id=?')->execute([$new, clean($_POST['note'] ?? '', 3000), date('Y-m-d H:i:s'), $o['id']]);
        $ext = '';
        if ($new === 'Оплачен' && $o['status'] !== 'Оплачен' && !empty($o['service_id']) && ($until = svc_apply_payment($o)))   // продление услуги
            $ext = ' Услуга продлена, оплачено до ' . lk_date($until) . '.';
        if ($new !== $o['status'] && $o['type'] === 'order' && in_array($new, ['Оплачен', 'В работе', 'Выполнен', 'Отменён'], true))
            mail_client($o, "Заказ {$o['number']}: " . LK_ST[$new][0], "Статус вашего заказа № {$o['number']} изменён: " . LK_ST[$new][0] . '.' . $ext);
        lk_flash('Сохранено.' . $ext);
    } elseif ($act === 'doc') {
        $kind = isset(LK_DOC_KINDS[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'other';
        try {
            if (!$o['user_id']) throw new RuntimeException('Заказ не привязан к кабинету клиента');
            $stored = lk_store_upload($_FILES['file'] ?? [], (int)$o['id'], ['application/pdf', 'image/jpeg', 'image/png'], 20);
            $date = preg_match('/^\d{4}-\d\d-\d\d$/', (string)($_POST['date'] ?? '')) ? $_POST['date'] : date('Y-m-d');
            $number = clean($_POST['number'] ?? '', 50);
            lk_add_document($o, $kind, $stored, LK_DOC_KINDS[$kind], $number, $date, 'manager');
            $label = LK_DOC_KINDS[$kind] . ($number ? " № $number" : '');
            if ($kind === 'invoice') {
                $amount = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['amount'] ?? '0'));
                pay_db()->prepare('UPDATE orders SET invoice_number=?, invoice_date=?, invoice_amount=? WHERE id=?')->execute([$number ?: null, $date, $amount > 0 ? $amount : null, $o['id']]);
                $pdo->prepare("UPDATE documents SET blocked=1 WHERE order_id=? AND kind='invoice' AND file<>?")->execute([$o['id'], $stored['file']]);  // старые счета больше не действуют
                $until = $o['has_hw'] ? lk_add_workdays($date, 3) : null;
                if (in_array($o['status'], ['Новый', 'Срок резерва истёк', 'Счёт выставлен'], true))
                    $pdo->prepare("UPDATE orders SET status='Счёт выставлен', valid_until=?, updated_at=? WHERE id=?")->execute([$until, date('Y-m-d H:i:s'), $o['id']]);
                mail_client($o, "Счёт по заказу {$o['number']}", "Выставлен $label по заказу № {$o['number']}. Счёт во вложении и в личном кабинете."
                    . ($until ? "\nСчёт на оборудование действует до " . lk_date($until, true) . ' (3 банковских дня) — после этого резерв снимается.' : '')
                    . "\nПосле оплаты прикрепите платёжное поручение в кабинете — так отгрузка пройдёт быстрее.",
                    [LK_FILES . '/' . $stored['file'], "Счёт {$o['number']}." . pathinfo($stored['file'], PATHINFO_EXTENSION), $stored['mime']]);
            } else {
                mail_client($o, "$label по заказу {$o['number']}", "По заказу № {$o['number']} в личном кабинете доступен документ: $label.");
            }
            lk_flash("$label прикреплён, клиенту отправлено письмо");
        } catch (RuntimeException $e) { lk_flash($e->getMessage(), 'bad'); }
    } elseif ($act === 'keys') {
        $keys = trim((string)($_POST['keys'] ?? ''));
        $pdo->prepare('UPDATE orders SET keys_text=?, updated_at=? WHERE id=?')->execute([mb_substr($keys, 0, 10000), date('Y-m-d H:i:s'), $o['id']]);
        if ($keys !== '' && !empty($_POST['notify']))
            mail_client($o, "Ключи и дистрибутивы по заказу {$o['number']}", 'Ссылки на дистрибутивы и пин-коды по вашему заказу доступны в личном кабинете, в карточке заказа.'
                . (in_array($o['status'], LK_KEYS_VISIBLE, true) ? '' : ' Они станут видны после подтверждения оплаты.'));
        lk_flash('Ключи сохранены' . (in_array($o['status'], LK_KEYS_VISIBLE, true) ? '' : ' (клиент увидит их после смены статуса на «Оплачен»)'));
    } elseif ($act === 'deldoc') {
        $pdo->prepare('UPDATE documents SET blocked=1 WHERE id=? AND order_id=?')->execute([(int)$_POST['doc'], $o['id']]);
        lk_flash('Документ помечен недействительным');
    } elseif ($act === 'as' && $o['user_id']) {
        $_SESSION['as'] = (int)$o['user_id']; lk_redirect('/lk/orders/?n=' . urlencode($o['number']));
    }
    lk_redirect($back);
}
if (isset($_GET['as'])) { $_SESSION['as'] = (int)$_GET['as']; lk_redirect('/lk/'); }
adm_staff_get();      // чат, сотрудники, мой профиль
adm_services_get();   // услуги, обращения (выходит сам, если страница его)
adm_payments_get();
adm_billing_get();

/* ---------- карточка заказа ---------- */
if (isset($_GET['n'])) {
    $st = $pdo->prepare("SELECT * FROM orders WHERE number=?"); $st->execute([(string)$_GET['n']]);
    $o = $st->fetch();
    if (!$o) mgr_page('Не найден', '<h1>Заказ не найден</h1>');
    $o = lk_apply_reserve($o);
    $f = fn() => lk_csrf_field() . '<input type="hidden" name="id" value="' . $o['id'] . '">';
    $rows = '';
    foreach (lk_items($o) as $it)
        $rows .= '<tr><td>' . h($it['name']) . (lk_is_hw_item($it) ? ' <span class="badge wait">оборудование</span>' : '') . '</td><td>' . h($it['code']) . '</td><td>' . (int)$it['qty'] . '</td><td>' . lk_money($it) . '</td><td>' . lk_money($it, true) . '</td></tr>';
    $opts = implode('', array_map(fn($s) => '<option' . ($s === $o['status'] ? ' selected' : '') . '>' . h($s) . '</option>', array_keys(LK_ST)));
    $kinds = implode('', array_map(fn($k, $v) => $k === 'payment' ? '' : '<option value="' . $k . '">' . $v . '</option>', array_keys(LK_DOC_KINDS), LK_DOC_KINDS));
    $docs = '';
    foreach (lk_docs_for_order((int)$o['id']) as $d)
        $docs .= '<tr><td>' . h(LK_DOC_KINDS[$d['kind']] ?? '') . ($d['number'] ? ' № ' . h($d['number']) : '') . '</td><td>' . lk_date($d['doc_date']) . '</td><td>' . ($d['uploaded_by'] === 'client' ? 'клиент' : 'менеджер') . '</td>'
            . '<td>' . ($d['blocked'] ? '<span class="badge bad">недействителен</span>' : '<span class="badge ok">действует</span>') . '</td><td><a href="/lk/file.php?id=' . $d['id'] . '" target="_blank">Открыть</a>'
            . ($d['blocked'] ? '' : ' <form method="post" class="inline">' . $f() . '<input type="hidden" name="act" value="deldoc"><input type="hidden" name="doc" value="' . $d['id'] . '"><button class="link">аннулировать</button></form>') . '</td></tr>';
    $pays = '';
    foreach (pay_history((int)$o['id']) as $p) $pays .= '<tr><td>' . lk_date($p['payment_date']) . '</td><td>п/п № ' . h($p['payment_order_num']) . '</td><td>' . money_rub((float)$p['amount']) . '</td><td>' . h($p['source']) . ' · ' . h($p['match_note']) . '</td></tr>';
    $payCard = '<div class="card cut"><h2>Оплаты</h2><p>К оплате: <b>' . money_rub(pay_due($o)) . '</b>' . ($o['invoice_amount'] ? ' (по счёту № ' . h($o['invoice_number']) . ')' : ' (по прайсу — укажите сумму счёта при загрузке)') . ' · оплачено: <b>' . money_rub((float)$o['paid_amount']) . '</b></p>'
        . ($pays ? '<table class="tbl"><tbody>' . $pays . '</tbody></table>' : '<p class="muted">Поступлений нет.</p>') . '</div>';
    $body = '<p><a href="/lk/admin/">← Все заказы</a></p><h1>Заказ № ' . h($o['number']) . ' ' . lk_badge($o['status']) . '</h1>'
        . '<div class="grid2"><div class="card cut"><h2>Клиент</h2><p><b>' . h($o['name']) . '</b><br>' . h($o['email']) . '<br>' . h($o['phone']) . '<br>' . h($o['company'] ?: 'Физлицо') . ($o['inn'] ? ', ИНН ' . h($o['inn']) : '') . '</p>'
        . '<p class="muted">' . h($o['delivery']) . ' · ' . h($o['payment']) . ($o['comment'] ? '<br>Комментарий: ' . nl2br(h($o['comment'])) : '') . '</p>'
        . ($o['user_id'] ? '<form method="post">' . $f() . '<button class="btn ghost small" name="act" value="as">Открыть кабинет глазами клиента</button></form>' : '<p class="muted">Не привязан к кабинету</p>') . '</div>'
        . '<div class="card cut"><h2>Статус</h2><form method="post">' . $f() . '<input type="hidden" name="act" value="status"><label>Статус<select name="status">' . $opts . '</select></label>'
        . '<label>Заметка менеджера<textarea name="note" rows="3">' . h($o['manager_note']) . '</textarea></label><button class="btn">Сохранить</button></form>'
        . ($o['valid_until'] ? '<p class="muted">Резерв / счёт действует до ' . lk_date($o['valid_until'], true) . '</p>' : '') . '</div></div>'
        . '<div class="card cut"><h2>Позиции</h2><table class="tbl"><thead><tr><th>Наименование</th><th>Артикул</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p class="total">Итого по прайсу: <b>' . money_rub((float)$o['total_rub']) . ((float)$o['total_usd'] ? ' + ' . money_usd((float)$o['total_usd']) : '') . '</b></p><p><a href="/lk/kp.php?n=' . urlencode($o['number']) . '" target="_blank">КП в PDF</a></p></div>'
        . $payCard . '<div class="card cut"><h2>Документы</h2>' . ($docs ? '<table class="tbl"><tbody>' . $docs . '</tbody></table>' : '<p class="muted">Документов нет.</p>')
        . '<h3>Прикрепить документ из 1С</h3><form method="post" enctype="multipart/form-data" class="filters">' . $f() . '<input type="hidden" name="act" value="doc">'
        . '<label>Тип<select name="kind">' . $kinds . '</select></label><label>Номер<input name="number" maxlength="50"></label><label>Дата<input type="date" name="date" value="' . date('Y-m-d') . '"></label><label>Сумма счёта, ₽ (для сверки оплат)<input name="amount" inputmode="decimal" placeholder="' . h(number_format((float)$o['total_rub'], 2, ',', '')) . '"></label>'
        . '<label>Файл (PDF)<input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required></label><button class="btn">Прикрепить и уведомить клиента</button></form>'
        . '<p class="muted">Счёт переводит заказ в «Ожидает оплаты»' . ($o['has_hw'] ? ' и запускает резерв на 3 банковских дня (в заказе есть оборудование)' : '') . '. Предыдущие счета становятся недействительными.</p></div>'
        . '<div class="card cut"><h2>Электронные поставки и ключи</h2><form method="post">' . $f() . '<input type="hidden" name="act" value="keys">'
        . '<label>Ссылки на дистрибутивы, пин-коды (клиент видит после статуса «Оплачен»)<textarea name="keys" rows="5">' . h($o['keys_text']) . '</textarea></label>'
        . '<label class="chk"><input type="checkbox" name="notify" value="1" checked> Уведомить клиента письмом (без самих ключей)</label><button class="btn">Сохранить</button></form></div>';
    mgr_page('Заказ ' . $o['number'], $body);
}

/* ---------- клиенты ---------- */
if (isset($_GET['users'])) {
    $rows = '';
    foreach ($pdo->query("SELECT u.*, (SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id AND o.type='order') n FROM users u ORDER BY u.id DESC LIMIT 500") as $c)
        $rows .= '<tr><td>' . h($c['email']) . '</td><td>' . h($c['name']) . '</td><td>' . h($c['phone']) . '</td><td>' . (int)$c['n'] . '</td><td>' . lk_date($c['last_login'], true) . '</td>'
            . '<td>' . ($c['pass_hash'] ? 'пароль' : ($c['verified'] ? 'код' : 'не входил')) . '</td><td><a href="?as=' . $c['id'] . '">кабинет клиента</a> · <a href="?svc=new&uid=' . $c['id'] . '">подключить услугу</a></td></tr>';
    mgr_page('Клиенты', '<h1>Клиенты</h1><div class="card cut"><table class="tbl"><thead><tr><th>E-mail</th><th>Имя</th><th>Телефон</th><th>Заказов</th><th>Последний вход</th><th>Вход</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
}

/* ---------- обращения и заявки ---------- */
if (isset($_GET['leads'])) {
    $rows = '';
    foreach ($pdo->query("SELECT * FROM orders WHERE type='lead' ORDER BY id DESC LIMIT 300") as $t)
        $rows .= '<tr><td>' . h($t['number']) . '</td><td>' . lk_date($t['created_at'], true) . '</td><td>' . h($t['name']) . '<br><span class="muted">' . h($t['phone']) . ' ' . h($t['email']) . '</span></td>'
            . '<td>' . nl2br(h(mb_strimwidth((string)$t['comment'], 0, 300, '…'))) . '</td><td><form method="post" class="inline">' . lk_csrf_field() . '<input type="hidden" name="id" value="' . $t['id'] . '"><input type="hidden" name="act" value="status">'
            . '<select name="status" onchange="this.form.submit()">' . implode('', array_map(fn($s) => '<option' . ($s === $t['status'] ? ' selected' : '') . '>' . h($s) . '</option>', ['Новый', 'В работе', 'Выполнен', 'Отменён'])) . '</select></form></td></tr>';
    mgr_page('Обращения', '<h1>Обращения и заявки</h1><div class="card cut"><table class="tbl"><thead><tr><th>Номер</th><th>Дата</th><th>Клиент</th><th>Текст</th><th>Статус</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
}

/* ---------- список заказов ---------- */
$f = array_key_exists($_GET['f'] ?? '', LK_FILTERS) ? $_GET['f'] : 'all';
$q = trim((string)($_GET['q'] ?? ''));
$rows = ''; $all = array_map('lk_apply_reserve', $pdo->query("SELECT * FROM orders WHERE type='order' ORDER BY id DESC LIMIT 1000")->fetchAll());
foreach ($all as $o) {
    if (LK_FILTERS[$f][1] !== null && !in_array($o['status'], LK_FILTERS[$f][1], true)) continue;
    if ($q !== '' && mb_stripos($o['number'] . ' ' . $o['email'] . ' ' . $o['company'] . ' ' . $o['inn'] . ' ' . $o['items_json'], $q) === false) continue;
    $rows .= '<tr><td><a href="?n=' . urlencode($o['number']) . '"><b>' . h($o['number']) . '</b></a></td><td>' . lk_date($o['created_at'], true) . '</td><td>' . h($o['company'] ?: $o['name']) . '<br><span class="muted">' . h($o['email']) . '</span></td>'
        . '<td>' . money_rub((float)$o['total_rub']) . '</td><td>' . lk_badge($o['status']) . ($o['status'] === 'Платёж на проверке' ? ' ⚑' : '') . '</td></tr>';
}
$tabs = '';
foreach (LK_FILTERS as $k => [$label, $sts]) $tabs .= '<a href="?f=' . $k . '"' . ($k === $f ? ' class="on"' : '') . '>' . $label . ' <i>' . count(array_filter($all, fn($o) => $sts === null || in_array($o['status'], $sts, true))) . '</i></a>';
mgr_page('Заказы', '<h1>Заказы клиентов</h1><form class="search" method="get"><input type="hidden" name="f" value="' . h($f) . '"><input name="q" value="' . h($q) . '" placeholder="Номер, e-mail, ИНН, компания, артикул"><button class="btn">Найти</button></form>'
    . '<div class="tabs scroll">' . $tabs . '</div><div class="card cut"><table class="tbl"><thead><tr><th>Номер</th><th>Дата</th><th>Клиент</th><th>Сумма</th><th>Статус</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5" class="muted">Заказов нет</td></tr>') . '</tbody></table></div>');
