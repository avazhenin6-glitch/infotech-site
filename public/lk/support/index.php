<?php
/* Обращения в поддержку: список, новое обращение, переписка с вложениями */
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
$u = lk_require(); $uid = (int)$u['id']; $pdo = svc_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lk_check_csrf();
    $text = clean($_POST['text'] ?? '', 6000);
    try {
        if (isset($_POST['tid'])) {   // ответ в обращение
            $t = tk_get((int)$_POST['tid'], $uid);
            if (!$t) { http_response_code(404); exit('Обращение не найдено'); }
            if (($_POST['act'] ?? '') === 'close') {
                $pdo->prepare("UPDATE tickets SET status='Закрыто', updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $t['id']]);
                lk_flash('Обращение закрыто. Спасибо!');
            } elseif (mb_strlen($text) < 2) lk_flash('Напишите сообщение', 'bad');
            elseif (lk_throttle('tmsg', 60, 3600)) lk_flash('Слишком много сообщений за час', 'bad');
            else {
                tk_add_msg((int)$t['id'], 'client', $text, $_FILES['file'] ?? null);
                lk_notify_manager("Ответ клиента в обращении {$t['number']}", "{$u['name']} <{$u['email']}>:\n\n$text\n\nОтветить: " . LK_SITE . "/lk/admin/?t={$t['id']}");
                send_telegram("Новое сообщение в обращении {$t['number']}");
                lk_flash('Сообщение отправлено');
            }
            lk_redirect('/lk/support/?t=' . $t['id']);
        }
        $topic = in_array($_POST['topic'] ?? '', TICKET_TOPICS, true) ? $_POST['topic'] : 'Другое';
        $subject = clean($_POST['subject'] ?? '', 150);
        if (mb_strlen($text) < 5) { lk_flash('Опишите вопрос', 'bad'); lk_redirect('/lk/support/'); }
        if (lk_throttle('ticket', 10, 3600)) { lk_flash('Слишком много обращений за час', 'bad'); lk_redirect('/lk/support/'); }
        $t = tk_create($uid, $topic, $subject ?: mb_strimwidth($text, 0, 60, '…'), $text, $_FILES['file'] ?? null);
        lk_flash("Обращение {$t['number']} создано. Ответ придёт сюда и на {$u['email']}.");
        lk_redirect('/lk/support/?t=' . $t['id']);
    } catch (RuntimeException $e) { lk_flash($e->getMessage(), 'bad'); lk_redirect('/lk/support/'); }
}

$upload = '<label>Вложение (необязательно: PDF, JPG, PNG до 10 МБ)<input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png"></label>';
if (isset($_GET['t'])) {
    $t = tk_get((int)$_GET['t'], $uid);
    if (!$t) { http_response_code(404); lk_page('Не найдено', 'support', '<h1>Обращение не найдено</h1><p><a href="/lk/support/">← Все обращения</a></p>', $u); }
    $closed = $t['status'] === 'Закрыто';
    $body = '<p><a href="/lk/support/">← Все обращения</a></p><h1>' . h($t['number']) . ' <span class="badge ' . (TICKET_ST[$t['status']] ?? 'wait') . '">' . h($t['status']) . '</span></h1>' . lk_trace()
        . '<div class="card cut"><p class="meta"><span>' . h($t['topic']) . '</span><span>' . h($t['subject']) . '</span><span>создано ' . lk_date($t['created_at'], true) . '</span></p>'
        . tk_thread($t, '/lk/file.php?m=') . '</div>'
        . '<div class="card cut"><h2>' . ($closed ? 'Возобновить обращение' : 'Ответить') . '</h2><form method="post" enctype="multipart/form-data">' . lk_csrf_field() . '<input type="hidden" name="tid" value="' . $t['id'] . '">'
        . '<label>Сообщение<textarea name="text" rows="4" required></textarea></label>' . $upload . '<div class="acts"><button class="btn">Отправить</button>'
        . ($closed ? '' : '<button class="btn ghost" name="act" value="close" formnovalidate>Вопрос решён — закрыть</button>') . '</div></form></div>';
    lk_page('Обращение ' . $t['number'], 'support', $body, $u);
}

$st = $pdo->prepare('SELECT t.*, (SELECT COUNT(*) FROM ticket_msgs m WHERE m.ticket_id=t.id) n FROM tickets t WHERE user_id=? ORDER BY updated_at DESC LIMIT 100'); $st->execute([$uid]);
$rows = '';
foreach ($st->fetchAll() as $t)
    $rows .= '<tr><td data-l="Номер"><a href="?t=' . $t['id'] . '"><b>' . h($t['number']) . '</b></a></td><td data-l="Тема">' . h($t['topic']) . '<br><span class="muted">' . h($t['subject']) . '</span></td>'
        . '<td data-l="Обновлено">' . lk_date($t['updated_at'], true) . '</td><td data-l="Статус"><span class="badge ' . (TICKET_ST[$t['status']] ?? 'wait') . '">' . h($t['status']) . '</span></td></tr>';
$opts = implode('', array_map(fn($x) => '<option>' . h($x) . '</option>', TICKET_TOPICS));
$body = '<h1>Обращения</h1>' . lk_trace()
    . ($rows ? '<div class="card cut"><h2>Мои обращения</h2><table class="tbl cards"><thead><tr><th>Номер</th><th>Тема</th><th>Обновлено</th><th>Статус</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '')
    . '<div class="card cut"><h2>Новое обращение</h2><form method="post" enctype="multipart/form-data">' . lk_csrf_field()
    . '<div class="row"><label>Тема<select name="topic">' . $opts . '</select></label><label>Кратко<input name="subject" maxlength="150" placeholder="Например: не запускается 1С"></label></div>'
    . '<label>Вопрос<textarea name="text" rows="5" required placeholder="Опишите задачу или проблему: что происходит, с какого момента, номер сервера или базы"></textarea></label>' . $upload
    . '<button class="btn">Отправить</button></form><p class="muted">Срочно? Звоните: <a href="tel:+78129286884">8 (812) 928-68-84</a>.</p></div>';
lk_page('Обращения', 'support', $body, $u);
