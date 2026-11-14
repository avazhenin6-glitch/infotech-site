<?php
/* Оплаты из СберБизнеса: журнал поступлений, распознавание (номер заказа/счёта → ИНН → сумма), частичная оплата, неразнесённые платежи.
   Источники: выписка 1CClientBankExchange (загрузка менеджером), вебхук из 1С (/api/v1/payments/callback), ручная привязка. */
declare(strict_types=1);

const OUR_INN = '7811653356';
const PAY_OPEN = ['Новый', 'Счёт выставлен', 'Платёж на проверке', 'Частично оплачен', 'Срок резерва истёк'];

function pay_db(): PDO {
    static $done = false;
    $pdo = svc_db();
    if ($done) return $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS bank_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, uniq TEXT UNIQUE, source TEXT, order_id INTEGER, payment_order_num TEXT, payment_date TEXT,
        payer_inn TEXT, payer_name TEXT, amount REAL, raw_purpose TEXT, match_status TEXT, match_note TEXT, created_at TEXT, matched_at TEXT, matched_by TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS bt_order ON bank_transactions(order_id)");
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    foreach (['paid_amount' => 'REAL DEFAULT 0', 'invoice_amount' => 'REAL', 'invoice_number' => 'TEXT', 'invoice_date' => 'TEXT'] as $c => $t)
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN $c $t");
    $done = true;
    return $pdo;
}
/* сумма к оплате: сумма счёта из 1С, если указана, иначе итог заказа по прайсу */
function pay_due(array $o): float { return (float)($o['invoice_amount'] ?: $o['total_rub']); }
function pay_history(int $orderId): array {
    $st = pay_db()->prepare("SELECT * FROM bank_transactions WHERE order_id=? AND match_status='matched' ORDER BY payment_date, id"); $st->execute([$orderId]);
    return $st->fetchAll();
}
function pay_norm_date(string $d): string {   // 14.11.2026 / 2026-11-14 → 2026-11-14
    if (preg_match('/^(\d\d)\.(\d\d)\.(\d{4})/', $d, $m)) return "$m[3]-$m[2]-$m[1]";
    return substr($d, 0, 10);
}

/* поиск заказа по назначению платежа: номер заказа сайта (ИТ-261114-12) или номер счёта 1С («счёт № 412 от 14.11.2026») */
function pay_find_by_purpose(string $purpose): array {
    $pdo = pay_db(); $found = []; $how = [];
    if (preg_match_all('/(?:ИТ|IT|ИT)\s*-?\s*(\d{6})\s*-\s*(\d{1,7})/ui', $purpose, $mm, PREG_SET_ORDER))
        foreach ($mm as $m) {
            $st = $pdo->prepare("SELECT * FROM orders WHERE type='order' AND number=?"); $st->execute(["ИТ-$m[1]-$m[2]"]);
            if ($o = $st->fetch()) { $found[$o['id']] = $o; $how[$o['id']] = 'номер заказа'; }
        }
    if (preg_match_all('/(?:ИТ|IT|ИT)\s*-\s*(\d{6})(?!\d|\s*-\s*\d)/ui', $purpose, $mm, PREG_SET_ORDER))   // счёт CRM: ИТ-000145
        foreach ($mm as $m) {
            $st = $pdo->prepare("SELECT * FROM orders WHERE type='order' AND invoice_number=?"); $st->execute(["ИТ-$m[1]"]);
            foreach ($st->fetchAll() as $o) { $found[$o['id']] = $o; $how[$o['id']] = 'номер счёта'; }
        }
    if (preg_match_all('/сч[её]т[ауе]?\s*(?:на\s+оплату\s*)?(?:№|N|No|#)?\s*([A-ZА-Я]{0,4}-?\d{1,10}(?:\/\d+)?)(?:\s*от\s*(\d\d\.\d\d\.\d{2,4}))?/ui', $purpose, $mm, PREG_SET_ORDER))
        foreach ($mm as $m) {
            $num = ltrim($m[1], '0') ?: $m[1];
            $sql = "SELECT * FROM orders WHERE type='order' AND (invoice_number=? OR ltrim(invoice_number,'0')=?)"; $args = [$m[1], $num];
            if (!empty($m[2])) { $d = pay_norm_date(strlen($m[2]) === 8 ? substr($m[2], 0, 6) . '20' . substr($m[2], 6) : $m[2]); $sql .= ' AND (invoice_date IS NULL OR invoice_date=?)'; $args[] = $d; }
            $st = $pdo->prepare($sql . ' ORDER BY id DESC'); $st->execute($args);
            foreach ($st->fetchAll() as $o) { $found[$o['id']] = $o; $how[$o['id']] ??= 'номер счёта'; }
        }
    return [$found, $how];
}

