<?php
/* Дашборд личного кабинета */
declare(strict_types=1);
require __DIR__ . '/../../app/lk.php';
$u = lk_require();
$pdo = lk_db();
$st = $pdo->prepare("SELECT * FROM orders WHERE user_id=? AND type='order' ORDER BY id DESC LIMIT 20"); $st->execute([$u['id']]);
$orders = array_map('lk_apply_reserve', $st->fetchAll());
$active = null;
foreach ($orders as $o) if (!in_array($o['status'], ['Выполнен', 'Отменён'], true)) { $active = $o; break; }

$w1 = '<div class="card cut"><h2>Активный заказ</h2>';
if ($active) {
    $inv = null;
    foreach (lk_docs_for_order((int)$active['id']) as $d) if ($d['kind'] === 'invoice' && !$d['blocked']) { $inv = $d; break; }
    $w1 .= '<p class="big"><a href="/lk/orders/?n=' . urlencode($active['number']) . '">№ ' . h($active['number']) . '</a> ' . lk_badge($active['status']) . '</p>'
        . '<p class="muted">от ' . lk_date($active['created_at']) . ' · позиций: ' . count(lk_items($active))
        . ($active['status'] === 'Счёт выставлен' && $active['has_hw'] && $active['valid_until'] ? ' · счёт действует до ' . lk_date($active['valid_until'], true) : '') . '</p>'
        . ((float)($active['paid_amount'] ?? 0) > 0 ? (function ($o) { $due = pay_due($o); $pct = $due > 0 ? min(100, (int)round((float)$o['paid_amount'] / $due * 100)) : 100;
            return '<div class="paybar" title="Оплачено ' . $pct . '%"><i style="width:' . $pct . '%"></i></div><p class="muted">Оплачено ' . money_rub((float)$o['paid_amount']) . ' из ' . money_rub($due) . '</p>'; })($active) : '')
        . '<div class="acts">'
        . ($inv ? '<a class="btn" href="/lk/file.php?id=' . $inv['id'] . '">Скачать счёт (PDF)</a>' : '')
        . (in_array($active['status'], ['Счёт выставлен', 'Частично оплачен'], true) ? '<a class="btn ghost" href="/lk/orders/?n=' . urlencode($active['number']) . '#pay">Прикрепить платёжное поручение</a>' : '')
        . ($active['status'] === 'Срок резерва истёк' ? '<a class="btn" href="/lk/orders/?n=' . urlencode($active['number']) . '#reissue">Обновить цены и перевыставить счёт</a>' : '')
        . '<a class="btn ghost" href="/lk/kp.php?n=' . urlencode($active['number']) . '">КП для руководства (PDF)</a></div>';
    if ($active['status'] === 'Новый') $w1 .= '<p class="muted">Менеджер проверит наличие и выставит счёт — он появится здесь и придёт на почту.</p>';
} else {
    $w1 .= '<p class="muted">Активных заказов нет.</p><div class="acts"><a class="btn" href="/magazin-1c/">Перейти в магазин</a></div>';
}
$w1 .= '</div>';

$svcs = svc_for_user((int)$u['id']);
if ($svcs) {
    $li = '';
    foreach ($svcs as $s) {
        $d = svc_days_left($s);
        $extra = $s['kind'] === 'support' && $s['hours_month'] > 0
            ? ' · часы: ' . str_replace('.', ',', (string)round(svc_hours_used((int)$s['id']), 2)) . ' из ' . str_replace('.', ',', (string)(float)$s['hours_month'])
            : ($s['kind'] === 'server' ? ' · ' . h($s['status']) : '');
        $li .= '<li><b>' . h($s['title'] ?: SVC_KINDS[$s['kind']]) . '</b> ' . svc_badge($s) . '<br><span class="muted">до ' . lk_date(svc_until($s))
            . ($d !== null ? ($d < 0 ? ' · просрочено' : ' · ' . $d . ' дн.') : '') . $extra . '</span></li>';
    }
    $w2 = '<div class="card cut"><h2>Мои серверы и 1С</h2><ul class="svclist">' . $li . '</ul><div class="acts"><a class="btn" href="/lk/services/">Управлять услугами</a></div></div>';
} else {
    $w2 = '<div class="card cut"><h2>Мои серверы и 1С</h2><p class="muted">Подключённых услуг нет. Здесь появятся аренда серверов, сопровождение 1С и договоры ИТС с датами оплаты и остатком часов.</p>'
        . '<div class="acts"><a class="btn ghost" href="/uslugi/arenda-servera-1c/">Тарифы аренды</a><a class="btn ghost" href="/uslugi/soprovozhdenie-1c/">Сопровождение 1С</a></div></div>';
}
$mgr = $u['manager'] ?: 'Отдел продаж ИНФОТЕХ';
$w3 = '<div class="card cut"><h2>Персональный менеджер</h2><p class="big">' . h($mgr) . '</p>'
    . '<p><a href="tel:+78129286884">8 (812) 928-68-84</a><br><a href="mailto:info@pbk.spb.ru">info@pbk.spb.ru</a><br><span class="muted">Пн–Пт, 9:00–18:00</span></p>'
    . '<div class="acts"><a class="btn" href="/lk/support/">Создать обращение</a></div></div>';
$recent = '';
foreach (array_slice($orders, 0, 5) as $o)
    $recent .= '<tr><td><a href="/lk/orders/?n=' . urlencode($o['number']) . '">' . h($o['number']) . '</a></td><td>' . lk_date($o['created_at']) . '</td><td>' . lk_badge($o['status']) . '</td></tr>';
$body = '<h1>Здравствуйте' . ($u['name'] ? ', ' . h($u['name']) : '') . '</h1>' . lk_trace()
    . '<div class="grid3">' . $w1 . $w2 . $w3 . '</div>'
    . ($recent ? '<div class="card cut"><h2>Последние заказы</h2><table class="tbl"><thead><tr><th>Номер</th><th>Дата</th><th>Статус</th></tr></thead><tbody>' . $recent . '</tbody></table><p><a href="/lk/orders/">Все заказы →</a></p></div>' : '');
lk_page('Обзор', 'dash', $body, $u);
