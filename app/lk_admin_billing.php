<?php
/* Кабинет менеджера: аренда серверов — задачи дня (закрыть/открыть доступ, выключить ВМ, удалить данные), счета, долги, выручка. */
declare(strict_types=1);

const BILL_ADMIN_ACTS = ['bill_close', 'bill_open', 'bill_vm_off', 'bill_vm_on', 'bill_purge', 'bill_issue', 'bill_run', 'bill_annul'];

function adm_billing_post(): void {
    $act = (string)$_POST['act']; $by = mgr_name();
    $s = isset($_POST['sid']) ? svc_get((int)$_POST['sid']) : null;
    $back = '/lk/admin/?billing=1';
    try {
        switch ($act) {
            case 'bill_close': if (!$s) break; lk_flash('Готово: ' . bill_set_access($s, false, $by)); break;
            case 'bill_open':
                if (!$s) break;
                if ($s['vm'] === 'off') { lk_flash('ВМ выключена — сначала включите её и отметьте «ВМ включена»', 'bad'); break; }
                lk_flash('Готово: ' . bill_set_access($s, true, $by)); break;
            case 'bill_vm_off': if (!$s) break; bill_set_vm($s, false, $by); lk_flash('Отмечено: ВМ выключена. Клиенту отправлено письмо с датой удаления данных.'); break;
            case 'bill_vm_on': if (!$s) break; bill_set_vm($s, true, $by); lk_flash('Отмечено: ВМ включена'); break;
            case 'bill_purge':
                if (!$s) break;
                $who = $s['company_id'] ? (string)bill_db()->query('SELECT name FROM user_companies WHERE id=' . (int)$s['company_id'])->fetchColumn() : '';
                $need = $s['vpn_login'] ?: ($s['title'] ?: 'удалить');
                if (trim((string)($_POST['confirm'] ?? '')) !== $need) { lk_flash("Для подтверждения введите «{$need}» — данные удаляются безвозвратно", 'bad'); break; }
                if (!($pd = bill_purge_date($s)) || $pd > bill_today()) { lk_flash('Срок хранения данных ещё не истёк', 'bad'); break; }
                bill_mark_purged($s, $by); lk_flash('Отмечено: данные удалены, услуга закрыта' . ($who ? " ($who)" : '')); break;
            case 'bill_issue':
                if (!$s || $s['kind'] !== 'server') break;
                $ym = preg_match('/^\d{4}-\d\d$/', (string)($_POST['period'] ?? '')) ? $_POST['period'] : date('Y-m');
                $from = preg_match('/^\d{4}-\d\d-\d\d$/', (string)($_POST['from'] ?? '')) ? $_POST['from'] : null;
                $inv = bill_issue($s, $ym, $from, $by);
                lk_flash("Счёт {$inv['number']} на " . money_rub((float)$inv['amount']) . ' выставлен и отправлен клиенту');
                $back = '/lk/admin/?svc=' . $s['id']; break;
            case 'bill_annul':
                $st = bill_db()->prepare('SELECT * FROM invoices WHERE id=?'); $st->execute([(int)$_POST['iid']]);
                $inv = $st->fetch();
                if (!$inv) break;
                $o = bill_db()->query('SELECT * FROM orders WHERE id=' . (int)$inv['order_id'])->fetch();
                if ((float)$o['paid_amount'] > 0) { lk_flash('По счёту уже есть оплата — аннулировать нельзя', 'bad'); break; }
                bill_db()->prepare('UPDATE invoices SET annulled=1 WHERE id=?')->execute([$inv['id']]);
                bill_db()->prepare("UPDATE orders SET status='Отменён', updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $inv['order_id']]);
                bill_db()->prepare("UPDATE documents SET blocked=1 WHERE order_id=? AND kind='invoice'")->execute([$inv['order_id']]);
                bill_log((int)$inv['service_id'], 'annul', "счёт {$inv['number']} аннулирован", $by);
                lk_flash("Счёт {$inv['number']} аннулирован. Номер не переиспользуется."); $back = '/lk/admin/?svc=' . $inv['service_id']; break;
            case 'bill_run':
                $log = bill_run();
                lk_flash($log ? 'Выполнено: ' . implode('; ', $log) : 'Сегодня действий по аренде нет'); break;
        }
    } catch (RuntimeException $e) { lk_flash($e->getMessage(), 'bad'); }
    lk_redirect($back);
}

function adm_billing_svc_card(array $s): string {   // блок в карточке услуги (?svc=)
    if ($s['kind'] !== 'server') return '';
    $f = lk_csrf_field() . '<input type="hidden" name="sid" value="' . $s['id'] . '">';
    $rows = '';
    foreach (bill_invoices((int)$s['id']) as $i)
        $rows .= '<tr><td>' . h($i['number']) . '</td><td>' . lk_date($i['issued']) . '</td><td>' . h(bill_month_name($i['period'])) . '</td><td>' . money_rub((float)$i['amount']) . '</td><td>до ' . lk_date($i['due']) . '</td>'
            . '<td>' . ($i['annulled'] ? '<span class="badge bad">аннулирован</span>' : '<a href="?n=' . urlencode($i['order_number']) . '">' . lk_badge($i['order_status']) . '</a>') . '</td>'
            . '<td>' . (!$i['annulled'] && !(float)$i['paid_amount'] && !in_array($i['order_status'], BILL_PAID, true) ? '<form method="post" class="inline" onsubmit="return confirm(\'Аннулировать счёт ' . h($i['number']) . '?\')">' . lk_csrf_field() . '<input type="hidden" name="iid" value="' . $i['id'] . '"><button class="link" name="act" value="bill_annul">аннулировать</button></form>' : '') . '</td></tr>';
    $ym = date('Y-m');
    $cur = bill_invoice_for((int)$s['id'], $ym);
    $from = $s['started'] && substr($s['started'], 0, 7) === $ym ? $s['started'] : date('Y-m-d');
    $form = '<form method="post" class="filters" style="margin-top:14px">' . $f . '<input type="hidden" name="act" value="bill_issue">'
        . '<label>Период<input type="month" name="period" value="' . ($cur ? bill_next_ym($ym) : $ym) . '"></label>'
        . '<label>С даты (неполный месяц)<input type="date" name="from" value="' . ($cur ? '' : $from) . '"></label>'
        . '<button class="btn ghost">Выставить счёт</button></form><p class="muted">Обычно ничего нажимать не нужно: счета на следующий месяц выставляются сами ' . lk_date(bill_issue_day($ym)) . '. Ручной счёт — для нового клиента с середины месяца: сумма считается пропорционально дням.</p>';
    $log = '';
    foreach (bill_db()->query('SELECT * FROM bill_log WHERE service_id=' . (int)$s['id'] . ' ORDER BY id DESC LIMIT 15') as $l)
        $log .= '<tr><td>' . h(date('d.m.Y H:i', strtotime($l['at']))) . '</td><td>' . h($l['note']) . '</td><td class="muted">' . h($l['by']) . '</td></tr>';
    $state = 'Доступ: <b>' . ($s['access'] === 'closed' ? 'закрыт с ' . lk_date($s['access_at']) : 'открыт') . '</b> · ВМ: <b>' . ($s['vm'] === 'off' ? 'выключена с ' . lk_date($s['vm_at']) : 'работает') . '</b>'
        . ' · долг: <b>' . money_rub(bill_debt((int)$s['id'])) . '</b> · VPN-учётка: <b>' . h($s['vpn_login'] ?: 'не указана') . '</b>';
    return '<div class="card cut"><h2>Счета и доступ</h2><p>' . $state . '</p>'
        . ($rows ? '<table class="tbl"><thead><tr><th>Счёт</th><th>Дата</th><th>Период</th><th>Сумма</th><th>Срок</th><th>Статус</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>' : '<p class="muted">Счетов ещё не было.</p>')
        . $form . ($log ? '<details><summary>Журнал</summary><table class="tbl"><tbody>' . $log . '</tbody></table></details>' : '') . '</div>';
}

function adm_billing_get(): void {
    if (!isset($_GET['billing'])) return;
    $L = bill_lists();
    $f = fn($s) => lk_csrf_field() . '<input type="hidden" name="sid" value="' . $s['id'] . '">';
    $who = fn($s) => '<a href="?svc=' . $s['id'] . '"><b>' . h($s['cname'] ?: $s['email']) . '</b></a><br><span class="muted">' . h($s['title']) . ($s['vpn_login'] ? ' · VPN: ' . h($s['vpn_login']) : ' · VPN-учётка не указана') . '</span>';
    $auto = mt_configured();
    $sec = function (string $title, string $hint, array $list, callable $btn) use ($who): string {
        if (!$list) return '';
        $r = '';
        foreach ($list as $s) $r .= '<tr><td>' . $who($s) . '</td><td>' . money_rub(bill_debt((int)$s['id'])) . '</td><td>' . $btn($s) . '</td></tr>';
        return '<div class="card cut"><h2>' . $title . ' · ' . count($list) . '</h2><p class="muted">' . $hint . '</p><table class="tbl"><thead><tr><th>Клиент</th><th>Долг</th><th></th></tr></thead><tbody>' . $r . '</tbody></table></div>';
    };
    $tasks = $sec('К отключению', 'Срок оплаты истёк. ' . ($auto ? 'Кнопка закроет OpenVPN-учётку на MikroTik и разорвёт подключение.' : 'Отключите OpenVPN-учётку на MikroTik вручную и нажмите кнопку — клиенту уйдёт письмо.'), $L['close'],
            fn($s) => '<form method="post" class="inline">' . $f($s) . '<button class="btn small" name="act" value="bill_close">' . ($auto ? 'Закрыть доступ' : 'Отметить: доступ закрыт') . '</button></form><br><span class="muted">счёт ' . h($s['_over'][0]['number']) . ', срок ' . lk_date(bill_eff_due($s['_over'][0], $s)) . '</span>')
        . $sec('Включить', 'Оплата поступила. ' . ($auto ? '' : 'Включите учётку на MikroTik и нажмите кнопку.'), $L['open'],
            fn($s) => $s['vm'] === 'off' ? '<form method="post" class="inline">' . $f($s) . '<button class="btn small ghost" name="act" value="bill_vm_on">ВМ включена</button></form>'
                : '<form method="post" class="inline">' . $f($s) . '<button class="btn small" name="act" value="bill_open">' . ($auto ? 'Открыть доступ' : 'Отметить: доступ открыт') . '</button></form>')
        . $sec('Выключить ВМ', 'Доступ закрыт ' . BILL_VM_OFF_DAYS . '+ дней, оплаты нет. Выключите ВМ штатно (Shutdown) на Proxmox / ESXi и отметьте.', $L['vm_off'],
            fn($s) => '<form method="post" class="inline" onsubmit="return confirm(\'ВМ выключена штатно? Клиенту уйдёт письмо с датой удаления данных.\')">' . $f($s) . '<button class="btn small" name="act" value="bill_vm_off">ВМ выключена</button></form>')
        . $sec('К удалению данных', 'ВМ выключена ' . BILL_PURGE_DAYS . '+ дней. Удаление необратимо: проверьте, что оплаты нет, удалите ВМ и бэкапы, затем подтвердите.', $L['purge'],
            fn($s) => '<form method="post" class="row" onsubmit="return confirm(\'Данные удалены безвозвратно?\')">' . $f($s) . '<input type="hidden" name="act" value="bill_purge"><input name="confirm" placeholder="введите: ' . h($s['vpn_login'] ?: ($s['title'] ?: 'удалить')) . '" required style="max-width:200px;margin:0"><button class="btn small ghost">Данные удалены</button></form>');
    // долги
    $d = '';
    foreach (bill_report_debts() as $r)
        $d .= '<tr><td>' . $who($r['s']) . '</td><td>' . $r['count'] . '</td><td><b>' . money_rub($r['debt']) . '</b></td><td>' . ($r['overdue_days'] ? '<span class="badge bad">' . $r['overdue_days'] . ' дн.</span>' : '<span class="badge wait">в срок</span>') . '</td>'
            . '<td>' . ($r['s']['access'] === 'closed' ? 'доступ закрыт' : 'работает') . '</td></tr>';
    // выручка
    $rev = ''; $max = 1;
    $R = bill_report_revenue(12);
    foreach ($R as $v) $max = max($max, $v['billed'], $v['received']);
    foreach (array_reverse($R, true) as $ym => $v)
        if ($v['billed'] || $v['received']) $rev .= '<tr><td>' . h(bill_month_name($ym)) . '</td><td>' . money_rub($v['billed']) . '<div class="paybar"><i style="width:' . round($v['billed'] / $max * 100) . '%"></i></div></td><td>' . money_rub($v['received']) . '<div class="paybar"><i style="width:' . round($v['received'] / $max * 100) . '%"></i></div></td></tr>';
    $servers = bill_servers();
    $cfgWarn = '';
    try { crm_bank(); } catch (RuntimeException $e) { $cfgWarn .= '<p class="badge bad">' . h($e->getMessage()) . '</p> '; }
    if (!$auto) $cfgWarn .= '<p class="muted">MikroTik не подключён (app/config.php → mikrotik): доступ закрывается и открывается вручную, CRM ведёт списки и письма.</p>';
    mgr_page('Аренда серверов', '<h1>Аренда серверов</h1>' . $cfgWarn
        . '<p class="muted">Серверов в аренде: ' . count($servers) . '. Счета на следующий месяц выставляются сами ' . lk_date(bill_issue_day(substr(bill_today(), 0, 7))) . ', срок оплаты — до ' . lk_date(bill_due(bill_next_ym(substr(bill_today(), 0, 7)))) . ' (5-й рабочий день).</p>'
        . ($tasks ?: '<div class="card cut"><h2>Задач на сегодня нет</h2><p class="muted">Все серверы оплачены или срок ещё не наступил.</p></div>')
        . '<div class="card cut"><h2>Долги по клиентам</h2>' . ($d ? '<table class="tbl"><thead><tr><th>Клиент</th><th>Счетов</th><th>Долг</th><th>Просрочка</th><th>Сервер</th></tr></thead><tbody>' . $d . '</tbody></table>' : '<p class="muted">Долгов нет.</p>') . '</div>'
        . '<div class="card cut"><h2>Выручка по месяцам</h2><p class="muted">Начислено — счета за месяц аренды; получено — разнесённые поступления по дате платежа (все заказы).</p><table class="tbl"><thead><tr><th>Месяц</th><th>Начислено (аренда)</th><th>Получено</th></tr></thead><tbody>' . $rev . '</tbody></table></div>'
        . '<form method="post">' . lk_csrf_field() . '<button class="btn ghost" name="act" value="bill_run">Выполнить ежедневную проверку сейчас</button></form>');
}
