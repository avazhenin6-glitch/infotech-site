<?php
/* JSON для сайта: кто вошёл (шапка, автозаполнение оформления), синхронизация корзины, ИНН → реквизиты */
declare(strict_types=1);
require __DIR__ . '/../../app/lk.php';
lk_session();
$a = (string)($_GET['a'] ?? '');
$u = lk_user();
// запросы с изменением данных — только с заголовком сайта (кросс-доменная форма его не поставит)
$xhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'pbk';
if ($a === 'me') {
    if (!$u) json_out(['logged' => false]);
    $c = lk_companies((int)$u['id']);
    json_out(['logged' => true, 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'],
              'companies' => array_map(fn($x) => ['inn' => $x['inn'], 'name' => $x['name'], 'kpp' => $x['kpp']], $c)]);
}
if (!$u) json_out(['ok' => false, 'error' => 'auth'], 401);
if ($a === 'cart') {
    $pdo = lk_db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$xhr) json_out(['ok' => false], 403);
        $in = json_decode((string)file_get_contents('php://input'), true);
        $cart = [];
        foreach ((array)($in['cart'] ?? []) as $code => $q) if (is_string($code) && strlen($code) < 40 && (int)$q > 0 && (int)$q < 10000) $cart[$code] = (int)$q;
        $pdo->prepare('UPDATE users SET cart_json=?, cart_updated=? WHERE id=?')->execute([json_encode($cart), date('Y-m-d H:i:s'), $u['id']]);
        json_out(['ok' => true]);
    }
    json_out(['ok' => true, 'cart' => json_decode((string)$u['cart_json'], true) ?: (object)[]]);
}
if ($a === 'inn') {
    if (!$xhr || lk_throttle('inn', 30, 3600)) json_out(['ok' => false], 429);
    $r = lk_dadata_inn(preg_replace('/\D/', '', (string)($_GET['inn'] ?? '')));
    json_out($r ? ['ok' => true] + $r : ['ok' => false]);
}
json_out(['ok' => false], 404);
