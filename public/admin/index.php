<?php
/* Админка заказов и заявок: /admin/ */
declare(strict_types=1);
require __DIR__ . '/../../app/lib.php';

session_name('itadm');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const STATUSES = ['Новый', 'В работе', 'Счёт выставлен', 'Оплачен', 'Выполнен', 'Отменён'];
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$self = strtok($_SERVER['REQUEST_URI'], '?');

/* вход / выход */
if (isset($_GET['logout'])) { session_destroy(); header("Location: $self"); exit; }
$err = '';
if (empty($_SESSION['auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $hash = (string)cfg('admin_password_hash');
        usleep(400000);
        if ($hash && hash_equals((string)cfg('admin_user'), (string)$_POST['user']) && password_verify((string)$_POST['pass'], $hash)) {
            session_regenerate_id(true); $_SESSION['auth'] = 1; header("Location: $self"); exit;
        }
        $err = $hash ? 'Неверный логин или пароль' : 'Пароль админки не задан в app/config.php';
    }
    page('Вход', '<form method="post" class="login card"><h2>Вход в админку</h2>' . ($err ? '<p class="err">' . h($err) . '</p>' : '') .
        '<label>Логин<input name="user" autocomplete="username" required></label><label>Пароль<input type="password" name="pass" autocomplete="current-password" required></label><button name="login" value="1">Войти</button></form>');
}

$pdo = db();

/* действия */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('csrf'); }
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_POST['status']) && in_array($_POST['status'], STATUSES, true))
        $pdo->prepare('UPDATE orders SET status=?, manager_note=? WHERE id=?')->execute([$_POST['status'], clean($_POST['note'] ?? '', 3000), $id]);
    header("Location: $self?id=$id&saved=1"); exit;
}

/* выгрузка CSV */
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Номер', 'Тип', 'Статус', 'Дата', 'Имя', 'E-mail', 'Телефон', 'ИНН', 'Организация', 'Поставка', 'Оплата', 'Сумма ₽', 'Сумма $', 'Состав', 'Комментарий', 'Заметка менеджера'], ';');
    foreach ($pdo->query('SELECT * FROM orders ORDER BY id DESC') as $r) {
        $items = implode(' | ', array_map(fn($i) => "{$i['name']} ({$i['code']}) x{$i['qty']}", json_decode($r['items_json'] ?: '[]', true)));
        fputcsv($out, [$r['number'], $r['type'] === 'order' ? 'Заказ' : 'Заявка', $r['status'], $r['created_at'], $r['name'], $r['email'], $r['phone'], $r['inn'], $r['company'],
            $r['delivery'], $r['payment'], $r['total_rub'], $r['total_usd'], $items, $r['comment'], $r['manager_note']], ';');
    }
    exit;
}

