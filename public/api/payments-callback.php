<?php
/* Контур А: 1С:УНФ ИНФОТЕХ сообщает о поступлении на расчётный счёт.
   POST /api/v1/payments/callback, JSON; подпись: X-Signature = hex(HMAC-SHA256(secret, X-Timestamp . "." . body)).
   Секрет — 'payments_secret' в app/config.php (только на сервере). Описание: docs/1c-payments-webhook.md */
declare(strict_types=1);
require __DIR__ . '/../../app/lib.php';
require __DIR__ . '/../../app/lk.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $data): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'POST only']);
if (lk_throttle('paycb', 300, 600)) out(429, ['ok' => false, 'error' => 'too many']);
$secret = (string)(cfg('payments_secret') ?? '');
if (strlen($secret) < 32) out(503, ['ok' => false, 'error' => 'payments_secret не настроен']);
$body = (string)file_get_contents('php://input', false, null, 0, 65536);
$ts = (string)($_SERVER['HTTP_X_TIMESTAMP'] ?? '');
$sig = strtolower((string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
if (!ctype_digit($ts) || abs(time() - (int)$ts) > 600) out(401, ['ok' => false, 'error' => 'bad timestamp']);
if (!hash_equals(hash_hmac('sha256', $ts . '.' . $body, $secret), $sig)) out(401, ['ok' => false, 'error' => 'bad signature']);

$j = json_decode($body, true);
if (!is_array($j)) out(400, ['ok' => false, 'error' => 'bad json']);
$amount = (float)str_replace([' ', ','], ['', '.'], (string)($j['amount'] ?? 0));
$t = [
    'order'             => trim((string)($j['order'] ?? '')),
    'invoice_number'    => trim((string)($j['invoice_number'] ?? '')),
    'invoice_date'      => pay_norm_date((string)($j['invoice_date'] ?? '')),
    'amount'            => $amount,
    'payment_date'      => pay_norm_date((string)($j['payment_date'] ?? '')) ?: date('Y-m-d'),
    'payment_order_num' => mb_substr(trim((string)($j['payment_order_num'] ?? '')), 0, 30),
    'payer_inn'         => preg_replace('/\D/', '', (string)($j['payer_inn'] ?? '')),
    'payer_name'        => mb_substr(trim((string)($j['payer_name'] ?? '')), 0, 300),
    'purpose'           => mb_substr(trim((string)($j['purpose'] ?? '')), 0, 1000),
];
if ($amount <= 0 || $t['payment_order_num'] === '') out(422, ['ok' => false, 'error' => 'amount и payment_order_num обязательны']);
$r = pay_register($t, '1С');
out(200, ['ok' => true] + array_intersect_key($r, array_flip(['status', 'order', 'order_status', 'note'])));
