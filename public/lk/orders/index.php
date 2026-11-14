<?php
/* Мои заказы: список с фильтрами и поиском, карточка заказа с действиями */
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
$u = lk_require();
$pdo = lk_db();
$uid = (int)$u['id'];

/* ---------- карточка заказа ---------- */
if (isset($_GET['n'])) {
    $o = lk_order_for_user((string)$_GET['n'], $uid);
    if (!$o) { http_response_code(404); lk_page('Заказ не найден', 'orders', '<h1>Заказ не найден</h1><p><a href="/lk/orders/">← Все заказы</a></p>', $u); }
    $self = '/lk/orders/?n=' . urlencode($o['number']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        lk_check_csrf();
        $act = (string)($_POST['act'] ?? '');
        if ($act === 'pay' && in_array($o['status'], ['Счёт выставлен', 'Частично оплачен'], true)) {
            try {
                $stored = lk_store_upload($_FILES['file'] ?? [], (int)$o['id']);
                lk_add_document($o, 'payment', $stored, 'Платёжное поручение');
                $pdo->prepare("UPDATE orders SET status='Платёж на проверке', updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $o['id']]);
                $ext = pathinfo($stored['file'], PATHINFO_EXTENSION);
                send_mail_attach((string)cfg('notify_emails'), "Платёжное поручение по заказу {$o['number']}",
                    "Клиент прикрепил платёжное поручение по заказу {$o['number']}.\nКомпания: {$o['company']}\n\nПроверьте поступление и смените статус: " . LK_SITE . '/lk/admin/?n=' . urlencode($o['number']),
                    LK_FILES . '/' . $stored['file'], "Платёжка {$o['number']}.$ext", $stored['mime']);
                send_telegram("Платёжка по заказу {$o['number']} — проверьте поступление");
                lk_flash('Платёжное поручение получено. Менеджер проверит поступление и подтвердит оплату.');
            } catch (RuntimeException $e) { lk_flash($e->getMessage(), 'bad'); }
            lk_redirect($self);
        }
        if ($act === 'reissue' && $o['status'] === 'Срок резерва истёк') {
            $cat = catalog(); $items = []; $gone = []; $rub = 0.0; $usd = 0.0;
            foreach (lk_items($o) as $it) {
                $p = $cat[$it['code']] ?? null;
                if (!$p || (isset($p['in_stock']) && $p['in_stock'] === 0)) { $gone[] = $it['name']; continue; }
                $it['price'] = $p['price']; $it['cur'] = $p['cur']; $it['sum'] = $p['price'] * $it['qty']; $it['name'] = $p['name'];
                $p['cur'] === 'USD' ? $usd += $it['sum'] : $rub += $it['sum'];
                $items[] = $it;
            }
            if (!$items) { lk_flash('Позиций заказа сейчас нет в наличии. Оставьте обращение — менеджер подберёт замену.', 'bad'); lk_redirect($self); }
            $pdo->prepare("UPDATE orders SET items_json=?, total_rub=?, total_usd=?, status='Новый', valid_until=NULL, updated_at=? WHERE id=?")
                ->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $rub, $usd, date('Y-m-d H:i:s'), $o['id']]);
            lk_notify_manager("Перевыставить счёт по заказу {$o['number']}", "Клиент обновил цены по заказу {$o['number']} после окончания резерва. Новая сумма по прайсу: " . money_rub($rub)
                . ($gone ? "\nНет в наличии, убраны из заказа: " . implode('; ', $gone) : '') . "\nВыставьте новый счёт в 1С и прикрепите его в кабинете.");
            lk_flash('Цены обновлены по текущему прайсу. Менеджер выставит новый счёт — он появится здесь.' . ($gone ? ' Некоторых позиций нет в наличии, они убраны из заказа.' : ''));
            lk_redirect($self);
        }
        if ($act === 'repeat') {
            $cart = [];
            foreach (lk_items($o) as $it) $cart[$it['code']] = (int)$it['qty'];
            $js = json_encode($cart);
            echo '<!DOCTYPE html><meta charset="utf-8"><script>try{var c=JSON.parse(localStorage.getItem("infotech-cart2")||"{}"),n=' . $js . ';for(var k in n)c[k]=(c[k]||0)+n[k];localStorage.setItem("infotech-cart2",JSON.stringify(c))}catch(e){}location.href="/magazin-1c/?cart=1"</script>';
            exit;
        }
    }

    $rows = '';
    foreach (lk_items($o) as $it) {
        $m = lk_product_meta((string)$it['code']);
        $rows .= '<tr><td class="ph">' . ($m['img'] ? '<img src="' . h($m['img']) . '" alt="" loading="lazy">' : '') . '</td>'
            . '<td data-l="Наименование">' . h($it['name']) . '</td><td data-l="Артикул">' . h($it['code']) . '</td><td data-l="Кол-во">' . (int)$it['qty'] . ' шт</td>'
            . '<td data-l="Цена">' . lk_money($it) . '</td><td data-l="Сумма"><b>' . lk_money($it, true) . '</b></td><td data-l="Гарантия">' . h($m['warranty'] ?: '—') . '</td></tr>';
    }
    $total = money_rub((float)$o['total_rub']) . ((float)$o['total_usd'] ? ' + ' . money_usd((float)$o['total_usd']) : '');
    $docs = lk_docs_for_order((int)$o['id']);
    $dl = '';
    foreach ($docs as $d) {
        $label = (LK_DOC_KINDS[$d['kind']] ?? 'Документ') . ($d['number'] ? ' № ' . h($d['number']) : '') . ' от ' . lk_date($d['doc_date']);
        $dl .= '<li>' . ($d['blocked'] ? '<span class="muted">' . $label . ' — недействителен (срок резерва истёк)</span>' : '<a href="/lk/file.php?id=' . $d['id'] . '">' . $label . '</a>') . '</li>';
    }
    $invDoc = null;
    foreach ($docs as $d) if ($d['kind'] === 'invoice' && !$d['blocked']) { $invDoc = $d; break; }
    $acts = ($invDoc ? '<a class="btn" href="/lk/file.php?id=' . $invDoc['id'] . '">Скачать счёт (PDF из 1С)</a>' : '') . '<a class="btn ghost" href="/lk/kp.php?n=' . urlencode($o['number']) . '">Скачать КП для руководства (PDF)</a>'
        . '<form method="post" class="inline">' . lk_csrf_field() . '<button class="btn ghost" name="act" value="repeat">Повторить заказ</button></form>';
    $note = '';
    if ($o['status'] === 'Новый') $note = '<div class="flash wait">Заказ принят. Менеджер проверит наличие и выставит счёт — он появится в этом заказе и придёт на почту.</div>';
    if ($o['status'] === 'Счёт выставлен' && $o['has_hw'] && $o['valid_until'])
        $note = '<div class="flash wait">Счёт действует до <b>' . lk_date($o['valid_until'], true) . '</b> (3 банковских дня). После этого резерв снимается, цены на оборудование пересчитываются.</div>';
    $pay = in_array($o['status'], ['Счёт выставлен', 'Частично оплачен'], true) ? '<div class="card cut" id="pay"><h2>Прикрепить платёжное поручение вручную</h2><p class="muted">Оплаты из Сбербанка отмечаются автоматически. Если платёж ещё в пути, приложите платёжку — менеджер ускорит отгрузку.</p><p class="muted">PDF, JPG или PNG до 10 МБ. Менеджер получит его сразу и ускорит отгрузку.</p>'
        . '<form method="post" enctype="multipart/form-data" class="row">' . lk_csrf_field() . '<input type="hidden" name="act" value="pay"><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required><button class="btn">Отправить</button></form></div>' : '';
    $reissue = $o['status'] === 'Срок резерва истёк' ? '<div class="card cut" id="reissue"><h2>Срок резерва истёк</h2><p>Счёт не был оплачен за 3 банковских дня, и старый счёт больше не действует: цены на оборудование могли измениться.</p>'
        . '<form method="post">' . lk_csrf_field() . '<button class="btn" name="act" value="reissue">Обновить цены и перевыставить счёт</button></form></div>' : '';
    $keys = (in_array($o['status'], LK_KEYS_VISIBLE, true) && trim((string)$o['keys_text']) !== '')
        ? '<div class="card cut keys"><h2>Электронные поставки и ключи</h2><div class="keybox">' . nl2br(preg_replace('#(https?://[^\s<]+)#', '<a href="$1" target="_blank" rel="noopener">$1</a>', h($o['keys_text']))) . '</div><p class="muted">Не передавайте пин-коды третьим лицам.</p></div>' : '';
    /* история оплат из СберБизнеса / 1С */
    $hist = pay_history((int)$o['id']); $due = pay_due($o); $paidSum = (float)$o['paid_amount'];
    $hrows = '';
    foreach ($hist as $p) $hrows .= '<tr><td data-l="Дата">' . lk_date($p['payment_date']) . '</td><td data-l="П/П">№ ' . h($p['payment_order_num']) . '</td><td data-l="Сумма"><b>' . money_rub((float)$p['amount']) . '</b></td></tr>';
    $last = $hist ? end($hist) : null;
    if ($o['status'] === 'Оплачен' && $last) $note = '<div class="flash ok">Оплачено (П/П № ' . h($last['payment_order_num']) . ' от ' . lk_date($last['payment_date']) . '). Спасибо!</div>';
    if ($o['status'] === 'Частично оплачен') $note = '<div class="flash wait">Частично оплачено: ' . money_rub($paidSum) . ' из ' . money_rub($due) . '. Остаток к оплате: <b>' . money_rub(max(0, $due - $paidSum)) . '</b>.</div>';
    $payHist = $hist ? '<div class="card cut"><h2>История оплат по заказу</h2><table class="tbl cards"><thead><tr><th>Дата</th><th>Платёжное поручение</th><th>Сумма</th></tr></thead><tbody>' . $hrows . '</tbody></table>'
        . '<p class="total">Оплачено: <b>' . money_rub($paidSum) . '</b>' . ($o['invoice_amount'] ? ' из ' . money_rub($due) . ' по счёту' : '') . '</p></div>' : '';
    $comp = $o['company'] ? h($o['company']) . ($o['inn'] ? ', ИНН ' . h($o['inn']) : '') : 'Физическое лицо';
    $body = '<p><a href="/lk/orders/">← Все заказы</a></p><h1>Заказ № ' . h($o['number']) . ' ' . lk_badge($o['status']) . '</h1>' . lk_trace() . $note
        . '<div class="card cut"><div class="meta"><span>от ' . lk_date($o['created_at'], true) . '</span><span>' . $comp . '</span><span>' . h($o['delivery']) . '</span></div>'
        . '<table class="tbl items"><thead><tr><th></th><th>Наименование</th><th>Артикул</th><th>Кол-во</th><th>Цена</th><th>Сумма</th><th>Гарантия</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p class="total">Итого по прайсу: <b>' . $total . '</b></p><p class="muted">Окончательная сумма — в счёте. НДС не облагается (УСН).</p><div class="acts">' . $acts . '</div></div>'
        . $reissue . $payHist . $pay . $keys
        . '<div class="card cut"><h2>Документы по заказу</h2>' . ($dl ? '<ul class="docs">' . $dl . '</ul>' : '<p class="muted">Счёт, УПД и акты появятся здесь, когда менеджер их выставит.</p>') . '</div>';
    lk_page('Заказ ' . $o['number'], 'orders', $body, $u);
}

