<?php
/* Мои серверы и услуги 1С: аренда, сопровождение (часы), договоры 1С:ИТС; продление, обещанный платёж, масштабирование */
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
$u = lk_require(); $uid = (int)$u['id'];
$pdo = svc_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lk_check_csrf();
    $s = svc_get((int)($_POST['sid'] ?? 0), $uid);   // только свои услуги
    if (!$s) { http_response_code(404); exit('Услуга не найдена'); }
    $name = $s['title'] ?: SVC_KINDS[$s['kind']];
    switch ($_POST['act'] ?? '') {
        case 'renew':
            if ($s['kind'] === 'server') break;   // аренда — счета приходят сами, за 5 дней до конца месяца
            $o = svc_create_renewal($s);
            lk_flash('Заказ на продление создан. Менеджер выставит счёт — он появится в заказе и придёт на почту.');
            lk_redirect('/lk/orders/?n=' . urlencode($o['number']));
        case 'promise':
            if (!svc_can_promise($s)) { lk_flash('Обещанный платёж сейчас недоступен', 'bad'); break; }
            if ($s['kind'] === 'server') {
                $inv = bill_can_promise($s);
                $until = date('Y-m-d', strtotime(max($inv['due'], date('Y-m-d')) . ' +3 day'));
                $pdo->prepare("UPDATE services SET promised_until=?, promised_for=?, updated_at=? WHERE id=?")->execute([$until, $inv['number'], date('Y-m-d H:i:s'), $s['id']]);
                bill_log((int)$s['id'], 'promise', "обещанный платёж по счёту {$inv['number']} до " . lk_date($until), 'клиент');
                lk_notify_manager("Обещанный платёж: $name", "Клиент {$u['email']} взял обещанный платёж по счёту {$inv['number']}: доступ не закрываем до " . lk_date($until) . '.');
                lk_flash('Обещанный платёж подключён: доступ к серверу сохраняется до ' . lk_date($until) . ' включительно. Оплатите счёт № ' . $inv['number'] . ' до этой даты.');
                break;
            }
            $until = date('Y-m-d', strtotime(max($s['paid_until'], date('Y-m-d')) . ' +3 day'));
            $pdo->prepare("UPDATE services SET promised_until=?, promised_for=?, updated_at=? WHERE id=?")->execute([$until, $s['paid_until'], date('Y-m-d H:i:s'), $s['id']]);
            svc_create_renewal($s);
            lk_notify_manager("Обещанный платёж: $name", "Клиент {$u['email']} взял обещанный платёж по услуге «{$name}» до " . lk_date($until) . ". Счёт на продление создан в кабинете.");
            lk_flash('Обещанный платёж подключён: услуга работает до ' . lk_date($until) . '. Оплатите счёт на продление до этой даты.');
            break;
        case 'scale':
            $want = clean($_POST['want'] ?? '', 1000);
            if (mb_strlen($want) < 3) { lk_flash('Опишите, какие ресурсы нужны', 'bad'); break; }
            $t = tk_create($uid, 'Сервер / аренда', "Масштабирование: $name", "Прошу изменить ресурсы сервера «{$name}».\nСейчас: " . svc_cfg_text($s) . "\nНужно: $want", null, (int)$s['id']);
            lk_flash("Запрос {$t['number']} отправлен. Менеджер рассчитает стоимость и ответит в обращении.");
            lk_redirect('/lk/support/?t=' . $t['id']);
    }
    lk_redirect('/lk/services/');
}

function svc_cfg_text(array $s): string {
    $c = svc_config($s);
    $p = [];
    if (!empty($c['cpu'])) $p[] = $c['cpu'] . ' vCPU';
    if (!empty($c['ram'])) $p[] = $c['ram'] . ' ГБ RAM';
    if (!empty($c['disk'])) $p[] = $c['disk'] . ' ГБ' . (!empty($c['disk_type']) ? ' ' . $c['disk_type'] : '');
    if (!empty($c['users'])) $p[] = 'до ' . $c['users'] . ' польз.';
    return $p ? implode(' · ', $p) : '—';
}