/* регистрация поступления: возвращает ['status'=>matched|unmatched|duplicate|ignored, 'order'=>..., 'note'=>...] */
function pay_register(array $t, string $source): array {
    $pdo = pay_db();
    $t['payment_date'] = pay_norm_date((string)($t['payment_date'] ?? date('Y-m-d')));
    $t['amount'] = round((float)$t['amount'], 2);
    $t['payer_inn'] = preg_replace('/\D/', '', (string)($t['payer_inn'] ?? ''));
    if ($t['amount'] <= 0) return ['status' => 'ignored', 'note' => 'нулевая сумма'];
    $uniq = hash('sha256', implode('|', [$t['payment_order_num'] ?? '', $t['payment_date'], $t['payer_inn'], number_format($t['amount'], 2, '.', '')]));
    $st = $pdo->prepare('SELECT * FROM bank_transactions WHERE uniq=?'); $st->execute([$uniq]);
    if ($ex = $st->fetch()) return ['status' => 'duplicate', 'id' => (int)$ex['id'], 'order_id' => $ex['order_id']];
    $pdo->prepare('INSERT INTO bank_transactions (uniq, source, payment_order_num, payment_date, payer_inn, payer_name, amount, raw_purpose, match_status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uniq, $source, (string)($t['payment_order_num'] ?? ''), $t['payment_date'], $t['payer_inn'], mb_substr((string)($t['payer_name'] ?? ''), 0, 250),
                   $t['amount'], mb_substr((string)($t['purpose'] ?? ''), 0, 1000), 'unmatched', date('Y-m-d H:i:s')]);
    $tid = (int)$pdo->lastInsertId();

    // 1С может прислать номер заказа явно
    $cands = []; $how = [];
    if (!empty($t['order'])) {
        $st = $pdo->prepare("SELECT * FROM orders WHERE type='order' AND number=?"); $st->execute([(string)$t['order']]);
        if ($o = $st->fetch()) { $cands[$o['id']] = $o; $how[$o['id']] = 'номер заказа от 1С'; }
    }
    if (!$cands && !empty($t['invoice_number'])) {
        $st = $pdo->prepare("SELECT * FROM orders WHERE type='order' AND (invoice_number=? OR ltrim(invoice_number,'0')=?)"); $st->execute([(string)$t['invoice_number'], ltrim((string)$t['invoice_number'], '0')]);
        foreach ($st->fetchAll() as $o) { $cands[$o['id']] = $o; $how[$o['id']] = 'номер счёта от 1С'; }
    }
    if (!$cands) [$cands, $how] = pay_find_by_purpose((string)($t['purpose'] ?? ''));
    $cands = array_filter($cands, fn($o) => in_array($o['status'], PAY_OPEN, true));
    if (count($cands) !== 1) {
        $note = $cands ? 'несколько подходящих заказов' : (pay_suggest($t['payer_inn']) ? 'номер не найден, ИНН плательщика есть среди клиентов' : 'заказ не найден');
        $pdo->prepare('UPDATE bank_transactions SET match_note=? WHERE id=?')->execute([$note, $tid]);
        return ['status' => 'unmatched', 'id' => $tid, 'note' => $note];
    }
    $o = reset($cands);
    // ИНН: если в заказе (или его организации) указан ИНН — он должен совпасть с плательщиком
    $inns = array_filter([preg_replace('/\D/', '', (string)$o['inn'])]);
    if ($o['company_id']) { $c = $pdo->query('SELECT inn FROM user_companies WHERE id=' . (int)$o['company_id'])->fetchColumn(); if ($c) $inns[] = $c; }
    if ($inns && $t['payer_inn'] !== '' && !in_array($t['payer_inn'], $inns, true)) {
        $pdo->prepare('UPDATE bank_transactions SET match_note=? WHERE id=?')->execute(["ИНН плательщика {$t['payer_inn']} не совпадает с заказом {$o['number']}", $tid]);
        return ['status' => 'unmatched', 'id' => $tid, 'note' => 'ИНН не совпадает'];
    }
    $r = pay_apply($tid, (int)$o['id'], $source, $how[$o['id']] ?? '');
    return ['status' => 'matched', 'id' => $tid, 'order' => $o['number'], 'order_status' => $r];
}