/* ---------- список ---------- */
$f = array_key_exists($_GET['f'] ?? '', LK_FILTERS) ? $_GET['f'] : 'all';
$q = trim((string)($_GET['q'] ?? ''));
$st = $pdo->prepare("SELECT * FROM orders WHERE user_id=? AND type='order' ORDER BY id DESC"); $st->execute([$uid]);
$all = array_map('lk_apply_reserve', $st->fetchAll());
$list = array_filter($all, function ($o) use ($f, $q) {
    $okF = LK_FILTERS[$f][1] === null || in_array($o['status'], LK_FILTERS[$f][1], true);
    $okQ = $q === '' || mb_stripos($o['number'], $q) !== false || mb_stripos((string)$o['items_json'], $q) !== false;
    return $okF && $okQ;
});
$tabs = '';
foreach (LK_FILTERS as $k => [$label, $sts]) {
    $n = count(array_filter($all, fn($o) => $sts === null || in_array($o['status'], $sts, true)));
    $tabs .= '<a href="?f=' . $k . ($q !== '' ? '&q=' . urlencode($q) : '') . '"' . ($k === $f ? ' class="on"' : '') . '>' . $label . ' <i>' . $n . '</i></a>';
}
$rows = '';
foreach ($list as $o) {
    $items = lk_items($o);
    $first = $items[0]['name'] ?? '';
    $rows .= '<tr><td data-l="Номер"><a href="?n=' . urlencode($o['number']) . '"><b>' . h($o['number']) . '</b></a></td><td data-l="Дата">' . lk_date($o['created_at']) . '</td>'
        . '<td data-l="Состав">' . h(mb_strimwidth($first, 0, 60, '…')) . (count($items) > 1 ? ' <span class="muted">+ ещё ' . (count($items) - 1) . '</span>' : '') . '</td>'
        . '<td data-l="Сумма">' . money_rub((float)$o['total_rub']) . '</td><td data-l="Статус">' . lk_badge($o['status']) . '</td></tr>';
}
$body = '<h1>Мои заказы</h1>' . lk_trace()
    . '<form class="search" method="get"><input type="hidden" name="f" value="' . h($f) . '"><input name="q" value="' . h($q) . '" placeholder="Номер заказа или артикул PBK-…"><button class="btn">Найти</button></form>'
    . '<div class="tabs scroll">' . $tabs . '</div>'
    . ($rows ? '<div class="card cut"><table class="tbl cards"><thead><tr><th>Номер</th><th>Дата</th><th>Состав</th><th>Сумма</th><th>Статус</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
             : '<div class="card cut"><p class="muted">' . ($all ? 'Ничего не найдено.' : 'Заказов пока нет. Оформите заказ в магазине — он появится здесь.') . '</p><div class="acts"><a class="btn" href="/magazin-1c/">В магазин</a></div></div>');
lk_page('Мои заказы', 'orders', $body, $u);
