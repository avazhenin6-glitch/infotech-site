<?php
/* Скачивание документа — только владельцу заказа или менеджеру (защита от IDOR) */
declare(strict_types=1);
require __DIR__ . '/../../app/lk.php';
lk_session();
$id = (int)($_GET['id'] ?? 0);
$st = lk_db()->prepare('SELECT * FROM documents WHERE id=?'); $st->execute([$id]);
$d = $st->fetch();
$u = lk_user();
$manager = lk_is_manager();
if (!$d || (!$manager && (!$u || (int)$d['user_id'] !== (int)$u['id']))) { http_response_code(404); exit('Документ не найден'); }
if ($d['blocked'] && !$manager) { http_response_code(410); exit('Счёт недействителен: срок резерва истёк. Перевыставьте счёт в карточке заказа.'); }
$path = LK_FILES . '/' . $d['file'];
if (!is_file($path) || !str_starts_with(realpath($path), realpath(LK_FILES))) { http_response_code(404); exit('Файл не найден'); }
$ext = pathinfo($path, PATHINFO_EXTENSION);
$name = (LK_DOC_KINDS[$d['kind']] ?? 'Документ') . ($d['number'] ? ' ' . $d['number'] : '') . ' ' . date('d.m.Y', strtotime((string)$d['doc_date'])) . '.' . $ext;
header('Content-Type: ' . $d['mime']);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: inline; filename=\"document.$ext\"; filename*=UTF-8''" . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
readfile($path);
