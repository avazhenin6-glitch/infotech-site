<?php
/* Личный кабинет клиента pbk.spb.ru/lk/ — общая часть: БД, сессия, вход, заказы, документы, оформление. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const LK_FILES = APP_DIR . '/data/lk-files';
const LK_SITE = 'https://pbk.spb.ru';
/* статусы заказа: код => [название, цвет бейджа] */
const LK_ST = [
    'Новый'               => ['Новый', 'wait'],
    'Счёт выставлен'      => ['Ожидает оплаты', 'wait'],
    'Платёж на проверке'  => ['Платёж на проверке', 'wait'],
    'Частично оплачен'    => ['Частично оплачен', 'wait'],
    'Оплачен'             => ['Оплачен', 'ok'],
    'В работе'            => ['В работе', 'wait'],
    'Выполнен'            => ['Выполнен', 'ok'],
    'Отменён'             => ['Отменён', 'bad'],
    'Срок резерва истёк'  => ['Срок резерва истёк', 'bad'],
];
const LK_FILTERS = [
    'all' => ['Все', null], 'wait' => ['Ожидают оплаты', ['Новый', 'Счёт выставлен', 'Частично оплачен']], 'check' => ['Платёж на проверке', ['Платёж на проверке']],
    'work' => ['В работе', ['Оплачен', 'В работе']], 'done' => ['Выполненные', ['Выполнен']], 'cancel' => ['Отменённые', ['Отменён', 'Срок резерва истёк']],
];
const LK_DOC_KINDS = ['invoice' => 'Счёт', 'upd' => 'УПД', 'act' => 'Акт', 'contract' => 'Договор', 'kp' => 'КП', 'payment' => 'Платёжное поручение', 'other' => 'Документ'];
const LK_KEYS_VISIBLE = ['Оплачен', 'В работе', 'Выполнен'];

