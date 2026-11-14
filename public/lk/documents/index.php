<?php
/* Архив документов: счета, УПД, акты, договоры; запрос акта сверки */
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
$u = lk_require(); $uid = (int)$u['id'];
$pdo = lk_db();
$companies = lk_companies($uid);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'sverka') {
    lk_check_csrf();
    $cid = (int)($_POST['company'] ?? 0); $from = (string)($_POST['from'] ?? ''); $to = (string)($_POST['to'] ?? '');
    $c = array_values(array_filter($companies, fn($x) => (int)$x['id'] === $cid))[0] ?? null;
    if (!$c || !preg_match('/^\d{4}-\d\d-\d\d$/', $from) || !preg_match('/^\d{4}-\d\d-\d\d$/', $to)) lk_flash('Выберите организацию и период', 'bad');
    else {
        lk_notify_manager('Запрос акта сверки', "Клиент {$u['email']} запросил акт сверки.\nОрганизация: {$c['name']}, ИНН {$c['inn']}\nПериод: " . lk_date($from) . ' — ' . lk_date($to)
            . "\nСформируйте акт в 1С и прикрепите его в кабинете клиента.");
        lk_flash('Запрос отправлен. Акт сверки появится в документах и придёт на почту.');
    }
    lk_redirect('/lk/documents/');
}
$from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? ''); $cf = (int)($_GET['company'] ?? 0); $kf = (string)($_GET['kind'] ?? '');
$sql = 'SELECT d.*, o.number AS order_number, o.company_id, o.company FROM documents d JOIN orders o ON o.id=d.order_id WHERE d.user_id=?'; $args = [$uid];
if (preg_match('/^\d{4}-\d\d-\d\d$/', $from)) { $sql .= ' AND d.doc_date>=?'; $args[] = $from; }
if (preg_match('/^\d{4}-\d\d-\d\d$/', $to)) { $sql .= ' AND d.doc_date<=?'; $args[] = $to; }
if ($cf) { $sql .= ' AND o.company_id=?'; $args[] = $cf; }
if (isset(LK_DOC_KINDS[$kf])) { $sql .= ' AND d.kind=?'; $args[] = $kf; }
$st = $pdo->prepare($sql . ' ORDER BY d.doc_date DESC, d.id DESC'); $st->execute($args);
$rows = '';
foreach ($st->fetchAll() as $d)
    $rows .= '<tr><td data-l="Документ">' . h(LK_DOC_KINDS[$d['kind']] ?? 'Документ') . ($d['number'] ? ' № ' . h($d['number']) : '') . '</td><td data-l="Дата">' . lk_date($d['doc_date']) . '</td>'
        . '<td data-l="Заказ"><a href="/lk/orders/?n=' . urlencode($d['order_number']) . '">' . h($d['order_number']) . '</a></td><td data-l="Организация">' . h($d['company'] ?: 'Физлицо') . '</td>'
        . '<td>' . ($d['blocked'] ? '<span class="muted">недействителен</span>' : '<a class="btn small" href="/lk/file.php?id=' . $d['id'] . '">Скачать</a>') . '</td></tr>';
$opt = fn($sel) => '<option value="0">Все организации</option>' . implode('', array_map(fn($c) => '<option value="' . $c['id'] . '"' . ((int)$c['id'] === $sel ? ' selected' : '') . '>' . h($c['name'] . ' (ИНН ' . $c['inn'] . ')') . '</option>', $companies));
$kinds = '<option value="">Все документы</option>' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($k === $kf ? ' selected' : '') . '>' . $v . '</option>', array_keys(LK_DOC_KINDS), LK_DOC_KINDS));
$body = '<h1>Документы</h1>' . lk_trace()
    . '<form class="filters card cut" method="get"><label>С<input type="date" name="from" value="' . h($from) . '"></label><label>По<input type="date" name="to" value="' . h($to) . '"></label>'
    . '<label>Организация<select name="company">' . $opt($cf) . '</select></label><label>Тип<select name="kind">' . $kinds . '</select></label><button class="btn">Показать</button></form>'
    . '<div class="card cut">' . ($rows ? '<table class="tbl cards"><thead><tr><th>Документ</th><th>Дата</th><th>Заказ</th><th>Организация</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>' : '<p class="muted">Документов за выбранный период нет.</p>') . '</div>'
    . '<div class="card cut"><h2>Запросить акт сверки</h2>' . ($companies
        ? '<form method="post" class="filters">' . lk_csrf_field() . '<input type="hidden" name="act" value="sverka"><label>Организация<select name="company" required>' . substr($opt(0), strlen('<option value="0">Все организации</option>')) . '</select></label>'
          . '<label>С<input type="date" name="from" required></label><label>По<input type="date" name="to" value="' . date('Y-m-d') . '" required></label><button class="btn">Запросить</button></form>'
        : '<p class="muted">Добавьте организацию в разделе <a href="/lk/profile/">«Профиль и организации»</a>.</p>') . '</div>';
lk_page('Документы', 'documents', $body, $u);
