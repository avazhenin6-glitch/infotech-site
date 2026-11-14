<?php
/* Обращения в поддержку (этап 1: заявка менеджеру; полноценные тикеты с перепиской — этап 2) */
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
$u = lk_require(); $uid = (int)$u['id']; $pdo = lk_db();
const TOPICS = ['Сервер / аренда', '1С', 'Гарантия и оборудование', 'Заказ и документы', 'Другое'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lk_check_csrf();
    $topic = in_array($_POST['topic'] ?? '', TOPICS, true) ? $_POST['topic'] : 'Другое';
    $text = clean($_POST['text'] ?? '', 4000);
    if (mb_strlen($text) < 5) lk_flash('Опишите вопрос', 'bad');
    elseif (lk_throttle('ticket', 10, 3600)) lk_flash('Слишком много обращений за час', 'bad');
    else {
        $pdo->prepare("INSERT INTO orders (type, created_at, name, email, phone, comment, user_id, ip) VALUES ('lead',?,?,?,?,?,?,?)")
            ->execute([date('Y-m-d H:i:s'), $u['name'], $u['email'], $u['phone'], "Обращение из ЛК — $topic\n\n$text", $uid, client_ip()]);
        $id = (int)$pdo->lastInsertId(); $num = 'О-' . date('ymd') . '-' . $id;
        $pdo->prepare('UPDATE orders SET number=? WHERE id=?')->execute([$num, $id]);
        lk_notify_manager("Обращение $num: $topic", "Клиент: {$u['name']} <{$u['email']}>, {$u['phone']}\nТема: $topic\n\n$text");
        send_telegram("Обращение $num из личного кабинета: $topic");  // без персональных данных
        lk_flash("Обращение $num отправлено. Ответим на {$u['email']} или по телефону.");
    }
    lk_redirect('/lk/support/');
}
$st = $pdo->prepare("SELECT * FROM orders WHERE user_id=? AND type='lead' ORDER BY id DESC LIMIT 30"); $st->execute([$uid]);
$rows = '';
foreach ($st->fetchAll() as $t)
    $rows .= '<tr><td data-l="Номер">' . h($t['number']) . '</td><td data-l="Дата">' . lk_date($t['created_at'], true) . '</td><td data-l="Тема">' . h(preg_replace('/^Обращение из ЛК — /u', '', strtok((string)$t['comment'], "\n"))) . '</td><td data-l="Статус">' . lk_badge($t['status']) . '</td></tr>';
$opts = implode('', array_map(fn($t) => '<option>' . h($t) . '</option>', TOPICS));
$body = '<h1>Обращения</h1>' . lk_trace() . '<div class="card cut"><h2>Новое обращение</h2><form method="post">' . lk_csrf_field()
    . '<label>Тема<select name="topic">' . $opts . '</select></label><label>Вопрос<textarea name="text" rows="5" required placeholder="Опишите задачу или проблему"></textarea></label><button class="btn">Отправить</button></form>'
    . '<p class="muted">Срочно? Звоните: <a href="tel:+78129286884">8 (812) 928-68-84</a>.</p></div>'
    . ($rows ? '<div class="card cut"><h2>Мои обращения</h2><table class="tbl cards"><thead><tr><th>Номер</th><th>Дата</th><th>Тема</th><th>Статус</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '');
lk_page('Обращения', 'support', $body, $u);
