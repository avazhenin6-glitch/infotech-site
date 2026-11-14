<?php
/* Кабинет менеджера: оплаты СберБизнеса — загрузка выписки 1CClientBankExchange, неразнесённые платежи, ручная привязка. */
declare(strict_types=1);

function adm_payments_post(): void {
    $pdo = pay_db(); $act = (string)($_POST['act'] ?? '');
    if ($act === 'pay_import') {
        $f = $_FILES['file'] ?? [];
        try {
            if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 20 * 1048576) throw new RuntimeException('Файл не загрузился');
            $rows = pay_parse_statement((string)file_get_contents($f['tmp_name']));
            $cnt = ['matched' => 0, 'unmatched' => 0, 'duplicate' => 0, 'ignored' => 0];
            foreach ($rows as $t) $cnt[pay_register($t, 'выписка')['status']]++;
            lk_flash('Выписка: поступлений ' . count($rows) . " — разнесено {$cnt['matched']}, требуют внимания {$cnt['unmatched']}, уже были загружены {$cnt['duplicate']}.", $cnt['unmatched'] ? 'wait' : 'ok');
        } catch (RuntimeException $e) { lk_flash($e->getMessage(), 'bad'); }
        lk_redirect('/lk/admin/?payments=1');
    }
    if ($act === 'pay_bind') {
        $tid = (int)$_POST['tid'];
        $num = trim((string)($_POST['order'] ?? ''));
        $st = $pdo->prepare("SELECT id FROM orders WHERE type='order' AND number=?"); $st->execute([$num]);
        $oid = (int)$st->fetchColumn();
        $tx = $pdo->query('SELECT * FROM bank_transactions WHERE id=' . $tid)->fetch();
        if (!$oid || !$tx || $tx['match_status'] === 'matched') lk_flash('Заказ не найден или платёж уже разнесён', 'bad');
        else { $r = pay_apply($tid, $oid, 'менеджер', 'вручную'); lk_flash("Платёж привязан к заказу $num — статус «{$r}»"); }
        lk_redirect('/lk/admin/?payments=1');
    }
    if ($act === 'pay_ignore') {
        $pdo->prepare("UPDATE bank_transactions SET match_status='ignored', match_note='не относится к заказам сайта', matched_at=?, matched_by='менеджер' WHERE id=? AND match_status='unmatched'")
            ->execute([date('Y-m-d H:i:s'), (int)$_POST['tid']]);
        lk_flash('Платёж скрыт из неразнесённых'); lk_redirect('/lk/admin/?payments=1');
    }
}

function adm_payments_get(): void {
    if (!isset($_GET['payments'])) return;
    $pdo = pay_db();
    $f = fn($tid) => lk_csrf_field() . '<input type="hidden" name="tid" value="' . $tid . '">';
    $un = '';
    foreach ($pdo->query("SELECT * FROM bank_transactions WHERE match_status='unmatched' ORDER BY payment_date DESC, id DESC") as $t) {
        $sug = '';
        foreach (pay_suggest((string)$t['payer_inn']) as $o)
            $sug .= '<form method="post" class="inline">' . $f($t['id']) . '<input type="hidden" name="act" value="pay_bind"><input type="hidden" name="order" value="' . h($o['number']) . '">'
                . '<button class="btn small' . (abs(pay_due($o) - (float)$o['paid_amount'] - (float)$t['amount']) < 0.01 ? '' : ' ghost') . '">→ ' . h($o['number']) . ' (' . money_rub(pay_due($o) - (float)$o['paid_amount']) . ')</button></form> ';
        $un .= '<tr><td>' . lk_date($t['payment_date']) . '<br><span class="muted">п/п № ' . h($t['payment_order_num']) . '</span></td><td style="white-space:nowrap"><b>' . money_rub((float)$t['amount']) . '</b></td>'
            . '<td>' . h($t['payer_name']) . '<br><span class="muted">ИНН ' . h($t['payer_inn']) . '</span></td><td>' . h($t['raw_purpose']) . '<br><span class="badge wait">' . h($t['match_note']) . '</span></td>'
            . '<td>' . ($sug ? '<div class="muted">Заказы этого ИНН:</div>' . $sug : '') . '<form method="post" class="row" style="margin-top:8px">' . $f($t['id']) . '<input type="hidden" name="act" value="pay_bind">'
            . '<input name="order" placeholder="ИТ-ггммдд-N" required style="max-width:150px;margin:0"><button class="btn small">Привязать</button></form>'
            . '<form method="post" class="inline">' . $f($t['id']) . '<button class="link" name="act" value="pay_ignore" style="color:var(--muted)">не относится к сайту</button></form></td></tr>';
    }
    $all = '';
    foreach ($pdo->query("SELECT b.*, o.number FROM bank_transactions b LEFT JOIN orders o ON o.id=b.order_id ORDER BY b.id DESC LIMIT 100") as $t)
        $all .= '<tr><td>' . lk_date($t['payment_date']) . '</td><td>№ ' . h($t['payment_order_num']) . '</td><td>' . money_rub((float)$t['amount']) . '</td><td>' . h($t['payer_inn']) . '</td>'
            . '<td>' . ($t['number'] ? '<a href="?n=' . urlencode($t['number']) . '">' . h($t['number']) . '</a>' : '—') . '</td><td>' . h($t['source']) . '</td>'
            . '<td><span class="badge ' . ['matched' => 'ok', 'unmatched' => 'wait', 'ignored' => 'bad'][$t['match_status']] . '">' . ['matched' => 'разнесён', 'unmatched' => 'не разнесён', 'ignored' => 'скрыт'][$t['match_status']] . '</span></td></tr>';
    mgr_page('Оплаты', '<h1>Оплаты из СберБизнеса</h1>'
        . '<div class="card cut"><h2>Загрузить выписку</h2><p class="muted">СберБизнес → Выписка → Экспорт → формат «1С» (.txt). Поступления на счёт ИНФОТЕХ разносятся по заказам автоматически: по номеру заказа или счёта в назначении платежа, с проверкой ИНН и суммы. Повторная загрузка той же выписки не создаёт дублей.</p>'
        . '<form method="post" enctype="multipart/form-data" class="filters">' . lk_csrf_field() . '<input type="hidden" name="act" value="pay_import"><label>Файл выписки<input type="file" name="file" accept=".txt" required></label><button class="btn">Загрузить и разнести</button></form></div>'
        . '<div class="card cut"><h2>Неразнесённые оплаты</h2>' . ($un ? '<table class="tbl"><thead><tr><th>Дата</th><th>Сумма</th><th>Плательщик</th><th>Назначение</th><th>Куда отнести</th></tr></thead><tbody>' . $un . '</tbody></table>' : '<p class="muted">Все поступления разнесены.</p>') . '</div>'
        . '<div class="card cut"><h2>Последние поступления</h2>' . ($all ? '<table class="tbl"><thead><tr><th>Дата</th><th>П/П</th><th>Сумма</th><th>ИНН</th><th>Заказ</th><th>Источник</th><th></th></tr></thead><tbody>' . $all . '</tbody></table>' : '<p class="muted">Поступлений пока нет.</p>') . '</div>');
}
