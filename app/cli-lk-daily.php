<?php
/* Ежедневно (таймер lk-daily): напоминания о продлении услуг за 7/3/1 день, заказ на продление, просрочка; истечение резерва счетов.
   php app/cli-lk-daily.php [--dry]   — --dry: только показать, что было бы сделано */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/lk.php';
$dry = in_array('--dry', $argv, true);
$pdo = svc_db(); $now = date('Y-m-d H:i:s'); $log = [];
$sent = function (int $sid, string $until, int $days) use ($pdo): bool {
    $st = $pdo->prepare('SELECT 1 FROM service_reminders WHERE service_id=? AND paid_until=? AND days=?'); $st->execute([$sid, $until, $days]);
    return (bool)$st->fetchColumn();
};
$mark = function (int $sid, string $until, int $days) use ($pdo, $now, $dry): void {
    if (!$dry) $pdo->prepare('INSERT OR IGNORE INTO service_reminders (service_id, paid_until, days, sent_at) VALUES (?,?,?,?)')->execute([$sid, $until, $days, $now]);
};
$plural = fn(int $n) => $n % 10 === 1 && $n % 100 !== 11 ? 'день' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? 'дня' : 'дней');

foreach ($pdo->query("SELECT s.*, u.email, u.name AS uname FROM services s JOIN users u ON u.id=s.user_id WHERE s.status IN ('Активен','Обслуживание') AND s.paid_until IS NOT NULL")->fetchAll() as $s) {
    $until = svc_until($s); $d = svc_days_left($s); $name = $s['title'] ?: SVC_KINDS[$s['kind']];
    if ($d === null) continue;
    if ($d < 0) {   // просрочено — один раз сообщаем менеджеру
        if (!$sent((int)$s['id'], $until, 0)) {
            $log[] = "просрочено: {$name} ({$s['email']}), с " . lk_date($until);
            if (!$dry) lk_notify_manager("Просрочена оплата: $name", "Услуга «{$name}» клиента {$s['email']} оплачена до " . lk_date($until) . ", оплаты нет. Решите, приостанавливать ли услугу.");
            $mark((int)$s['id'], $until, 0);
        }
        continue;
    }
    // ближайший порог, по которому ещё не писали: 1, 3 или 7 дней (пропущенные пороги покрываются одним письмом)
    $thr = null;
    foreach ([1, 3, 7] as $t) if ($d <= $t && !$sent((int)$s['id'], $until, $t)) { $thr = $t; break; }
    if ($thr === null) continue;
    $order = $dry ? svc_open_renewal($s) : svc_create_renewal($s, 'cron');
    $amount = $order ? money_rub((float)$order['total_rub']) : '';
    $text = "Здравствуйте" . ($s['uname'] ? ", {$s['uname']}" : '') . "!\n\n"
        . "Услуга «{$name}» оплачена до " . lk_date($until) . ($d === 0 ? ' — это сегодня.' : " — осталось $d " . $plural($d) . '.') . "\n"
        . ($order ? "Для продления создан заказ № {$order['number']}" . ($amount ? " на $amount" : '') . ". Счёт появится в личном кабинете: " . LK_SITE . '/lk/orders/?n=' . urlencode($order['number']) . "\n" : '')
        . ($s['kind'] === 'server' ? "Если оплата задерживается, в кабинете можно подключить обещанный платёж на 3 дня — сервер продолжит работать.\n" : '')
        . "\nВаши услуги: " . LK_SITE . "/lk/services/\nВопросы — 8 (812) 928-68-84, info@pbk.spb.ru\n\nООО «ИНФОТЕХ»";
    $log[] = "напоминание за $thr дн.: {$name} → {$s['email']}" . ($order ? " (заказ {$order['number']})" : '');
    if (!$dry) send_mail($s['email'], "Продление: {$name} — оплачено до " . lk_date($until), $text);
    foreach ([7, 3, 1] as $t) if ($t >= $thr) $mark((int)$s['id'], $until, $t);
}

/* резерв по счетам на оборудование: истёк — статус и письмо клиенту */
foreach ($pdo->query("SELECT * FROM orders WHERE type='order' AND status='Счёт выставлен' AND has_hw=1 AND valid_until < '$now'")->fetchAll() as $o) {
    $log[] = "резерв истёк: {$o['number']}";
    if ($dry) continue;
    lk_apply_reserve($o);
    if ($o['email']) send_mail($o['email'], "Заказ {$o['number']}: срок резерва истёк",
        "Счёт по заказу № {$o['number']} не был оплачен за 3 банковских дня, резерв снят. Цены на оборудование могли измениться.\n"
        . "Чтобы получить новый счёт, нажмите «Обновить цены и перевыставить счёт» в заказе: " . LK_SITE . '/lk/orders/?n=' . urlencode($o['number']) . "\n\nООО «ИНФОТЕХ», 8 (812) 928-68-84");
}

echo date('Y-m-d H:i') . ($dry ? ' [проверка]' : '') . ': ' . ($log ? "\n- " . implode("\n- ", $log) : 'событий нет') . "\n";
