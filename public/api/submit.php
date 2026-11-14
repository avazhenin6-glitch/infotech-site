<?php
/*
 * Приём заказов из магазина и заявок с сайта.
 * POST /api/submit.php, тело — JSON:
 *   {type:"order", items:[{code,qty}], delivery, city, need_install, payment, buyer:{name,email,phone,inn,company}, comment, website:""}
 *   {type:"lead",  name, phone, company, comment, website:""}
 * Цены и наименования берутся с сервера (catalog.js), а не из браузера.
 */
declare(strict_types=1);
require __DIR__ . '/../../app/lk.php';   // lib.php + личный кабинет


/* Телефон: принимаем только российский номер +7XXXXXXXXXX и приводим к виду +7 (XXX) XXX-XX-XX */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) json_out(['ok' => false, 'error' => 'Некорректный запрос'], 400);

// Ловушка для ботов: скрытое поле должно быть пустым
if (!empty($in['website'])) json_out(['ok' => true, 'number' => '—']);
if (rate_limited()) json_out(['ok' => false, 'error' => 'Слишком много отправок. Попробуйте через 10 минут или позвоните нам.'], 429);

$type = ($in['type'] ?? '') === 'order' ? 'order' : 'lead';
$now  = date('Y-m-d H:i:s');
$pdo  = db();

if ($type === 'lead') {
    $name = clean($in['name'] ?? '', 120); $phone = clean($in['phone'] ?? '', 60);
    $company = clean($in['company'] ?? '', 200); $comment = clean($in['comment'] ?? '', 3000);
    $phone = norm_phone($phone) ?? '';
    if (mb_strlen($name) < 2 || $phone === '')
        json_out(['ok' => false, 'error' => 'Укажите имя и телефон в формате +7 (XXX) XXX-XX-XX'], 422);
    $pdo->prepare('INSERT INTO orders (type, created_at, name, phone, company, comment, ip, user_agent) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(['lead', $now, $name, $phone, $company, $comment, client_ip(), clean($_SERVER['HTTP_USER_AGENT'] ?? '', 300)]);
    $id = (int)$pdo->lastInsertId();
    $number = 'З-' . date('ymd') . '-' . $id;
    $pdo->prepare('UPDATE orders SET number=? WHERE id=?')->execute([$number, $id]);

    $text = "Заявка с сайта {$number}\n\nИмя: {$name}\nТелефон: {$phone}" . ($company ? "\nКомпания: {$company}" : '') . ($comment ? "\n\n{$comment}" : '')
          . "\n\n" . cfg('site_url') . "/admin/?id={$id}";
    send_mail((string)cfg('notify_emails'), "Заявка с сайта {$number} — {$name}", $text);
    // В Telegram — без персональных данных (серверы Telegram за рубежом): только номер и ссылка
    send_telegram("Новая заявка с сайта {$number}" . ($company ? " · {$company}" : '') . "\n" . cfg('site_url') . "/admin/?id={$id}");
    json_out(['ok' => true, 'number' => $number]);
}

/* ---------- заказ ---------- */
$cat = catalog();
$items = []; $rub = 0.0; $usd = 0.0; $eCount = 0; $pCount = 0; $sCount = 0;
foreach (array_slice((array)($in['items'] ?? []), 0, 200) as $row) {
    $code = (string)($row['code'] ?? ''); $qty = (int)($row['qty'] ?? 0);
    if (!isset($cat[$code]) || $qty < 1 || $qty > 9999) continue;
    $p = $cat[$code];
    if (isset($p['in_stock']) && $p['in_stock'] === 0)   // регламент: на отсутствующий товар заказ не оформляется (2 = под заказ — можно)
        json_out(['ok' => false, 'error' => "«{$p['name']}» сейчас нет в наличии у поставщиков — удалите его из корзины и оставьте заявку, менеджер подберёт аналог."], 422);
    $sum = $p['price'] * $qty;
    if ($p['cur'] === 'USD') $usd += $sum; else $rub += $sum;
    if ($p['e'] === 1) $eCount += $qty; elseif ($p['e'] === 2) $sCount += $qty; else $pCount += $qty;
    $items[] = ['code' => $code, 'name' => $p['name'], 'qty' => $qty, 'price' => $p['price'], 'cur' => $p['cur'], 'sum' => $sum, 'e' => $p['e'], 'pre' => ($p['in_stock'] ?? 1) === 2];
}
if (!$items) json_out(['ok' => false, 'error' => 'Корзина пуста или товары устарели. Обновите страницу.'], 422);

$b = (array)($in['buyer'] ?? []);
$name = clean($b['name'] ?? '', 150); $email = clean($b['email'] ?? '', 150); $phone = clean($b['phone'] ?? '', 60);
$inn = preg_replace('/\D/', '', (string)($b['inn'] ?? '')); $company = clean($b['company'] ?? '', 250);
$errors = [];
if (mb_strlen($name) < 3) $errors[] = 'ФИО';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'e-mail';
$phone = norm_phone($phone) ?? ''; if ($phone === '') $errors[] = 'телефон (+7 и 10 цифр)';
if ($inn !== '' && !in_array(strlen($inn), [10, 12], true)) $errors[] = 'ИНН (10 или 12 цифр)';
if ($errors) json_out(['ok' => false, 'error' => 'Проверьте поля: ' . implode(', ', $errors)], 422);

$deliveryPhys = clean($in['delivery'] ?? '', 100);
$city = clean($in['city'] ?? '', 120);
$parts = [];
if ($eCount) $parts[] = 'Электронная поставка на e-mail';
if ($sCount) $parts[] = 'Услуги ИНФОТЕХ — подключение после оплаты';
if ($pCount) $parts[] = ($deliveryPhys ?: 'Курьерская доставка') . ($city ? " ({$city})" : '');
$needInstall = !empty($in['need_install']) ? 1 : 0;
if ($needInstall) $parts[] = 'установка специалистом';
$delivery = implode(' + ', $parts);
$payment = clean($in['payment'] ?? '', 100) ?: 'Безналичный расчёт (счёт)';
$comment = clean($in['comment'] ?? '', 3000);

$pdo->prepare('INSERT INTO orders (type, created_at, name, email, phone, inn, company, delivery, payment, city, need_install, comment, total_rub, total_usd, items_json, ip, user_agent)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute(['order', $now, $name, $email, $phone, $inn, $company, $delivery, $payment, $city, $needInstall, $comment, $rub, $usd,
               json_encode($items, JSON_UNESCAPED_UNICODE), client_ip(), clean($_SERVER['HTTP_USER_AGENT'] ?? '', 300)]);
