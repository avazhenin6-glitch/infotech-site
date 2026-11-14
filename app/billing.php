<?php
/* CRM, этап 1: календарный биллинг аренды серверов (docs: «CRM ИНФОТЕХ: процессы и этапы»).
   - счёт на следующий месяц выставляет CRM за 5 дней до конца месяца, свой номер ИТ-000001 (БП счетов не создаёт);
   - срок оплаты — 5-й рабочий день оплачиваемого месяца (производственный календарь РФ, app/workcal.php);
   - не оплачено → «Закрыть доступ» (OpenVPN-учётка на MikroTik), ВМ работает; через 14 дней закрытого доступа → «Выключить ВМ»;
     через 30 дней после выключения → «Удалить данные» (только вручную, с подтверждением);
   - новый клиент с середины месяца — первый счёт пропорционально дням; закрытый доступ сумму не уменьшает.
   Реквизиты для счёта — app/config.php 'bank' (только на сервере). */
declare(strict_types=1);

const BILL_ISSUE_DAYS_LEFT = 5;   // счёт уходит, когда до конца месяца осталось 5 дней (включая день выставления)
const BILL_DUE_WORKDAY = 5;       // срок оплаты: 5-й рабочий день месяца
const BILL_VM_OFF_DAYS = 14;      // дней закрытого доступа до выключения ВМ
const BILL_PURGE_DAYS = 30;       // дней после выключения до удаления данных
const BILL_PAID = ['Оплачен', 'В работе', 'Выполнен'];
const BILL_MONTHS = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
const BILL_MONTHS_GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

function bill_db(): PDO {
    static $done = false;
    $pdo = pay_db(); svc_db();
    if ($done) return $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT, seq INTEGER UNIQUE NOT NULL, number TEXT UNIQUE NOT NULL, order_id INTEGER, service_id INTEGER,
        user_id INTEGER, period TEXT, period_from TEXT, issued TEXT, due TEXT, amount REAL, doc_id INTEGER, annulled INTEGER DEFAULT 0, created_by TEXT, created_at TEXT)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS invoices_svc_period ON invoices(service_id, period) WHERE service_id IS NOT NULL AND annulled=0");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bill_events (key TEXT PRIMARY KEY, at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bill_log (id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER, action TEXT, note TEXT, by TEXT, at TEXT)");
    $cols = array_column($pdo->query('PRAGMA table_info(services)')->fetchAll(), 'name');
    foreach (['vpn_login' => 'TEXT', 'contract' => 'TEXT', 'access' => "TEXT DEFAULT 'open'", 'access_at' => 'TEXT', 'vm' => "TEXT DEFAULT 'on'", 'vm_at' => 'TEXT', 'purged_at' => 'TEXT'] as $c => $t)
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE services ADD COLUMN $c $t");
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    foreach (['bill_period' => 'TEXT', 'bill_invoice_id' => 'INTEGER'] as $c => $t)
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN $c $t");
    $done = true;
    return $pdo;
}

/* ---------- даты ---------- */
function bill_today(): string {   // --date в cli; 'debug_today' в config.php — только для тестового стенда
    if (PHP_SAPI === 'cli' && getenv('PBK_TODAY')) return (string)getenv('PBK_TODAY');
    $d = (string)(cfg('debug_today') ?? '');
    return preg_match('/^\d{4}-\d\d-\d\d$/', $d) ? $d : date('Y-m-d');
}
function bill_now(): string { return bill_today() . date(' H:i:s'); }
function bill_month_end(string $ym): string { return date('Y-m-t', strtotime("$ym-01")); }
function bill_next_ym(string $ym): string { return date('Y-m', strtotime("$ym-01 +1 month")); }
function bill_issue_day(string $ym): string { return date('Y-m-d', strtotime(bill_month_end($ym) . ' -' . (BILL_ISSUE_DAYS_LEFT - 1) . ' day')); }
function bill_due(string $ym): string { return wc_nth_workday($ym, BILL_DUE_WORKDAY); }
function bill_month_name(string $ym): string { return BILL_MONTHS[(int)substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4); }
function bill_date_words(string $d): string { return (int)substr($d, 8, 2) . ' ' . BILL_MONTHS_GEN[(int)substr($d, 5, 2) - 1] . ' ' . substr($d, 0, 4) . ' г.'; }
function bill_days(string $from, string $to): int { return (int)round((strtotime($to) - strtotime($from)) / 86400); }

/* ---------- сумма ---------- */
function bill_monthly_price(array $s): float {
    $months = max(1, (int)($s['period_months'] ?: 1));
    if ((float)$s['price'] > 0) return round((float)$s['price'] / $months, 2);
    $code = (string)$s['tariff_code'];
    if (preg_match('/^INF-SRV-[SP]\d$/', $code)) $code .= '-01';
    return (float)(catalog()[$code]['price'] ?? 0);
}
/* за месяц ym; $from — дата подключения внутри месяца: пропорционально календарным дням */
function bill_amount(array $s, string $ym, ?string $from = null): float {
    $m = bill_monthly_price($s);
    if (!$from || $from <= "$ym-01") return $m;
    $all = (int)date('t', strtotime("$ym-01"));
    $left = bill_days($from, bill_month_end($ym)) + 1;
    return round($m * $left / $all);
}