/* карточка */
if (!empty($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM orders WHERE id=?'); $st->execute([(int)$_GET['id']]); $r = $st->fetch();
    if (!$r) page('Не найдено', '<p>Запись не найдена. <a href="' . h($self) . '">К списку</a></p>');
    $items = json_decode($r['items_json'] ?: '[]', true);
    $rows = '';
    foreach ($items as $i) $rows .= '<tr><td>' . h($i['name']) . ($i['e'] ? ' <span class="tag">e-mail</span>' : '') . '</td><td>' . h($i['code']) . '</td><td class="r">' . $i['qty'] . '</td><td class="r">'
        . ($i['cur'] === 'USD' ? money_usd($i['price']) : money_rub($i['price'])) . '</td><td class="r">' . ($i['cur'] === 'USD' ? money_usd($i['sum']) : money_rub($i['sum'])) . '</td></tr>';
    $opts = implode('', array_map(fn($s) => '<option' . ($s === $r['status'] ? ' selected' : '') . '>' . h($s) . '</option>', STATUSES));
    $f = fn($k, $v) => $v !== '' && $v !== null ? '<div class="kv"><span>' . $k . '</span><b>' . nl2br(h((string)$v)) . '</b></div>' : '';
    $html = '<p><a href="' . h($self) . '">← Все заказы и заявки</a></p>' . (isset($_GET['saved']) ? '<p class="ok">Сохранено</p>' : '') .
        '<div class="card"><h2>' . ($r['type'] === 'order' ? 'Заказ ' : 'Заявка ') . h($r['number']) . ' <span class="st">' . h($r['status']) . '</span></h2>' .
        $f('Дата', $r['created_at']) . $f('Клиент', $r['name']) . $f('E-mail', $r['email']) . $f('Телефон', $r['phone']) . $f('ИНН', $r['inn']) . $f('Организация', $r['company']) .
        $f('Поставка', $r['delivery']) . $f('Оплата', $r['payment']) . $f('Комментарий', $r['comment']) .
        ($items ? '<table><tr><th>Наименование</th><th>Артикул</th><th class="r">Кол-во</th><th class="r">Цена</th><th class="r">Сумма</th></tr>' . $rows .
        '<tr><td colspan="4"><b>Итого</b></td><td class="r"><b>' . money_rub((float)$r['total_rub']) . ($r['total_usd'] > 0 ? ' + ' . money_usd((float)$r['total_usd']) : '') . '</b></td></tr></table>' : '') .
        '</div><form method="post" class="card"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '">' .
        '<label>Статус<select name="status">' . $opts . '</select></label><label>Заметка менеджера<textarea name="note" rows="3">' . h($r['manager_note']) . '</textarea></label><button>Сохранить</button></form>';
    page($r['number'], $html);
}

/* список */
$q = clean($_GET['q'] ?? '', 100); $t = $_GET['t'] ?? ''; $s = $_GET['s'] ?? '';
$where = []; $args = [];
if ($t === 'order' || $t === 'lead') { $where[] = 'type=?'; $args[] = $t; }
if (in_array($s, STATUSES, true)) { $where[] = 'status=?'; $args[] = $s; }
if ($q !== '') { $where[] = '(number LIKE ? OR name LIKE ? OR email LIKE ? OR phone LIKE ? OR company LIKE ? OR inn LIKE ?)'; array_push($args, ...array_fill(0, 6, "%$q%")); }
$sql = 'SELECT * FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500';
$st = $pdo->prepare($sql); $st->execute($args);
$rows = '';
foreach ($st as $r) {
    $rows .= '<tr onclick="location=\'?id=' . (int)$r['id'] . '\'"><td><a href="?id=' . (int)$r['id'] . '">' . h($r['number']) . '</a></td><td>' . ($r['type'] === 'order' ? 'Заказ' : 'Заявка') .
        '</td><td>' . h(substr($r['created_at'], 0, 16)) . '</td><td>' . h($r['name']) . '<br><small>' . h($r['company']) . '</small></td><td>' . h($r['phone']) . '<br><small>' . h($r['email']) . '</small></td><td class="r">' .
        ($r['type'] === 'order' ? money_rub((float)$r['total_rub']) . ($r['total_usd'] > 0 ? '<br><small>+ ' . money_usd((float)$r['total_usd']) . '</small>' : '') : '—') . '</td><td><span class="st">' . h($r['status']) . '</span></td></tr>';
}
$sel = fn($name, $cur, $opts) => '<select name="' . $name . '" onchange="this.form.submit()">' . implode('', array_map(fn($k, $v) => '<option value="' . h((string)$k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . h($v) . '</option>', array_keys($opts), $opts)) . '</select>';
$html = '<form class="filters"><input name="q" value="' . h($q) . '" placeholder="Поиск: номер, имя, телефон, ИНН">' .
    $sel('t', $t, ['' => 'Заказы и заявки', 'order' => 'Только заказы', 'lead' => 'Только заявки']) .
    $sel('s', $s, ['' => 'Все статусы'] + array_combine(STATUSES, STATUSES)) . '<button>Найти</button><a class="btn2" href="?csv=1">Выгрузить CSV</a></form>' .
    '<div class="card"><table><tr><th>Номер</th><th>Тип</th><th>Дата</th><th>Клиент</th><th>Контакты</th><th class="r">Сумма</th><th>Статус</th></tr>' . ($rows ?: '<tr><td colspan="7">Пока пусто</td></tr>') . '</table></div>';
page('Заказы и заявки', $html);

function page(string $title, string $body): never {
    $logged = !empty($_SESSION['auth']);
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' — ИНФОТЕХ</title><style>
    body{margin:0;font-family:Inter,system-ui,sans-serif;background:#F5FBFF;color:#001C1B;font-size:14px}
    header{background:#001C1B;color:#F5FBFF;padding:14px 24px;display:flex;justify-content:space-between;align-items:center}
    header a{color:#CEE4F2}main{max-width:1200px;margin:24px auto;padding:0 16px}
    .card{background:#fff;padding:20px;margin-bottom:16px;clip-path:polygon(14px 0,100% 0,100% calc(100% - 14px),calc(100% - 14px) 100%,0 100%,0 14px)}
    table{width:100%;border-collapse:collapse}th,td{padding:9px 8px;border-bottom:1px solid #CEE4F2;text-align:left;vertical-align:top}th{font-weight:500;color:#4B7BA6;font-size:12px}
    tr[onclick]{cursor:pointer}tr[onclick]:hover{background:#eaf4fb}.r{text-align:right;white-space:nowrap}small{color:#6a8397}
    .st{display:inline-block;background:#CEE4F2;padding:2px 8px;font-size:12px;font-weight:500}.tag{background:#001C1B;color:#CEE4F2;font-size:11px;padding:1px 6px}
    input,select,textarea{font:inherit;padding:9px 10px;border:1.5px solid #CEE4F2;background:#fff;box-sizing:border-box}
    label{display:block;margin-bottom:12px;font-weight:500}label input,label select,label textarea{display:block;width:100%;margin-top:6px;font-weight:400}
    button,.btn2{font:inherit;font-weight:500;background:#4B7BA6;color:#F5FBFF;border:0;padding:10px 18px;cursor:pointer;text-decoration:none;display:inline-block}
    .btn2{background:#CEE4F2;color:#001C1B}.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.filters input{flex:1;min-width:220px}
    .kv{display:grid;grid-template-columns:140px 1fr;gap:12px;padding:6px 0;border-bottom:1px solid #eef5fa}.kv span{color:#6a8397}.kv b{font-weight:500}
    .login{max-width:360px;margin:60px auto}.err{color:#b5452f}.ok{color:#2c7a4b;font-weight:500}h2{margin-top:0}
    </style></head><body><header><b>ИНФОТЕХ · заказы и заявки</b>' . ($logged ? '<a href="?logout=1">Выйти</a>' : '') . '</header><main>' . $body . '</main></body></html>';
    exit;
}