$svcs = svc_for_user($uid);
$cards = '';
foreach ($svcs as $s) {
    $name = $s['title'] ?: SVC_KINDS[$s['kind']];
    $d = svc_days_left($s);
    $until = svc_until($s);
    $open = svc_open_renewal($s);
    $f = lk_csrf_field() . '<input type="hidden" name="sid" value="' . $s['id'] . '">';
    $dleft = $d === null ? '' : ($d < 0 ? '<span class="neg">просрочено на ' . abs($d) . ' дн.</span>' : 'осталось ' . $d . ' дн.');
    $acts = $open ? '<a class="btn" href="/lk/orders/?n=' . urlencode($open['number']) . '">Счёт на продление: ' . h(LK_ST[$open['status']][0] ?? $open['status']) . '</a>'
                  : '<form method="post" class="inline">' . $f . '<button class="btn" name="act" value="renew">' . ($s['kind'] === 'server' ? 'Продлить аренду' : 'Продлить') . '</button></form>';
    $body = '<div class="kv"><span>Оплачено до</span><b>' . lk_date($s['paid_until']) . '</b>'
        . ($s['promised_until'] && $s['promised_until'] > (string)$s['paid_until'] ? '<span>Обещанный платёж до</span><b>' . lk_date($s['promised_until']) . '</b>' : '')
        . '<span>Период оплаты</span><b>' . (int)$s['period_months'] . ' мес.' . ($s['price'] ? ' · ' . money_rub((float)$s['price']) : '') . '</b></div>'
        . ($until ? '<p class="muted">' . $dleft . '</p>' : '');
    if ($s['kind'] === 'server') {
        $openInv = bill_open_invoices((int)$s['id']);
        $acts = '';
        foreach ($openInv as $i) $acts .= '<a class="btn" href="/lk/orders/?n=' . urlencode($i['order_number']) . '">Счёт № ' . h($i['number']) . ' — ' . money_rub((float)$i['amount'] - (float)$i['paid_amount']) . '</a>';
        $next = bill_issue_day(date('Y-m'));
        if ($next < date('Y-m-d')) $next = bill_issue_day(bill_next_ym(date('Y-m')));
        $due = $openInv ? bill_eff_due($openInv[0], $s) : null;
        $body = '<div class="kv"><span>Оплачено до</span><b>' . lk_date($s['paid_until']) . '</b><span>Абонентская плата</span><b>' . money_rub(bill_monthly_price($s)) . ' в месяц</b>'
            . ($openInv ? '<span>К оплате</span><b>' . money_rub(bill_debt((int)$s['id'])) . ' до ' . lk_date($due) . '</b>' : '<span>Следующий счёт</span><b>' . lk_date($next) . '</b>') . '</div>'
            . ($s['access'] === 'closed' ? '<p class="neg">Доступ приостановлен из-за неоплаты. После оплаты он откроется в течение рабочего дня.' . (($pd = bill_purge_date($s)) ? ' Сервер выключен; данные будут удалены ' . lk_date($pd) . ', если оплата не поступит.' : '') . '</p>'
               : ($due && $due < date('Y-m-d') ? '<p class="neg">Срок оплаты истёк — доступ может быть приостановлен в любой момент.</p>' : ''));
        $vpnOk = $s['vpn_status'] === 'Активен';
        $body = '<div class="kv"><span>Конфигурация</span><b>' . h(svc_cfg_text($s)) . '</b><span>Канал OpenVPN</span><b><i class="dot ' . ($vpnOk ? 'ok' : 'bad') . '"></i>' . h($s['vpn_status']) . '</b>'
            . '<span>Статус сервера</span><b>' . h($s['status']) . '</b></div>' . $body;
        if (svc_can_promise($s)) $acts .= '<form method="post" class="inline" onsubmit="return confirm(\'Подключить обещанный платёж на 3 дня? Доступен один раз на счёт.\')">' . $f . '<button class="btn ghost" name="act" value="promise">Обещанный платёж (на 3 дня)</button></form>';
        $acts .= '<button class="btn ghost" type="button" onclick="this.nextElementSibling.hidden=!this.nextElementSibling.hidden">Запросить масштабирование</button>'
            . '<form method="post" class="scale" hidden>' . $f . '<input type="hidden" name="act" value="scale"><label>Что изменить<textarea name="want" rows="3" placeholder="Например: +8 ГБ RAM, +200 ГБ диска, ещё 3 пользователя" required></textarea></label><button class="btn">Отправить запрос</button></form>';
    }
    if ($s['kind'] === 'support' && $s['hours_month'] > 0) {
        $used = svc_hours_used((int)$s['id']); $tot = (float)$s['hours_month'];
        $pct = min(100, round($used / $tot * 100));
        $rows = '';
        foreach (svc_hours_list((int)$s['id']) as $hr)
            $rows .= '<tr><td data-l="Дата">' . lk_date($hr['work_date']) . '</td><td data-l="Работа">' . h($hr['description']) . '</td><td data-l="Часы">' . rtrim(rtrim(number_format((float)$hr['hours'], 2, ',', ''), '0'), ',') . ' ч</td></tr>';
        $body .= '<div class="hours"><div class="hours-h"><span>Часы специалиста в ' . h(['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'][(int)date('n') - 1]) . '</span>'
            . '<b>' . str_replace('.', ',', (string)round($used, 2)) . ' из ' . str_replace('.', ',', (string)$tot) . ' ч</b></div>'
            . '<div class="bar' . ($used > $tot ? ' over' : '') . '"><i style="width:' . $pct . '%"></i></div>'
            . ($used > $tot ? '<p class="muted">Сверх тарифа: ' . str_replace('.', ',', (string)round($used - $tot, 2)) . ' ч — оплачиваются по ставке сверхлимита.</p>' : '')
            . ($rows ? '<details><summary>Что сделано в этом месяце</summary><table class="tbl cards"><tbody>' . $rows . '</tbody></table></details>' : '') . '</div>';
    }
    $cards .= '<div class="card cut svc"><div class="svc-h"><div><span class="kind">' . h(SVC_KINDS[$s['kind']]) . '</span><h2>' . h($name) . '</h2></div>' . svc_badge($s) . '</div>'
        . $body . ($s['note'] ? '<p class="muted">' . nl2br(h($s['note'])) . '</p>' : '') . '<div class="acts">' . $acts . '</div></div>';
}
$body = '<h1>Серверы и услуги 1С</h1>' . lk_trace()
    . ($cards ?: '<div class="card cut"><h2>Подключённых услуг пока нет</h2><p>Здесь будут арендованные серверы, сопровождение 1С с остатком часов и договоры 1С:ИТС: даты оплаты, продление в один клик и напоминания за 7, 3 и 1 день до окончания.</p>'
        . '<p class="muted">Если вы уже пользуетесь нашими услугами, а здесь пусто — напишите менеджеру, он подключит их к кабинету.</p>'
        . '<div class="acts"><a class="btn" href="/uslugi/arenda-servera-1c/">Тарифы аренды</a><a class="btn ghost" href="/uslugi/soprovozhdenie-1c/">Сопровождение 1С</a><a class="btn ghost" href="/lk/support/">Написать менеджеру</a></div></div>');
lk_page('Серверы и 1С', 'services', $body, $u);
