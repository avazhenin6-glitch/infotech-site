<?php
/* Скачивание документа — только владельцу заказа или менеджеру (защита от IDOR) */
declare(strict_types=1);
require __DIR__ . '/../../app/lk.php';
lk_session();
/* вложение обращения: ?m=ID сообщения */
if (isset($_GET['m'])) {
    $st = svc_db()->prepare('SELECT m.*, t.user_id FROM ticket_msgs m JOIN tickets t ON t.id=m.ticket_id WHERE m.id=?'); $st->execute([(int)$_GET['m']]);
    $m = $st->fetch(); $u = lk_user();
    if (!$m || !$m['file'] || (!lk_is_manager() && (!$u || (int)$m['user_id'] !== (int)$u['id']))) { http_response_code(404); exit('Файл не найден'); }
    $path = LK_FILES . '/' . $m['file'];
    if (!is_file($path) || !str_starts_with(realpath($path), realpath(LK_FILES))) { http_response_code(404); exit('Файл не найден'); }
    header('Content-Type: ' . $m['mime']); header('Content-Length: ' . filesize($path)); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="attachment.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
    readfile($path); exit;
}
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