/* ---------- реквизиты ---------- */
function bill_bank(): array {
    $b = cfg('bank');
    if (!is_array($b) || empty($b['account']) || empty($b['bik']) || empty($b['name']) || empty($b['corr']))
        throw new RuntimeException('Не заполнены банковские реквизиты ИНФОТЕХ (app/config.php → bank): счёт не выставлен');
    return $b;
}
function bill_next_seq(): int { return (int)bill_db()->query('SELECT COALESCE(MAX(seq),0)+1 FROM invoices')->fetchColumn(); }
function bill_number(int $seq): string { return 'ИТ-' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT); }

/* ---------- выставление счёта ---------- */
function bill_invoice_for(int $sid, string $ym): ?array {
    $st = bill_db()->prepare('SELECT * FROM invoices WHERE service_id=? AND period=? AND annulled=0'); $st->execute([$sid, $ym]);
    return $st->fetch() ?: null;
}
function bill_issue(array $s, string $ym, ?string $from = null, string $by = 'cron', ?string $today = null): array {
    $pdo = bill_db(); $today ??= bill_today();
    if ($inv = bill_invoice_for((int)$s['id'], $ym)) return $inv;
    bill_bank();
    $amount = bill_amount($s, $ym, $from);
    if ($amount <= 0) throw new RuntimeException('У услуги не задана цена — счёт не выставлен');
    $u = $pdo->query('SELECT * FROM users WHERE id=' . (int)$s['user_id'])->fetch();
    $c = $s['company_id'] ? $pdo->query('SELECT * FROM user_companies WHERE id=' . (int)$s['company_id'])->fetch() : null;
    $title = $s['title'] ?: SVC_KINDS[$s['kind']];
    $partial = $from && $from > "$ym-01";
    $itemName = 'Аренда виртуального сервера «' . $title . '» за ' . bill_month_name($ym)
        . ($partial ? ' (с ' . date('d.m.Y', strtotime($from)) . ', ' . (bill_days($from, bill_month_end($ym)) + 1) . ' из ' . date('t', strtotime("$ym-01")) . ' дн.)' : '');
    $item = ['code' => (string)($s['tariff_code'] ?: 'SVC-' . $s['id']), 'name' => $itemName, 'qty' => 1, 'price' => $amount, 'cur' => 'RUB', 'sum' => $amount, 'e' => 2];
    $due = bill_due($ym);
    if ($due < $today) $due = wc_add_workdays($today, BILL_DUE_WORKDAY);   // счёт за уже идущий месяц — 5 рабочих дней с сегодняшнего
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO orders (type, status, created_at, name, email, phone, inn, company, delivery, payment, comment, total_rub, total_usd, items_json, user_id, company_id, service_id, renew_months, has_hw, updated_at, bill_period)
                       VALUES ('order','Счёт выставлен',?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,1,0,?,?)")
            ->execute([$now, $u['name'], $u['email'], $u['phone'], $c['inn'] ?? '', $c['name'] ?? '', 'Аренда сервера', 'Безналичный расчёт по счёту',
                       "Счёт за " . bill_month_name($ym) . ($by === 'cron' ? ' (выставлен автоматически)' : ''), $amount,
                       json_encode([$item], JSON_UNESCAPED_UNICODE), $s['user_id'], $s['company_id'], $s['id'], $now, $ym]);
        $oid = (int)$pdo->lastInsertId();
        $onum = 'ИТ-' . date('ymd', strtotime($today)) . '-' . $oid;
        $seq = bill_next_seq(); $num = bill_number($seq);
        $pdo->prepare('INSERT INTO invoices (seq, number, order_id, service_id, user_id, period, period_from, issued, due, amount, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$seq, $num, $oid, $s['id'], $s['user_id'], $ym, $partial ? $from : null, $today, $due, $amount, $by, $now]);
        $iid = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE orders SET number=?, invoice_number=?, invoice_date=?, invoice_amount=?, bill_invoice_id=? WHERE id=?')->execute([$onum, $num, $today, $amount, $iid, $oid]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    $o = $pdo->query("SELECT * FROM orders WHERE id=$oid")->fetch();
    $inv = $pdo->query("SELECT * FROM invoices WHERE id=$iid")->fetch();
    $stored = bill_pdf_store($inv, $o, $s, $c, $u);
    $doc = lk_add_document($o, 'invoice', $stored, LK_DOC_KINDS['invoice'] ?? 'Счёт', $num, $today, 'crm');
    $pdo->prepare('UPDATE invoices SET doc_id=? WHERE id=?')->execute([$doc, $iid]);
    $inv['doc_id'] = $doc;
    if ($u['email'])
        send_mail_attach($u['email'], "Счёт № $num за " . bill_month_name($ym) . " — аренда сервера",
            "Здравствуйте" . ($u['name'] ? ", {$u['name']}" : '') . "!\n\nВо вложении счёт № $num от " . lk_date($today) . " на " . money_rub($amount)
            . " за аренду сервера «{$title}» на " . bill_month_name($ym) . ".\nОплатите, пожалуйста, до " . lk_date($due) . " включительно."
            . " В назначении платежа укажите номер счёта — так оплата зачтётся автоматически.\n\n"
            . "Если оплата не поступит в срок, доступ к серверу будет приостановлен (данные сохраняются).\n"
            . "Счёт и история оплат — в личном кабинете: " . LK_SITE . '/lk/orders/?n=' . urlencode($o['number']) . "\n\nООО «ИНФОТЕХ», 8 (812) 928-68-84, info@pbk.spb.ru",
            LK_FILES . '/' . $stored['file'], "Счёт $num.pdf", 'application/pdf');
    bill_log((int)$s['id'], 'invoice', "счёт $num на " . money_rub($amount) . " за " . bill_month_name($ym) . ", срок " . lk_date($due), $by);
    return $inv;
}

/* открытые (неоплаченные) счета услуги, старые первыми */
function bill_open_invoices(int $sid): array {
    $ph = implode(',', array_fill(0, count(BILL_PAID), '?'));
    $st = bill_db()->prepare("SELECT i.*, o.number AS order_number, o.status AS order_status, o.paid_amount FROM invoices i JOIN orders o ON o.id=i.order_id
                              WHERE i.service_id=? AND i.annulled=0 AND o.status NOT IN ($ph) AND o.status<>'Отменён' ORDER BY i.period, i.id");
    $st->execute([$sid, ...BILL_PAID]);
    return $st->fetchAll();
}
function bill_invoices(int $sid, int $limit = 24): array {
    $st = bill_db()->prepare("SELECT i.*, o.number AS order_number, o.status AS order_status, o.paid_amount FROM invoices i JOIN orders o ON o.id=i.order_id WHERE i.service_id=? ORDER BY i.id DESC LIMIT $limit");
    $st->execute([$sid]);
    return $st->fetchAll();
}
/* срок с учётом обещанного платежа (он привязан к номеру счёта) */
function bill_eff_due(array $inv, array $s): string {
    return ($s['promised_for'] === $inv['number'] && $s['promised_until'] > $inv['due']) ? $s['promised_until'] : $inv['due'];
}
/* просроченные счета на дату */
function bill_overdue(array $s, ?string $today = null): array {
    $today ??= bill_today();
    return array_values(array_filter(bill_open_invoices((int)$s['id']), fn($i) => bill_eff_due($i, $s) < $today));
}
function bill_debt(int $sid): float {
    $sum = 0.0;
    foreach (bill_open_invoices($sid) as $i) $sum += (float)$i['amount'] - (float)$i['paid_amount'];
    return max(0, round($sum, 2));
}

/* обещанный платёж для аренды: один раз на счёт, от дня до срока и до 7 дней после него */
function bill_can_promise(array $s, ?string $today = null): ?array {
    $today ??= bill_today();
    if ($s['access'] === 'closed' && $s['vm'] === 'off') return null;
    foreach (bill_open_invoices((int)$s['id']) as $i) {
        if ($s['promised_for'] === $i['number']) continue;
        if ($today >= wc_prev_workday($i['due']) && bill_days($i['due'], $today) <= 7) return $i;
    }
    return null;
}

/* ---------- журнал и события ---------- */
function bill_log(int $sid, string $action, string $note, string $by): void {
    bill_db()->prepare('INSERT INTO bill_log (service_id, action, note, by, at) VALUES (?,?,?,?,?)')->execute([$sid, $action, $note, $by, date('Y-m-d H:i:s')]);
}
function bill_once(string $key, bool $dry = false): bool {   // true — событие новое (и помечено)
    $pdo = bill_db();
    $st = $pdo->prepare('SELECT 1 FROM bill_events WHERE key=?'); $st->execute([$key]);
    if ($st->fetchColumn()) return false;
    if (!$dry) $pdo->prepare('INSERT INTO bill_events (key, at) VALUES (?,?)')->execute([$key, date('Y-m-d H:i:s')]);
    return true;
}

/* ---------- доступ, ВМ, данные ---------- */
function bill_client(array $s): array {
    $u = bill_db()->query('SELECT * FROM users WHERE id=' . (int)$s['user_id'])->fetch() ?: [];
    return [$u['email'] ?? '', $u['name'] ?? ''];
}
function bill_set_access(array $s, bool $open, string $by): string {
    $note = '';
    if (mt_configured() && $s['vpn_login']) $note = mt_set_access((string)$s['vpn_login'], $open);
    else $note = ($open ? 'доступ открыт' : 'доступ закрыт') . ' вручную на MikroTik' . ($s['vpn_login'] ? " ({$s['vpn_login']})" : '');
    $now = bill_now();
    bill_db()->prepare('UPDATE services SET access=?, access_at=?, vpn_status=?, status=?, updated_at=? WHERE id=?')
        ->execute([$open ? 'open' : 'closed', $now, $open ? 'Активен' : 'Отключён', $open ? 'Активен' : 'Приостановлен', $now, $s['id']]);
    bill_log((int)$s['id'], $open ? 'open' : 'close', $note, $by);
    [$email, $name] = bill_client($s); $title = $s['title'] ?: 'сервер';
    if ($email) send_mail($email, $open ? "Доступ к серверу «{$title}» восстановлен" : "Доступ к серверу «{$title}» приостановлен",
        "Здравствуйте" . ($name ? ", $name" : '') . "!\n\n" . ($open
            ? "Оплата получена, доступ к серверу «{$title}» снова открыт. Подключайтесь через OpenVPN как обычно."
            : "Доступ к серверу «{$title}» приостановлен из-за неоплаты. Сервер и базы работают, данные сохраняются.\n"
              . "Задолженность: " . money_rub(bill_debt((int)$s['id'])) . ". После оплаты доступ откроется в течение рабочего дня.\n"
              . "Если оплата не поступит в течение " . BILL_VM_OFF_DAYS . " дней, сервер будет выключен, а ещё через " . BILL_PURGE_DAYS . " дней данные будут удалены по условиям договора.")
        . "\n\nСчета: " . LK_SITE . "/lk/services/\nООО «ИНФОТЕХ», 8 (812) 928-68-84");
    return $note;
}
function bill_set_vm(array $s, bool $on, string $by): void {
    $now = bill_now();
    bill_db()->prepare('UPDATE services SET vm=?, vm_at=?, updated_at=? WHERE id=?')->execute([$on ? 'on' : 'off', $now, $now, $s['id']]);
    bill_log((int)$s['id'], $on ? 'vm_on' : 'vm_off', $on ? 'ВМ включена' : 'ВМ выключена (штатно)', $by);
    if ($on) return;
    [$email, $name] = bill_client($s); $title = $s['title'] ?: 'сервер';
    $purge = date('Y-m-d', strtotime(substr($now, 0, 10) . ' +' . BILL_PURGE_DAYS . ' day'));
    if ($email) send_mail($email, "Сервер «{$title}» выключен за неоплату",
        "Здравствуйте" . ($name ? ", $name" : '') . "!\n\nСервер «{$title}» выключен: оплата не поступила в течение " . BILL_VM_OFF_DAYS . " дней после приостановки доступа.\n"
        . "Задолженность: " . money_rub(bill_debt((int)$s['id'])) . ".\nЕсли оплата не поступит до " . lk_date($purge) . ", данные и резервные копии будут удалены без возможности восстановления.\n\n"
        . "Счета: " . LK_SITE . "/lk/services/\nООО «ИНФОТЕХ», 8 (812) 928-68-84");
}
function bill_purge_date(array $s): ?string {
    return $s['vm'] === 'off' && $s['vm_at'] ? date('Y-m-d', strtotime(substr($s['vm_at'], 0, 10) . ' +' . BILL_PURGE_DAYS . ' day')) : null;
}
function bill_mark_purged(array $s, string $by): void {
    $now = bill_now();
    bill_db()->prepare("UPDATE services SET purged_at=?, status='Закрыт', updated_at=? WHERE id=?")->execute([$now, $now, $s['id']]);
    bill_log((int)$s['id'], 'purge', 'данные и резервные копии удалены, услуга закрыта', $by);
}

/* ---------- оплата счёта (из pay_apply / смены статуса) ---------- */
function bill_on_paid(array $o): ?string {
    if (empty($o['bill_period']) || empty($o['service_id'])) return null;
    $pdo = bill_db();
    $s = svc_get((int)$o['service_id']);
    if (!$s) return null;
    $end = bill_month_end((string)$o['bill_period']);
    $paid = $s['paid_until'] && $s['paid_until'] > $end ? $s['paid_until'] : $end;
    $pdo->prepare("UPDATE services SET paid_until=?, promised_until=NULL, updated_at=? WHERE id=?")->execute([$paid, date('Y-m-d H:i:s'), $s['id']]);
    $s = svc_get((int)$s['id']);
    bill_log((int)$s['id'], 'paid', "оплачен счёт {$o['invoice_number']}, оплачено до " . lk_date($paid), 'оплата');
    if ($s['access'] === 'closed' && !bill_overdue($s) && !$s['purged_at']) {
        if ($s['vm'] === 'on' && mt_configured() && $s['vpn_login'] && !empty(cfg('mikrotik')['auto'])) {
            try { bill_set_access($s, true, 'авто'); }
            catch (RuntimeException $e) { lk_notify_manager('Не удалось открыть доступ', "Сервер «{$s['title']}»: " . $e->getMessage()); }
        } else lk_notify_manager("Оплата пришла — откройте доступ: {$s['title']}",
            "Счёт {$o['invoice_number']} оплачен. Сервер «{$s['title']}» в списке «Включить»" . ($s['vm'] === 'off' ? ' (ВМ выключена — сначала включите её)' : '') . ".\n" . LK_SITE . '/lk/admin/?billing=1');
    }
    return $paid;
}

/* ---------- списки для менеджера ---------- */
function bill_servers(): array {
    return bill_db()->query("SELECT s.*, u.email, u.name AS uname, c.name AS cname FROM services s JOIN users u ON u.id=s.user_id LEFT JOIN user_companies c ON c.id=s.company_id
                             WHERE s.kind='server' AND s.status<>'Закрыт' ORDER BY COALESCE(c.name, u.email)")->fetchAll();
}
function bill_lists(?string $today = null): array {
    $today ??= bill_today();
    $L = ['close' => [], 'open' => [], 'vm_off' => [], 'purge' => []];
    foreach (bill_servers() as $s) {
        $over = bill_overdue($s, $today);
        if ($s['access'] !== 'closed') { if ($over) $L['close'][] = $s + ['_over' => $over]; continue; }
        if (!$over) { $L['open'][] = $s; continue; }
        if ($s['vm'] === 'on' && bill_days(substr((string)$s['access_at'], 0, 10), $today) >= BILL_VM_OFF_DAYS) $L['vm_off'][] = $s + ['_over' => $over];
        if ($s['vm'] === 'off' && ($pd = bill_purge_date($s)) && $pd <= $today && !$s['purged_at']) $L['purge'][] = $s + ['_over' => $over];
    }
    return $L;
}

/* ---------- ежедневный прогон (app/cli-lk-daily.php) ---------- */
function bill_run(?string $today = null, bool $dry = false): array {
    $today ??= bill_today(); $log = [];
    $ym = substr($today, 0, 7);
    $target = $today >= bill_issue_day($ym) ? bill_next_ym($ym) : null;
    foreach (bill_servers() as $s) {
        $name = ($s['cname'] ?: $s['email']) . ' — ' . ($s['title'] ?: 'сервер');
        // 1) счёт на следующий месяц
        if ($target && !$s['purged_at'] && !bill_invoice_for((int)$s['id'], $target) && (!$s['started'] || $s['started'] <= bill_month_end($target))) {
            $log[] = "счёт за " . bill_month_name($target) . ": $name";
            if (!$dry) {
                try { $inv = bill_issue($s, $target, null, 'cron', $today); $log[count($log) - 1] .= " → {$inv['number']}"; }
                catch (RuntimeException $e) { $log[count($log) - 1] .= ' — ОШИБКА: ' . $e->getMessage(); if (bill_once("err:$target:" . $s['id'], $dry)) lk_notify_manager('Счёт не выставлен', "$name: " . $e->getMessage()); }
            }
        }
        // 2) напоминания по неоплаченным счетам
        [$email, $uname] = [$s['email'], $s['uname']];
        foreach (bill_open_invoices((int)$s['id']) as $i) {
            $due = bill_eff_due($i, $s); $left = money_rub((float)$i['amount'] - (float)$i['paid_amount']);
            $hello = "Здравствуйте" . ($uname ? ", $uname" : '') . "!\n\n";
            $tail = "\n\nСчёт: " . LK_SITE . '/lk/orders/?n=' . urlencode($i['order_number']) . "\nООО «ИНФОТЕХ», 8 (812) 928-68-84";
            if ($today >= $i['period'] . '-01' && $today < $due && bill_once("inv:{$i['id']}:start", $dry)) {
                $log[] = "напоминание (начало периода): {$i['number']} → $email";
                if (!$dry && $email) send_mail($email, "Напоминание: счёт № {$i['number']} не оплачен",
                    $hello . "Начался оплаченный по счёту № {$i['number']} период (" . bill_month_name($i['period']) . "), оплата пока не поступила.\nК оплате: $left. Срок — до " . lk_date($due) . " включительно, после этого доступ к серверу будет приостановлен." . $tail);
            }
            if ($today >= wc_prev_workday($due) && $today <= $due && bill_once("inv:{$i['id']}:pre:$due", $dry)) {
                $log[] = "предупреждение (срок " . lk_date($due) . "): {$i['number']} → $email";
                if (!$dry) {
                    if ($email) send_mail($email, "Последний день оплаты счёта № {$i['number']} — " . lk_date($due),
                        $hello . "Счёт № {$i['number']} на $left нужно оплатить до " . lk_date($due) . " включительно. Со следующего рабочего дня доступ к серверу «{$s['title']}» будет приостановлен.\n"
                        . "Если платёж уже отправлен, пришлите платёжное поручение в кабинете — так мы не закроем доступ по ошибке." . $tail);
                    lk_notify_manager("Срок оплаты " . lk_date($due) . ": $name", "Счёт {$i['number']} на $left не оплачен. Стоит позвонить клиенту ({$email}).");
                }
            }
        }
    }
    // 3) задачи менеджеру
    $L = bill_lists($today);
    foreach ($L['close'] as $s) {
        $i = $s['_over'][0];
        if (!bill_once("close:{$i['id']}:" . bill_eff_due($i, $s), $dry)) continue;
        $log[] = "к закрытию доступа: {$s['title']} ({$s['email']}), счёт {$i['number']}";
        if ($dry) continue;
        if (mt_configured() && $s['vpn_login'] && !empty(cfg('mikrotik')['auto'])) {
            try { $log[] = '  ' . bill_set_access($s, false, 'авто'); continue; } catch (RuntimeException $e) { $log[] = '  ОШИБКА: ' . $e->getMessage(); }
        }
        lk_notify_manager("Закрыть доступ: {$s['title']}", "Счёт {$i['number']} не оплачен, срок истёк " . lk_date(bill_eff_due($i, $s)) . ".\nКлиент: {$s['email']}. Долг: " . money_rub(bill_debt((int)$s['id'])) . "\nСписок «К отключению»: " . LK_SITE . '/lk/admin/?billing=1');
    }
    foreach ($L['vm_off'] as $s) if (bill_once("vmoff:{$s['id']}:{$s['access_at']}", $dry)) {
        $log[] = "к выключению ВМ: {$s['title']} ({$s['email']})";
        if (!$dry) lk_notify_manager("Выключить ВМ: {$s['title']}", "Доступ закрыт " . lk_date($s['access_at']) . ", оплаты нет " . BILL_VM_OFF_DAYS . " дней. Выключите ВМ штатно и отметьте в CRM: " . LK_SITE . '/lk/admin/?billing=1');
    }
    foreach (bill_servers() as $s) {
        if ($s['vm'] !== 'off' || $s['purged_at'] || !($pd = bill_purge_date($s)) || !bill_overdue($s, $today)) continue;
        $d = bill_days($today, $pd);
        foreach ([7, 1] as $t) if ($d <= $t && $d > 0 && bill_once("purgewarn:{$s['id']}:$pd:$t", $dry)) {
            $log[] = "предупреждение об удалении за $t дн.: {$s['title']} → {$s['email']}";
            if (!$dry && $s['email']) send_mail($s['email'], "Данные сервера «{$s['title']}» будут удалены " . lk_date($pd),
                "Здравствуйте" . ($s['uname'] ? ", {$s['uname']}" : '') . "!\n\nОплата за сервер «{$s['title']}» так и не поступила. " . lk_date($pd) . " данные и резервные копии будут удалены без возможности восстановления.\n"
                . "Задолженность: " . money_rub(bill_debt((int)$s['id'])) . ". Чтобы сохранить данные, оплатите счёт или свяжитесь с нами: 8 (812) 928-68-84.\n\n" . LK_SITE . "/lk/services/\nООО «ИНФОТЕХ»");
            break;
        }
    }
    foreach ($L['purge'] as $s) if (bill_once("purge:{$s['id']}:{$s['vm_at']}", $dry)) {
        $log[] = "к удалению данных: {$s['title']} ({$s['email']})";
        if (!$dry) lk_notify_manager("Срок хранения истёк: {$s['title']}", "ВМ выключена " . lk_date($s['vm_at']) . ", оплаты нет. Данные можно удалить — только вручную, после проверки: " . LK_SITE . '/lk/admin/?billing=1');
    }
    return $log;
}

/* ---------- отчёты ---------- */
function bill_report_debts(?string $today = null): array {
    $today ??= bill_today(); $rows = [];
    foreach (bill_servers() as $s) {
        $open = bill_open_invoices((int)$s['id']);
        if (!$open) continue;
        $debt = 0.0; $over = 0;
        foreach ($open as $i) { $debt += (float)$i['amount'] - (float)$i['paid_amount']; if (bill_eff_due($i, $s) < $today) $over = max($over, bill_days(bill_eff_due($i, $s), $today)); }
        $rows[] = ['s' => $s, 'debt' => round($debt, 2), 'count' => count($open), 'overdue_days' => $over, 'first' => $open[0]];
    }
    usort($rows, fn($a, $b) => [$b['overdue_days'], $b['debt']] <=> [$a['overdue_days'], $a['debt']]);
    return $rows;
}
/* выручка по месяцам: начислено (счета по периоду) и получено (разнесённые поступления по дате платежа) */
function bill_report_revenue(int $months = 12, ?string $today = null): array {
    $today ??= bill_today(); $pdo = bill_db(); $out = [];
    $from = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -' . ($months - 1) . ' month'));
    for ($ym = $from, $n = 0; $n < $months + 1; $ym = bill_next_ym($ym), $n++) $out[$ym] = ['billed' => 0.0, 'received' => 0.0];
    foreach ($pdo->query("SELECT period, SUM(amount) s FROM invoices WHERE annulled=0 AND period>='$from' GROUP BY period") as $r) if (isset($out[$r['period']])) $out[$r['period']]['billed'] = (float)$r['s'];
    foreach ($pdo->query("SELECT substr(payment_date,1,7) ym, SUM(amount) s FROM bank_transactions WHERE match_status='matched' AND payment_date>='$from-01' GROUP BY ym") as $r) if (isset($out[$r['ym']])) $out[$r['ym']]['received'] = (float)$r['s'];
    return $out;
}

/* ---------- сумма прописью ---------- */
function bill_words(float $sum): string {
    $rub = (int)floor($sum + 0.0001); $kop = (int)round(($sum - $rub) * 100);
    $o = [['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'], ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять']];
    $teen = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
    $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
    $hund = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
    $units = [['рубль', 'рубля', 'рублей', 0], ['тысяча', 'тысячи', 'тысяч', 1], ['миллион', 'миллиона', 'миллионов', 0], ['миллиард', 'миллиарда', 'миллиардов', 0]];
    $form = fn(int $n, array $f) => $n % 100 >= 11 && $n % 100 <= 19 ? $f[2] : ([1 => $f[0], 2 => $f[1], 3 => $f[1], 4 => $f[1]][$n % 10] ?? $f[2]);
    $parts = []; $n = $rub;
    if ($n === 0) $parts[] = 'ноль';
    for ($i = 0; $n > 0 && $i < 4; $i++, $n = intdiv($n, 1000)) {
        $t = $n % 1000; if (!$t) continue;
        $w = [$hund[intdiv($t, 100)]];
        $d = $t % 100;
        if ($d >= 10 && $d < 20) $w[] = $teen[$d - 10]; else { $w[] = $tens[intdiv($d, 10)]; $w[] = $o[$units[$i][3]][$d % 10]; }
        if ($i > 0) $w[] = $form($t, $units[$i]);
        array_unshift($parts, implode(' ', array_filter($w)));
    }
    $s = trim(implode(' ', $parts)) . ' ' . $form($rub, $units[0]) . ' ' . str_pad((string)$kop, 2, '0', STR_PAD_LEFT) . ' ' . $form($kop, ['копейка', 'копейки', 'копеек']);
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

/* ---------- PDF счёта ---------- */
function bill_pdf_store(array $inv, array $o, array $s, ?array $c, array $u): array {
    require_once APP_DIR . '/vendor/tfpdf/font/unifont/ttfonts.php';
    require_once APP_DIR . '/vendor/tfpdf/tfpdf.php';
    $b = bill_bank();
    $pdf = new tFPDF('P', 'mm', 'A4');
    $pdf->SetTitle('Счёт ' . $inv['number'], true); $pdf->SetAuthor('ООО «ИНФОТЕХ»', true);
    $pdf->AddFont('Inter', '', 'Inter-Regular.ttf', true); $pdf->AddFont('Inter', 'B', 'Inter-SemiBold.ttf', true);
    $pdf->SetMargins(15, 12, 15); $pdf->SetAutoPageBreak(true, 15); $pdf->AddPage();
    $pdf->SetDrawColor(0, 0, 0); $pdf->SetTextColor(0, 0, 0); $pdf->SetLineWidth(0.2);
    $pdf->Image(APP_DIR . '/vendor/kp-logo-light.jpg', 15, 10, 48);
    $pdf->SetFont('Inter', '', 7.5); $pdf->SetXY(80, 12);
    $pdf->MultiCell(115, 3.8, "Чтобы оплата зачлась автоматически, укажите в назначении платежа:\n«Оплата по счёту № {$inv['number']}»", 0, 'R');
    // банковский блок
    $y = 28; $pdf->SetFont('Inter', '', 8.5);
    $pdf->Rect(15, $y, 180, 28);
    $pdf->Line(110, $y, 110, $y + 28); $pdf->Line(15, $y + 12, 195, $y + 12); $pdf->Line(110, $y + 6, 195, $y + 6); $pdf->Line(125, $y, 125, $y + 28);
    $pdf->Line(15, $y + 18, 110, $y + 18); $pdf->Line(62, $y + 12, 62, $y + 18); $pdf->Line(110, $y + 18, 195, $y + 18);
    $pdf->SetXY(16, $y + 1); $pdf->MultiCell(93, 4, (string)$b['name']);
    $pdf->SetXY(16, $y + 8); $pdf->Cell(93, 4, 'Банк получателя');
    $pdf->SetXY(111, $y + 1); $pdf->Cell(14, 4, 'БИК'); $pdf->Cell(69, 4, (string)$b['bik']);
    $pdf->SetXY(111, $y + 7); $pdf->Cell(14, 4, 'Сч. №'); $pdf->Cell(69, 4, (string)$b['corr']);
    $pdf->SetXY(16, $y + 13); $pdf->Cell(46, 4, 'ИНН 7811653356'); $pdf->Cell(47, 4, 'КПП 470601001');
    $pdf->SetXY(111, $y + 13); $pdf->Cell(14, 4, 'Сч. №'); $pdf->Cell(69, 4, (string)$b['account']);
    $pdf->SetXY(16, $y + 19); $pdf->Cell(93, 4, 'ООО «ИНФОТЕХ»'); $pdf->SetXY(16, $y + 23.5); $pdf->Cell(93, 4, 'Получатель');
    // заголовок
    $pdf->SetXY(15, $y + 34); $pdf->SetFont('Inter', 'B', 14);
    $pdf->Cell(0, 7, "Счёт на оплату № {$inv['number']} от " . bill_date_words($inv['issued']), 0, 1);
    $pdf->SetLineWidth(0.6); $pdf->Line(15, $pdf->GetY() + 1, 195, $pdf->GetY() + 1); $pdf->SetLineWidth(0.2); $pdf->Ln(4);
    $row = function (string $k, string $v) use ($pdf) { $pdf->SetFont('Inter', '', 9); $pdf->Cell(28, 5, $k); $pdf->SetFont('Inter', 'B', 9); $pdf->MultiCell(152, 5, $v); $pdf->Ln(1.5); };
    $addr = trim((string)cfg('company_address'));
    $row('Поставщик:', 'ООО «ИНФОТЕХ», ИНН 7811653356, КПП 470601001' . ($addr ? ", $addr" : '') . ', тел. 8 (812) 928-68-84');
    $buyer = $c ? trim(($c['name'] ?: '') . ($c['inn'] ? ', ИНН ' . $c['inn'] : '') . ($c['kpp'] ? ', КПП ' . $c['kpp'] : '') . ($c['address'] ? ', ' . $c['address'] : ''), ', ')
                : trim((string)($u['name'] ?: $u['email']));
    $row('Покупатель:', $buyer);
    if ($s['contract']) $row('Основание:', (string)$s['contract']);
    // таблица
    $pdf->Ln(1); $w = [10, 104, 16, 12, 19, 19];
    $pdf->SetFont('Inter', 'B', 8.5);
    foreach (['№', 'Товары (работы, услуги)', 'Кол-во', 'Ед.', 'Цена', 'Сумма'] as $i => $t) $pdf->Cell($w[$i], 7, $t, 1, 0, 'C');
    $pdf->Ln(); $pdf->SetFont('Inter', '', 8.5);
    $items = lk_items($o); $total = 0.0;
    foreach ($items as $k => $it) {
        $total += (float)$it['sum'];
        $x = $pdf->GetX(); $yy = $pdf->GetY();
        $pdf->SetXY($x + $w[0], $yy); $pdf->MultiCell($w[1], 4.5, (string)$it['name'], 0, 'L');
        $h = max(7, $pdf->GetY() - $yy + 1);
        $pdf->SetXY($x, $yy); $pdf->Cell($w[0], $h, (string)($k + 1), 1, 0, 'C'); $pdf->Rect($x + $w[0], $yy, $w[1], $h);
        $pdf->SetXY($x + $w[0] + $w[1], $yy);
        $pdf->Cell($w[2], $h, (string)$it['qty'], 1, 0, 'C'); $pdf->Cell($w[3], $h, 'мес.', 1, 0, 'C');
        $pdf->Cell($w[4], $h, number_format((float)$it['price'], 2, ',', ' '), 1, 0, 'R'); $pdf->Cell($w[5], $h, number_format((float)$it['sum'], 2, ',', ' '), 1, 1, 'R');
    }
    $pdf->Ln(1); $pdf->SetFont('Inter', 'B', 9);
    $pdf->Cell(161, 5.5, 'Итого:', 0, 0, 'R'); $pdf->Cell(19, 5.5, number_format($total, 2, ',', ' '), 0, 1, 'R');
    $pdf->Cell(161, 5.5, 'Без НДС (УСН)', 0, 0, 'R'); $pdf->Cell(19, 5.5, '—', 0, 1, 'R');
    $pdf->Cell(161, 5.5, 'Всего к оплате:', 0, 0, 'R'); $pdf->Cell(19, 5.5, number_format($total, 2, ',', ' '), 0, 1, 'R');
    $pdf->Ln(2); $pdf->SetFont('Inter', '', 9);
    $pdf->Cell(0, 5, 'Всего наименований ' . count($items) . ', на сумму ' . number_format($total, 2, ',', ' ') . ' руб.', 0, 1);
    $pdf->SetFont('Inter', 'B', 9); $pdf->MultiCell(0, 5, bill_words($total)); $pdf->Ln(2);
    $pdf->SetFont('Inter', '', 8.5);
    $pdf->MultiCell(0, 4.5, 'Оплатить не позднее ' . lk_date($inv['due']) . '. При неоплате в срок доступ к серверу приостанавливается до поступления оплаты; '
        . 'стоимость за период приостановки не пересчитывается. НДС не облагается: ООО «ИНФОТЕХ» применяет упрощённую систему налогообложения.');
    $pdf->SetLineWidth(0.6); $pdf->Line(15, $pdf->GetY() + 3, 195, $pdf->GetY() + 3); $pdf->SetLineWidth(0.2); $pdf->Ln(10);
    $dir = trim((string)cfg('director_name'));
    $pdf->SetFont('Inter', 'B', 9); $pdf->Cell(30, 5, 'Руководитель'); $pdf->SetFont('Inter', '', 9);
    $pdf->Cell(60, 5, '', 'B'); $pdf->Cell(5, 5, ''); $pdf->Cell(60, 5, $dir, 'B', 1, 'C');
    $dirpath = LK_FILES . '/' . $o['id'];
    if (!is_dir($dirpath)) mkdir($dirpath, 0750, true);
    $name = 'invoice-' . preg_replace('/\D/', '', $inv['number']) . '-' . bin2hex(random_bytes(3)) . '.pdf';
    $pdf->Output('F', "$dirpath/$name");
    return ['file' => $o['id'] . '/' . $name, 'mime' => 'application/pdf', 'size' => (int)filesize("$dirpath/$name")];
}
