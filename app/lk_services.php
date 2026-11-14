<?php
/* Личный кабинет, этап 2: услуги (аренда серверов, сопровождение 1С, договоры 1С:ИТС), часы специалиста, продление, обращения. */
declare(strict_types=1);

const SVC_KINDS = ['server' => 'Аренда сервера', 'support' => 'Сопровождение 1С', 'its' => 'Договор 1С:ИТС'];
const SVC_STATUS = ['Активен' => 'ok', 'Обслуживание' => 'wait', 'Приостановлен' => 'bad', 'Закрыт' => 'bad'];
const SVC_PERIODS = [1 => '01', 3 => '03', 6 => '06', 12 => '12'];
const TICKET_TOPICS = ['Сервер / аренда', '1С', 'Гарантия и оборудование', 'Заказ и документы', 'Другое'];
const TICKET_ST = ['Открыто' => 'wait', 'Ответ получен' => 'ok', 'Ожидает ответа клиента' => 'wait', 'Закрыто' => 'bad'];

function svc_db(): PDO {
    static $done = false;
    $pdo = lk_db();
    if ($done) return $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, company_id INTEGER, kind TEXT NOT NULL, title TEXT,
        tariff_code TEXT, period_months INTEGER DEFAULT 1, price REAL DEFAULT 0, config_json TEXT, hours_month REAL DEFAULT 0,
        vpn_status TEXT DEFAULT 'Активен', status TEXT DEFAULT 'Активен', started TEXT, paid_until TEXT,
        promised_until TEXT, promised_for TEXT, note TEXT, created_at TEXT, updated_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_hours (id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER, work_date TEXT, hours REAL, description TEXT, created_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_reminders (service_id INTEGER, paid_until TEXT, days INTEGER, sent_at TEXT, PRIMARY KEY(service_id, paid_until, days))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, number TEXT, user_id INTEGER, topic TEXT, subject TEXT,
        status TEXT DEFAULT 'Открыто', service_id INTEGER, created_at TEXT, updated_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_msgs (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER, author TEXT, text TEXT, file TEXT, mime TEXT, created_at TEXT)");
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    foreach (['service_id' => 'INTEGER', 'renew_months' => 'INTEGER'] as $c => $t)
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN $c $t");
    $done = true;
    return $pdo;
}

function svc_for_user(int $uid): array {
    $st = svc_db()->prepare("SELECT * FROM services WHERE user_id=? AND status<>'Закрыт' ORDER BY kind, id"); $st->execute([$uid]);
    return $st->fetchAll();
}
function svc_get(int $id, ?int $uid = null): ?array {
    $st = svc_db()->prepare('SELECT * FROM services WHERE id=?' . ($uid ? ' AND user_id=?' : '')); $st->execute($uid ? [$id, $uid] : [$id]);
    return $st->fetch() ?: null;
}
/* действует до: оплаченный период или обещанный платёж, если он позже */
function svc_until(array $s): ?string {
    $u = $s['paid_until'];
    if ($s['promised_until'] && (!$u || $s['promised_until'] > $u)) $u = $s['promised_until'];
    return $u;
}
function svc_days_left(array $s): ?int {
    $u = svc_until($s);
    if (!$u) return null;
    return (int)floor((strtotime($u . ' 23:59:59') - time()) / 86400);
}
function svc_config(array $s): array { return json_decode((string)$s['config_json'], true) ?: []; }
function svc_hours_used(int $sid, ?string $month = null): float {
    $month ??= date('Y-m');
    $st = svc_db()->prepare("SELECT COALESCE(SUM(hours),0) FROM service_hours WHERE service_id=? AND substr(work_date,1,7)=?"); $st->execute([$sid, $month]);
    return (float)$st->fetchColumn();
}
function svc_hours_list(int $sid, ?string $month = null): array {
    $month ??= date('Y-m');
    $st = svc_db()->prepare("SELECT * FROM service_hours WHERE service_id=? AND substr(work_date,1,7)=? ORDER BY work_date DESC, id DESC"); $st->execute([$sid, $month]);
    return $st->fetchAll();
}
/* позиция для счёта на продление: из каталога (тарифы ИНФОТЕХ) или по цене услуги */
function svc_renew_item(array $s): array {
    $months = (int)($s['period_months'] ?: 1);
    $code = (string)$s['tariff_code'];
    if ($s['kind'] === 'server' && preg_match('/^INF-SRV-[SP]\d$/', $code)) $code .= '-' . (SVC_PERIODS[$months] ?? '01');
    $p = $code !== '' ? (catalog()[$code] ?? null) : null;
    if ($p) {
        $qty = $s['kind'] === 'support' ? $months : 1;   // сопровождение — помесячно
        return ['code' => $code, 'name' => $p['name'], 'qty' => $qty, 'price' => $p['price'], 'cur' => $p['cur'], 'sum' => $p['price'] * $qty, 'e' => 2];
    }
    $price = (float)$s['price'];
    return ['code' => 'SVC-' . $s['id'], 'name' => 'Продление: ' . ($s['title'] ?: SVC_KINDS[$s['kind']]) . " — $months мес.", 'qty' => 1, 'price' => $price, 'cur' => 'RUB', 'sum' => $price, 'e' => 2];
}
/* открытый заказ на продление (если уже есть — возвращаем его) */
function svc_open_renewal(array $s): ?array {
    $st = svc_db()->prepare("SELECT * FROM orders WHERE service_id=? AND type='order' AND status IN ('Новый','Счёт выставлен','Платёж на проверке') ORDER BY id DESC LIMIT 1");
    $st->execute([$s['id']]);
    return $st->fetch() ?: null;
}
function svc_create_renewal(array $s, string $by = 'client'): array {
    if ($o = svc_open_renewal($s)) return $o;
    $pdo = svc_db();
    $u = $pdo->query('SELECT * FROM users WHERE id=' . (int)$s['user_id'])->fetch();
    $c = $s['company_id'] ? $pdo->query('SELECT * FROM user_companies WHERE id=' . (int)$s['company_id'])->fetch() : null;
    $it = svc_renew_item($s);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO orders (type, created_at, name, email, phone, inn, company, delivery, payment, comment, total_rub, total_usd, items_json, user_id, company_id, service_id, renew_months, has_hw, updated_at)
                   VALUES ('order',?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,0,?)")
        ->execute([$now, $u['name'], $u['email'], $u['phone'], $c['inn'] ?? '', $c['name'] ?? '', 'Продление услуги', 'Безналичный расчёт по счёту',
                   'Продление: ' . ($s['title'] ?: SVC_KINDS[$s['kind']]) . ($by === 'cron' ? ' (автоматически, срок оплаты подходит к концу)' : ''),
                   $it['sum'], json_encode([$it], JSON_UNESCAPED_UNICODE), $s['user_id'], $s['company_id'], $s['id'], (int)($s['period_months'] ?: 1), $now]);
    $id = (int)$pdo->lastInsertId();
    $number = 'ИТ-' . date('ymd') . '-' . $id;
    $pdo->prepare('UPDATE orders SET number=? WHERE id=?')->execute([$number, $id]);
    lk_notify_manager("Продление {$number}: " . ($s['title'] ?: SVC_KINDS[$s['kind']]),
        "Заказ на продление услуги «" . ($s['title'] ?: SVC_KINDS[$s['kind']]) . "» клиента {$u['email']}" . ($c ? " ({$c['name']}, ИНН {$c['inn']})" : '') . ".\n"
        . "Оплачено до: " . lk_date($s['paid_until']) . "\nПозиция: {$it['name']} — " . money_rub((float)$it['sum']) . "\nВыставьте счёт в 1С и прикрепите его к заказу {$number}.");
    $st = $pdo->prepare('SELECT * FROM orders WHERE id=?'); $st->execute([$id]);
    return $st->fetch();
}
/* оплата заказа на продление → сдвигаем оплаченный период */
function svc_apply_payment(array $o): ?string {
    if (empty($o['service_id'])) return null;
    $s = svc_get((int)$o['service_id']);
    if (!$s) return null;
    $months = (int)($o['renew_months'] ?: $s['period_months'] ?: 1);
    $base = $s['paid_until'] && $s['paid_until'] >= date('Y-m-d') ? $s['paid_until'] : date('Y-m-d');
    $new = date('Y-m-d', strtotime("$base +$months month"));
    svc_db()->prepare("UPDATE services SET paid_until=?, promised_until=NULL, status=CASE WHEN status='Приостановлен' THEN 'Активен' ELSE status END, updated_at=? WHERE id=?")
        ->execute([$new, date('Y-m-d H:i:s'), $s['id']]);
    return $new;
}
/* «обещанный платёж»: +3 дня, один раз за оплаченный период, когда до конца ≤ 3 дней или период уже закончился (не более 7 дней назад) */
function svc_can_promise(array $s): bool {
    if ($s['kind'] !== 'server' || !$s['paid_until'] || $s['promised_for'] === $s['paid_until']) return false;
    $d = (int)floor((strtotime($s['paid_until'] . ' 23:59:59') - time()) / 86400);
    return $d <= 3 && $d >= -7;
}
function svc_badge(array $s): string {
    $st = $s['status'];
    $d = svc_days_left($s);
    if ($st === 'Активен' && $d !== null && $d < 0) return '<span class="badge bad">Оплата просрочена</span>';
    if ($st === 'Активен' && $d !== null && $d <= 7) return '<span class="badge wait">Скоро продление</span>';
    return '<span class="badge ' . (SVC_STATUS[$st] ?? 'wait') . '">' . h($st) . '</span>';
}

/* ---------------- обращения ---------------- */
function tk_create(int $uid, string $topic, string $subject, string $text, ?array $file = null, ?int $serviceId = null): array {
    $pdo = svc_db(); $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO tickets (user_id, topic, subject, status, service_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$uid, $topic, $subject, 'Открыто', $serviceId, $now, $now]);
    $id = (int)$pdo->lastInsertId(); $num = 'О-' . date('ymd') . '-' . $id;
    $pdo->prepare('UPDATE tickets SET number=? WHERE id=?')->execute([$num, $id]);
    tk_add_msg($id, 'client', $text, $file);
    $u = $pdo->query('SELECT * FROM users WHERE id=' . $uid)->fetch();
    lk_notify_manager("Обращение $num: $topic", "Клиент: {$u['name']} <{$u['email']}>, {$u['phone']}\nТема: $topic — $subject\n\n$text\n\nОтветить: " . LK_SITE . "/lk/admin/?t=$id");
    send_telegram("Обращение $num из личного кабинета: $topic");   // без персональных данных
    return ['id' => $id, 'number' => $num];
}
function tk_add_msg(int $tid, string $author, string $text, ?array $file = null): void {
    $pdo = svc_db();
    $stored = null;
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) $stored = lk_store_upload($file, -$tid);   // вложения обращений: lk-files/-ID/
    $pdo->prepare('INSERT INTO ticket_msgs (ticket_id, author, text, file, mime, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$tid, $author, $text, $stored['file'] ?? null, $stored['mime'] ?? null, date('Y-m-d H:i:s')]);
    $pdo->prepare('UPDATE tickets SET status=?, updated_at=? WHERE id=?')
        ->execute([$author === 'client' ? 'Открыто' : 'Ответ получен', date('Y-m-d H:i:s'), $tid]);
}
function tk_get(int $id, ?int $uid = null): ?array {
    $st = svc_db()->prepare('SELECT * FROM tickets WHERE id=?' . ($uid ? ' AND user_id=?' : '')); $st->execute($uid ? [$id, $uid] : [$id]);
    return $st->fetch() ?: null;
}
function tk_msgs(int $tid): array {
    $st = svc_db()->prepare('SELECT * FROM ticket_msgs WHERE ticket_id=? ORDER BY id'); $st->execute([$tid]);
    return $st->fetchAll();
}
function tk_thread(array $t, string $fileBase): string {
    $out = '';
    foreach (tk_msgs((int)$t['id']) as $m)
        $out .= '<div class="msg ' . ($m['author'] === 'client' ? 'me' : 'them') . ' cut"><div class="msg-h"><b>' . ($m['author'] === 'client' ? 'Клиент' : 'ИНФОТЕХ') . '</b><span>' . lk_date($m['created_at'], true) . '</span></div>'
            . '<div class="msg-t">' . nl2br(h($m['text'])) . '</div>' . ($m['file'] ? '<a class="msg-f" href="' . $fileBase . $m['id'] . '">📎 Вложение</a>' : '') . '</div>';
    return '<div class="thread">' . $out . '</div>';
}