$id = (int)$pdo->lastInsertId();
$number = 'ИТ-' . date('ymd') . '-' . $id;
$pdo->prepare('UPDATE orders SET number=? WHERE id=?')->execute([$number, $id]);
// личный кабинет: заказ привязывается к аккаунту (вошедшего покупателя или по e-mail; новый аккаунт создаётся сам)
try { $acc = lk_attach_order($id, $email, $name, $phone, $inn, $company); } catch (Throwable $e) { error_log('LK attach: ' . $e->getMessage()); $acc = null; }

$lines = array_map(fn($i) => "• {$i['name']} (арт. {$i['code']}) — {$i['qty']} шт × "
    . ($i['cur'] === 'USD' ? money_usd($i['price']) : money_rub($i['price'])) . ($i['e'] === 1 ? ' [e-mail]' : ($i['e'] === 2 ? ' [услуга]' : '')) . (!empty($i['pre']) ? ' [под заказ]' : ''), $items);
$total = money_rub($rub) . ($usd ? ' + ' . money_usd($usd) . ' (по курсу ЦБ)' : '');
$body = implode("\n", $lines) . "\n\nОриентир по прайсу (РРЦ «1С» / тарифы): {$total} — покупателю не показывается, сумму укажите в счёте\nПоставка: {$delivery}\nОплата: {$payment}";
// Покупателю — без цен: окончательную стоимость менеджер укажет в счёте
$custLines = array_map(fn($i) => "• {$i['name']} (арт. {$i['code']}) — {$i['qty']} шт" . ($i['e'] === 1 ? ' [e-mail]' : ($i['e'] === 2 ? ' [услуга]' : '')) . (!empty($i['pre']) ? ' [под заказ]' : ''), $items);
$custBody = implode("\n", $custLines) . "\n\nСтоимость: менеджер укажет в счёте (НДС не облагается, УСН)\nПоставка: {$delivery}\nОплата: {$payment}";
$buyer = "Покупатель: {$name}\nE-mail: {$email}\nТелефон: {$phone}" . ($inn ? "\nИНН: {$inn}" : '') . ($company ? "\nОрганизация: {$company}" : '') . ($comment ? "\nКомментарий: {$comment}" : '');

$managerText = "Новый заказ {$number}\n\n{$body}\n\n{$buyer}\n\n" . cfg('site_url') . "/lk/admin/?n=" . urlencode($number);
send_mail((string)cfg('notify_emails'), "Заказ {$number}", $managerText);
// В Telegram — состав и сумма без персональных данных покупателя
send_telegram("Новый заказ {$number}\n\n{$body}" . ($company ? "\nОрганизация: {$company}" : '') . "\n\n" . cfg('site_url') . "/lk/admin/?n=" . urlencode($number));

if (cfg('send_customer_copy')) {
    $cust = "Здравствуйте, {$name}!\n\nСпасибо за заказ в " . cfg('site_name') . ". Номер заказа: {$number}.\n\n{$custBody}\n\n"
          . "Менеджер свяжется с вами, уточнит детали и пришлёт счёт.\n"
          . "\nЗаказ, счёт и документы — в личном кабинете: " . cfg('site_url') . '/lk/orders/?n=' . urlencode($number) . "\n"
          . ($acc && $acc['created'] ? "Мы создали для вас кабинет на этот e-mail. Войдите по одноразовому коду, пароль можно задать потом.\n" : '')
          . ($eCount ? "Электронные лицензии и пин-коды придут на этот e-mail после оплаты.\n" : '')
          . ($sCount ? "Аренда сервера и сопровождение подключаются после оплаты — менеджер согласует дату запуска.\n" : '')
          . "\n" . cfg('site_name') . "\n" . cfg('site_url');
    send_mail($email, "Ваш заказ {$number} принят — " . cfg('site_name'), $cust);
}

json_out(['ok' => true, 'number' => $number]);