/* ---------------- база ---------------- */
function lk_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, name TEXT, phone TEXT, pass_hash TEXT,
        verified INTEGER DEFAULT 0, created_at TEXT, last_login TEXT, cart_json TEXT, cart_updated TEXT, manager TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_companies (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, inn TEXT, kpp TEXT, ogrn TEXT, name TEXT, address TEXT, created_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS otp (email TEXT, code_hash TEXT, purpose TEXT, until INTEGER, tries INTEGER DEFAULT 0, payload TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, user_id INTEGER, kind TEXT, title TEXT, number TEXT, doc_date TEXT,
        file TEXT, mime TEXT, size INTEGER, blocked INTEGER DEFAULT 0, uploaded_by TEXT, created_at TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS documents_user ON documents(user_id)");
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    foreach (['user_id' => 'INTEGER', 'company_id' => 'INTEGER', 'valid_until' => 'TEXT', 'keys_text' => 'TEXT', 'has_hw' => 'INTEGER DEFAULT 0', 'updated_at' => 'TEXT'] as $c => $t)
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN $c $t");
    $pdo->exec("CREATE INDEX IF NOT EXISTS orders_user ON orders(user_id)");
    return $pdo;
}

/* ---------------- сессия и безопасность ---------------- */
function lk_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('pbklk');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
}
function lk_csrf(): string { lk_session(); return $_SESSION['csrf']; }
function lk_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . lk_csrf() . '">'; }
function lk_check_csrf(): void {
    if (!hash_equals(lk_csrf(), (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? ''))) { http_response_code(403); exit('Сессия устарела — обновите страницу.'); }
}
function lk_user(): ?array {
    lk_session();
    $uid = !empty($_SESSION['mgr']) && !empty($_SESSION['as']) ? (int)$_SESSION['as'] : (int)($_SESSION['uid'] ?? 0);  // менеджер смотрит кабинет клиента
    if (!$uid) return null;
    static $u = false;
    if ($u === false) {
        $st = lk_db()->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$uid]);
        $u = $st->fetch() ?: null;
    }
    return $u;
}
function lk_require(): array {
    $u = lk_user();
    if (!$u) { header('Location: /lk/login/?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/lk/')); exit; }
    return $u;
}
function lk_login(int $uid): void {
    lk_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    lk_db()->prepare('UPDATE users SET last_login=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $uid]);
    setcookie('pbk_lk', '1', ['expires' => time() + 86400 * 30, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);  // только для кнопки в шапке
}
function lk_logout(): void {
    lk_session(); $_SESSION = []; session_destroy();
    setcookie('pbk_lk', '', ['expires' => 1, 'path' => '/']);
}
/* ограничение частоты: не больше $max событий $key за $win секунд с одного IP */
function lk_throttle(string $key, int $max, int $win): bool {
    $pdo = lk_db(); $k = $key . ':' . client_ip(); $now = time();
    $pdo->prepare('DELETE FROM hits WHERE at < ?')->execute([$now - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE ip=? AND at > ?'); $st->execute([$k, $now - $win]);
    if ((int)$st->fetchColumn() >= $max) return true;
    $pdo->prepare('INSERT INTO hits (ip, at) VALUES (?,?)')->execute([$k, $now]);
    return false;
}
function lk_hash(string $pass): string {
    return password_hash($pass, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
}
function lk_user_by_email(string $email): ?array {
    $st = lk_db()->prepare('SELECT * FROM users WHERE email=?'); $st->execute([mb_strtolower(trim($email))]);
    return $st->fetch() ?: null;
}

/* ---------------- одноразовые коды на e-mail ---------------- */
function lk_send_code(string $email, string $purpose, array $payload = []): bool {
    $email = mb_strtolower(trim($email));
    $code = (string)random_int(100000, 999999);
    $pdo = lk_db();
    $pdo->prepare('DELETE FROM otp WHERE email=? OR until < ?')->execute([$email, time()]);
    $pdo->prepare('INSERT INTO otp (email, code_hash, purpose, until, payload) VALUES (?,?,?,?,?)')
        ->execute([$email, password_hash($code, PASSWORD_BCRYPT), $purpose, time() + 600, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $what = $purpose === 'register' ? 'подтверждения регистрации' : 'входа';
    return send_mail($email, "Код $what: $code — ИНФОТЕХ",
        "Ваш код $what в личный кабинет ИНФОТЕХ: $code\n\nКод действует 10 минут. Если вы не запрашивали код — просто проигнорируйте это письмо.\n\n" . cfg('site_name') . "\n" . LK_SITE);
}
/* возвращает payload при верном коде, null — при неверном */
function lk_check_code(string $email, string $code, string $purpose): ?array {
    $email = mb_strtolower(trim($email)); $pdo = lk_db();
    $st = $pdo->prepare('SELECT rowid, * FROM otp WHERE email=? AND purpose=? AND until>=?'); $st->execute([$email, $purpose, time()]);
    $row = $st->fetch();
    if (!$row || $row['tries'] >= 5) return null;
    if (!password_verify(preg_replace('/\D/', '', $code), $row['code_hash'])) {
        $pdo->prepare('UPDATE otp SET tries=tries+1 WHERE rowid=?')->execute([$row['rowid']]);
        return null;
    }
    $pdo->prepare('DELETE FROM otp WHERE email=?')->execute([$email]);
    return json_decode((string)$row['payload'], true) ?: [];
}

/* ---------------- заказы ---------------- */
function lk_items(array $o): array { return json_decode((string)$o['items_json'], true) ?: []; }
function lk_order_for_user(string $number, int $uid): ?array {
    $st = lk_db()->prepare("SELECT * FROM orders WHERE number=? AND user_id=? AND type='order'"); $st->execute([$number, $uid]);
    $o = $st->fetch();
    return $o ? lk_apply_reserve($o) : null;
}
/* +N рабочих дней (сб/вс пропускаем; праздники РФ не учитываются) — до конца дня */
function lk_add_workdays(string $from, int $n): string {
    return wc_add_workdays($from, $n) . ' 23:59:59';   // с учётом праздников РФ (app/workcal.php)
}
/* резерв по оборудованию: неоплаченный счёт старше 3 рабочих дней — «Срок резерва истёк» */
function lk_apply_reserve(array $o): array {
    if ($o['status'] === 'Счёт выставлен' && $o['has_hw'] && $o['valid_until'] && $o['valid_until'] < date('Y-m-d H:i:s')) {
        $pdo = lk_db();
        $pdo->prepare("UPDATE orders SET status='Срок резерва истёк', updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $o['id']]);
        $pdo->prepare("UPDATE documents SET blocked=1 WHERE order_id=? AND kind='invoice'")->execute([$o['id']]);
        $o['status'] = 'Срок резерва истёк';
    }
    return $o;
}
function lk_is_hw_item(array $it): bool {
    $p = catalog()[$it['code']] ?? null;
    return str_starts_with((string)$it['code'], 'PBK-') || ($p && preg_match('/оборудование|комплектующие/ui', (string)$p['cat']));
}
/* привязка нового заказа к аккаунту (создаёт аккаунт по e-mail, если его нет) */
function lk_attach_order(int $orderId, string $email, string $name, string $phone, string $inn, string $company): array {
    $pdo = lk_db();
    lk_session();
    $uid = (int)($_SESSION['uid'] ?? 0);
    $created = false;
    if (!$uid) {
        $u = lk_user_by_email($email);
        if ($u) $uid = (int)$u['id'];
        else {
            $pdo->prepare('INSERT INTO users (email, name, phone, created_at) VALUES (?,?,?,?)')
                ->execute([mb_strtolower(trim($email)), $name, $phone, date('Y-m-d H:i:s')]);
            $uid = (int)$pdo->lastInsertId(); $created = true;
        }
    }
    $companyId = null;
    if ($inn !== '') {
        $st = $pdo->prepare('SELECT id FROM user_companies WHERE user_id=? AND inn=?'); $st->execute([$uid, $inn]);
        $companyId = $st->fetchColumn() ?: null;
        if (!$companyId) {
            $pdo->prepare('INSERT INTO user_companies (user_id, inn, name, created_at) VALUES (?,?,?,?)')->execute([$uid, $inn, $company, date('Y-m-d H:i:s')]);
            $companyId = (int)$pdo->lastInsertId();
        }
    }
    $st = $pdo->prepare('SELECT items_json FROM orders WHERE id=?'); $st->execute([$orderId]);
    $hw = array_filter(json_decode((string)$st->fetchColumn(), true) ?: [], 'lk_is_hw_item') ? 1 : 0;
    $pdo->prepare('UPDATE orders SET user_id=?, company_id=?, has_hw=?, updated_at=? WHERE id=?')->execute([$uid, $companyId, $hw, date('Y-m-d H:i:s'), $orderId]);
    return ['user_id' => $uid, 'created' => $created];
}

/* ---------------- файлы и документы ---------------- */
function lk_store_upload(array $f, int $orderId, array $allow = ['application/pdf', 'image/jpeg', 'image/png'], int $maxMb = 10): array {
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загрузился');
    if ($f['size'] > $maxMb * 1048576) throw new RuntimeException("Файл больше $maxMb МБ");
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, $allow, true)) throw new RuntimeException('Допустимы файлы PDF, JPG или PNG');
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime];
    $dir = LK_FILES . '/' . $orderId;
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new RuntimeException('Не удалось сохранить файл');
    return ['file' => "$orderId/$name", 'mime' => $mime, 'size' => (int)$f['size']];
}
function lk_add_document(array $o, string $kind, array $stored, string $title, string $number = '', string $date = '', string $by = 'client'): int {
    $pdo = lk_db();
    $pdo->prepare('INSERT INTO documents (order_id, user_id, kind, title, number, doc_date, file, mime, size, uploaded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$o['id'], $o['user_id'], $kind, $title, $number, $date ?: date('Y-m-d'), $stored['file'], $stored['mime'], $stored['size'], $by, date('Y-m-d H:i:s')]);
    return (int)$pdo->lastInsertId();
}
function lk_docs_for_order(int $orderId): array {
    $st = lk_db()->prepare('SELECT * FROM documents WHERE order_id=? ORDER BY id DESC'); $st->execute([$orderId]);
    return $st->fetchAll();
}

/* ---------------- письмо с вложением ---------------- */
function send_mail_attach(string $to, string $subject, string $text, string $path, string $fname, string $mime): bool {
    if (cfg('mail_transport') === 'none' || !$to) return false;
    $b = 'b' . bin2hex(random_bytes(8));
    $from = cfg('mail_from'); $fromName = cfg('mail_from_name');
    $headers = 'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$from>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n";
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$b\r\nContent-Type: $mime; name=\"=?UTF-8?B?" . base64_encode($fname) . "?=\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"=?UTF-8?B?" . base64_encode($fname) . "?=\"\r\n\r\n"
          . chunk_split(base64_encode((string)file_get_contents($path))) . "--$b--\r\n";
    $encSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (cfg('mail_transport') === 'mail') return @mail($to, $encSubj, $body, $headers);
    try { return smtp_send($to, $encSubj, $headers, $body); } catch (Throwable $e) { error_log('SMTP: ' . $e->getMessage()); return false; }
}

/* ---------------- оформление ---------------- */
function lk_badge(string $status): string {
    [$label, $cls] = LK_ST[$status] ?? [$status, 'wait'];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}
function lk_date(?string $d, bool $time = false): string {
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date($time ? 'd.m.Y H:i' : 'd.m.Y', $t) : h($d);
}
function lk_flash(?string $msg = null, string $kind = 'ok'): string {
    lk_session();
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $kind]; return ''; }
    if (empty($_SESSION['flash'])) return '';
    [$m, $k] = $_SESSION['flash']; unset($_SESSION['flash']);
    return '<div class="flash ' . $k . '">' . h($m) . '</div>';
}
function lk_redirect(string $to): never { header('Location: ' . $to); exit; }

const LK_NAV = [
    'dash' => ['/lk/', 'Обзор', 'M3 12l9-8 9 8M5 10v10h5v-6h4v6h5V10'],
    'orders' => ['/lk/orders/', 'Мои заказы', 'M4 6h16l-2 9H6L4 6zm0 0L3 3H1m6 17a1 1 0 100-2 1 1 0 000 2zm10 0a1 1 0 100-2 1 1 0 000 2z'],
    'services' => ['/lk/services/', 'Серверы и 1С', 'M4 5h16v5H4zM4 14h16v5H4zM8 7.5h.01M8 16.5h.01'],
    'documents' => ['/lk/documents/', 'Документы', 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6'],
    'support' => ['/lk/support/', 'Обращения', 'M4 5h16v11H8l-4 4V5z'],
    'profile' => ['/lk/profile/', 'Профиль и организации', 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0'],
];
function lk_page(string $title, string $active, string $body, ?array $u = null): never {
    $nav = '';
    foreach (LK_NAV as $k => [$href, $label, $icon])
        $nav .= '<a href="' . $href . '"' . ($k === $active ? ' aria-current="page"' : '') . '><svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $icon . '"/></svg>' . $label . '</a>';
    $who = $u ? '<div class="who"><b>' . h($u['name'] ?: $u['email']) . '</b><span>' . h($u['email']) . '</span></div>' : '';
    $css = '/assets/lk.css?v=' . @filemtime(PUBLIC_DIR . '/assets/lk.css');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . h($title) . ' — Личный кабинет ИНФОТЕХ</title>'
        . '<link rel="icon" href="/favicon.ico"><link rel="stylesheet" href="' . $css . '"></head><body class="lk">'
        . ($u ? '<aside class="side" id="side"><a class="brand" href="/"><img src="/logo-light-s.png" alt="ИНФОТЕХ" height="34"></a>' . $who
            . '<nav>' . $nav . '</nav><div class="side-foot"><a href="/magazin-1c/">← В магазин</a><a href="/lk/login/?logout=1">Выйти</a></div></aside>'
            . '<header class="topbar"><button class="burger" onclick="document.body.classList.toggle(\'menu\')" aria-label="Меню">☰</button><a href="/"><img src="/logo-light-s.png" alt="ИНФОТЕХ" height="28"></a></header>' : '')
        . '<main class="work' . ($u ? '' : ' solo') . '">' . (!empty($_SESSION['as']) && !empty($_SESSION['mgr']) ? '<div class="flash wait">Вы смотрите кабинет клиента ' . h($u['email'] ?? '') . ' как менеджер. <a href="/lk/admin/?stopas=1">Вернуться в кабинет менеджера</a></div>' : '') . lk_flash() . $body . '</main></body></html>';
    exit;
}
function lk_trace(): string {   // декоративная линия печатной платы (брендбук)
    return '<svg class="trace" viewBox="0 0 400 24" preserveAspectRatio="none" aria-hidden="true"><path d="M0 12h150l12-8h80l12 8h146"/><circle cx="150" cy="12" r="3"/><circle cx="254" cy="12" r="3"/></svg>';
}

/* ---------------- товары: фото и гарантия (оборудование от дистрибьюторов) ---------------- */
function lk_product_meta(string $code): array {
    static $img = null;
    if ($img === null) {
        $img = [];
        $raw = @file_get_contents(PUBLIC_DIR . '/supply.js');
        if ($raw && ($p = strpos($raw, 'window.SUPPLY=')) !== false) {
            $d = json_decode(rtrim(substr($raw, $p + 14), ";\n\r "), true);
            foreach (($d['items'] ?? []) as $it) $img[$it[0]] = $it[5] ?? '';
        }
    }
    $w = '';
    if (str_starts_with($code, 'PBK-') && is_file(APP_DIR . '/data/supply.sqlite')) {
        static $s = null;
        $s ??= new PDO('sqlite:' . APP_DIR . '/data/supply.sqlite');
        $st = $s->prepare('SELECT specs FROM products WHERE sku=?'); $st->execute([$code]);
        foreach (json_decode((string)$st->fetchColumn(), true) ?: [] as [$k, $v])
            if (preg_match('/гарант/ui', $k)) { $w = $v; break; }
    }
    return ['img' => $img[$code] ?? '', 'warranty' => $w];
}
function lk_money(array $it, bool $sum = false): string {
    $v = $sum ? (float)$it['sum'] : (float)$it['price'];
    return ($it['cur'] ?? 'RUB') === 'USD' ? money_usd($v) : money_rub($v);
}
function lk_notify_manager(string $subject, string $text): void {
    send_mail((string)cfg('notify_emails'), $subject, $text . "\n\nКабинет менеджера: " . LK_SITE . '/lk/admin/');
}
function lk_companies(int $uid): array {
    $st = lk_db()->prepare('SELECT * FROM user_companies WHERE user_id=? ORDER BY id'); $st->execute([$uid]);
    return $st->fetchAll();
}
function lk_is_manager(): bool { lk_session(); return !empty($_SESSION['mgr']); }

/* DaData: организация по ИНН (ключ — app/config.php 'dadata_token'; без ключа — ручной ввод) */
function lk_dadata_inn(string $inn): ?array {
    $token = (string)cfg('dadata_token');
    if (!$token || !preg_match('/^\d{10}(\d{2})?$/', $inn) || !function_exists('curl_init')) return null;
    $ch = curl_init('https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Token ' . $token],
        CURLOPT_POSTFIELDS => json_encode(['query' => $inn])]);
    $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
    $s = $r['suggestions'][0] ?? null;
    if (!$s) return null;
    $d = $s['data'];
    return ['inn' => $d['inn'] ?? $inn, 'kpp' => $d['kpp'] ?? '', 'ogrn' => $d['ogrn'] ?? '', 'name' => $s['value'] ?? '', 'address' => $d['address']['unrestricted_value'] ?? ($d['address']['value'] ?? '')];
}
require_once __DIR__ . '/lk_services.php';
require_once __DIR__ . '/lk_payments.php';
require_once __DIR__ . '/workcal.php';
require_once __DIR__ . '/mikrotik.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/staff.php';