/* привязка поступления к заказу и пересчёт статуса */
function pay_apply(int $tid, int $orderId, string $by, string $how = ''): string {
    $pdo = pay_db();
    $st = $pdo->prepare('SELECT * FROM orders WHERE id=?'); $st->execute([$orderId]); $o = $st->fetch();
    $st = $pdo->prepare('SELECT * FROM bank_transactions WHERE id=?'); $st->execute([$tid]); $t = $st->fetch();
    if (!$o || !$t) return '';
    $pdo->prepare("UPDATE bank_transactions SET order_id=?, match_status='matched', match_note=?, matched_at=?, matched_by=? WHERE id=?")
        ->execute([$orderId, $how ? "по: $how" : '', date('Y-m-d H:i:s'), $by, $tid]);
    $paid = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE order_id=$orderId AND match_status='matched'")->fetchColumn();
    $due = pay_due($o);
    $full = $paid + 0.009 >= $due;
    $new = $full ? 'Оплачен' : 'Частично оплачен';
    $was = $o['status'];
    $pdo->prepare('UPDATE orders SET paid_amount=?, status=?, valid_until=CASE WHEN ? THEN NULL ELSE valid_until END, updated_at=? WHERE id=?')
        ->execute([$paid, in_array($was, ['В работе', 'Выполнен'], true) ? $was : $new, $full ? 1 : 0, date('Y-m-d H:i:s'), $orderId]);
    if ($full) $pdo->prepare("UPDATE documents SET blocked=0 WHERE order_id=? AND kind='invoice' AND id=(SELECT MAX(id) FROM documents WHERE order_id=? AND kind='invoice')")->execute([$orderId, $orderId]);
    $ext = '';
    if ($full && $was !== 'Оплачен' && !empty($o['service_id']) && ($until = svc_apply_payment($o))) $ext = "\nУслуга продлена, оплачено до " . lk_date($until) . '.';
    if ($o['email'] && $was !== $new) {
        $pp = 'п/п № ' . $t['payment_order_num'] . ' от ' . lk_date($t['payment_date']);
        $msg = $full ? "Оплата по заказу № {$o['number']} получена ($pp, " . money_rub((float)$t['amount']) . '). Спасибо!' . $ext
                       . "\nЭлектронные лицензии и пин-коды, если они есть в заказе, станут доступны в кабинете, как только менеджер их добавит."
                     : "Поступила частичная оплата по заказу № {$o['number']}: " . money_rub((float)$t['amount']) . " ($pp). Остаток к оплате: " . money_rub(max(0, $due - $paid)) . '.';
        send_mail($o['email'], ($full ? 'Оплата получена' : 'Частичная оплата') . " — заказ {$o['number']}",
            "Здравствуйте" . ($o['name'] ? ", {$o['name']}" : '') . "!\n\n$msg\n\nЗаказ: " . LK_SITE . '/lk/orders/?n=' . urlencode($o['number']) . "\n\nООО «ИНФОТЕХ», 8 (812) 928-68-84");
    }
    if ($full && $was !== 'Оплачен') send_telegram("Оплата получена: заказ {$o['number']}, " . money_rub($paid));
    return $new;
}
/* открытые заказы клиентов с таким ИНН — подсказки менеджеру */
function pay_suggest(string $inn): array {
    if ($inn === '') return [];
    $ph = implode(',', array_fill(0, count(PAY_OPEN), '?'));
    $st = pay_db()->prepare("SELECT o.* FROM orders o LEFT JOIN user_companies c ON c.id=o.company_id WHERE o.type='order' AND o.status IN ($ph) AND (o.inn=? OR c.inn=?) ORDER BY o.id DESC LIMIT 10");
    $st->execute([...PAY_OPEN, $inn, $inn]);
    return $st->fetchAll();
}

/* ---------------- выписка 1CClientBankExchange (СберБизнес → «Экспорт в 1С») ---------------- */
function pay_parse_statement(string $raw): array {
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!str_starts_with(trim($raw), '1CClientBankExchange')) throw new RuntimeException('Это не файл выписки в формате 1С (1CClientBankExchange)');
    $docs = []; $cur = null;
    foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'СекцияДокумент')) { $cur = ['_type' => trim(explode('=', $line, 2)[1] ?? '')]; continue; }
        if ($line === 'КонецДокумента') { if ($cur) $docs[] = $cur; $cur = null; continue; }
        if ($cur !== null && str_contains($line, '=')) { [$k, $v] = explode('=', $line, 2); $cur[$k] ??= $v; }
    }
    $in = [];
    foreach ($docs as $d) {
        if (preg_replace('/\D/', '', $d['ПолучательИНН'] ?? '') !== OUR_INN) continue;   // только поступления на счёт ИНФОТЕХ
        $in[] = ['payment_order_num' => $d['Номер'] ?? '', 'payment_date' => $d['ДатаПоступило'] ?? ($d['Дата'] ?? ''), 'amount' => (float)str_replace(',', '.', $d['Сумма'] ?? '0'),
                 'payer_inn' => $d['ПлательщикИНН'] ?? '', 'payer_name' => $d['Плательщик1'] ?? ($d['Плательщик'] ?? ''), 'purpose' => $d['НазначениеПлатежа'] ?? ''];
    }
    return $in;
}
