<?php
/* Сотрудники ИНФОТЕХ (вход в /lk/admin/) и внутренний чат.
   Первый вход логином из app/config.php (admin_user / admin_password_hash) создаёт учётку администратора;
   config остаётся аварийным способом восстановить пароль администратора. Данные сотрудников и переписка — только в SQLite на сервере. */
declare(strict_types=1);

const STAFF_ROLES = ['admin' => 'Администратор', 'manager' => 'Менеджер'];
const STAFF_FILES = APP_DIR . '/data/staff-files';

function staff_db(): PDO {
    static $done = false;
    $pdo = lk_db();
    if ($done) return $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff (
        id INTEGER PRIMARY KEY AUTOINCREMENT, login TEXT UNIQUE NOT NULL, pass_hash TEXT, name TEXT, position TEXT, phone TEXT, birthday TEXT,
        email TEXT, role TEXT DEFAULT 'manager', active INTEGER DEFAULT 1, created_at TEXT, last_login TEXT, last_seen TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_msgs (id INTEGER PRIMARY KEY AUTOINCREMENT, room TEXT NOT NULL, author_id INTEGER, text TEXT,
        file TEXT, fname TEXT, mime TEXT, size INTEGER, created_at TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS staff_msgs_room ON staff_msgs(room, id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_reads (staff_id INTEGER, room TEXT, last_id INTEGER, PRIMARY KEY(staff_id, room))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_codes (staff_id INTEGER, kind TEXT, target TEXT, code_hash TEXT, until INTEGER, tries INTEGER DEFAULT 0, sent_at INTEGER, PRIMARY KEY(staff_id, kind))");
    $cols = array_column($pdo->query('PRAGMA table_info(staff)')->fetchAll(), 'name');
    foreach (['email_verified' => 'TEXT', 'phone_verified' => 'TEXT'] as $c => $t) if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE staff ADD COLUMN $c $t");
    $done = true;
    return $pdo;
}

/* ---------- вход ---------- */
function staff_by_login(string $login): ?array {
    $st = staff_db()->prepare('SELECT * FROM staff WHERE login=?'); $st->execute([$login]);
    return $st->fetch() ?: null;
}
function staff_get(int $id): ?array {
    $st = staff_db()->prepare('SELECT * FROM staff WHERE id=?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}
function staff_auth(string $login, string $pass): ?array {
    $pdo = staff_db(); $now = date('Y-m-d H:i:s');
    $s = staff_by_login($login);
    if ($s && $s['active'] && $s['pass_hash'] && password_verify($pass, $s['pass_hash'])) {
        if (password_needs_rehash($s['pass_hash'], PASSWORD_DEFAULT)) $pdo->prepare('UPDATE staff SET pass_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $s['id']]);
        return $s;
    }
    // администратор из config.php: первый вход создаёт учётку; новый пароль в config — восстановление доступа
    $cfgHash = (string)cfg('admin_password_hash');
    if ($cfgHash && hash_equals((string)cfg('admin_user'), $login) && password_verify($pass, $cfgHash)) {
        if (!$s) {
            $pdo->prepare("INSERT INTO staff (login, pass_hash, name, role, created_at) VALUES (?,?,?,'admin',?)")->execute([$login, $cfgHash, 'Администратор', $now]);
            return staff_by_login($login);
        }
        $pdo->prepare("UPDATE staff SET pass_hash=?, active=1, role='admin' WHERE id=?")->execute([$cfgHash, $s['id']]);
        return staff_by_login($login);
    }
    return null;
}
function staff_me(): ?array {
    lk_session();
    if (empty($_SESSION['mgr'])) return null;
    static $me = false;
    if ($me !== false) return $me;
    $me = !empty($_SESSION['staff']) ? staff_get((int)$_SESSION['staff']) : null;
    if ($me && !$me['active']) { unset($_SESSION['mgr'], $_SESSION['staff']); $me = null; }
    return $me;
}
function staff_is_admin(): bool { $m = staff_me(); return $m && $m['role'] === 'admin'; }
function mgr_name(): string { $m = staff_me(); return $m ? (string)($m['name'] ?: $m['login']) : 'менеджер'; }
function staff_short(array $s): string {   // Важенин Александр Николаевич → Важенин А. Н.
    $p = preg_split('/\s+/u', trim((string)$s['name']));
    if (count($p) >= 2) return $p[0] . ' ' . implode(' ', array_map(fn($x) => mb_substr($x, 0, 1) . '.', array_slice($p, 1, 2)));
    return (string)($s['name'] ?: $s['login']);
}
function staff_list(bool $activeOnly = true): array {
    return staff_db()->query('SELECT * FROM staff' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY active DESC, name, login')->fetchAll();
}
function staff_bday_text(?string $d): string {
    if (!$d) return '';
    $m = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    return (int)substr($d, 8, 2) . ' ' . $m[(int)substr($d, 5, 2) - 1];
}
/* дни рождения в ближайшие $days дней (0 — сегодня) */
function staff_birthdays(int $days = 7): array {
    $out = []; $today = strtotime(date('Y-m-d'));
    foreach (staff_list() as $s) {
        if (!$s['birthday']) continue;
        $md = substr($s['birthday'], 5);
        if ($md === '02-29' && !checkdate(2, 29, (int)date('Y'))) $md = '02-28';
        $t = strtotime(date('Y') . '-' . $md);
        if ($t < $today) $t = strtotime((date('Y') + 1) . '-' . $md);
        $in = (int)round(($t - $today) / 86400);
        if ($in <= $days) $out[] = ['s' => $s, 'in' => $in];
    }
    usort($out, fn($a, $b) => $a['in'] <=> $b['in']);
    return $out;
}

/* ---------- чат ---------- */
function chat_dm(int $a, int $b): string { return 'dm:' . min($a, $b) . ':' . max($a, $b); }
function chat_can(string $room, int $me): bool {
    if ($room === 'general') return true;
    if (!preg_match('/^dm:(\d+):(\d+)$/', $room, $m)) return false;
    return (int)$m[1] === $me || (int)$m[2] === $me;
}
function chat_rooms(array $me): array {   // [room => [title, sub, unread, last]]
    $pdo = staff_db(); $rooms = ['general' => ['Общий чат', 'все сотрудники', null]];
    foreach (staff_list() as $s) if ($s['id'] != $me['id']) $rooms[chat_dm((int)$me['id'], (int)$s['id'])] = [staff_short($s), $s['position'] ?: STAFF_ROLES[$s['role']] ?? '', $s];
    $out = [];
    foreach ($rooms as $r => [$t, $sub, $who]) {
        $st = $pdo->prepare('SELECT COALESCE(last_id,0) FROM staff_reads WHERE staff_id=? AND room=?'); $st->execute([$me['id'], $r]); $read = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM staff_msgs WHERE room=? AND id>? AND author_id<>?'); $st->execute([$r, $read, $me['id']]); $unread = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT MAX(id) FROM staff_msgs WHERE room=?'); $st->execute([$r]);
        $out[$r] = ['title' => $t, 'sub' => $sub, 'unread' => $unread, 'last' => (int)$st->fetchColumn(), 'who' => $who];
    }
    return $out;
}
function chat_unread_total(array $me): int { return array_sum(array_column(chat_rooms($me), 'unread')); }
function chat_messages(string $room, int $after = 0, int $limit = 200): array {
    $st = staff_db()->prepare("SELECT m.*, s.name, s.login FROM staff_msgs m LEFT JOIN staff s ON s.id=m.author_id WHERE m.room=? AND m.id>? ORDER BY m.id DESC LIMIT $limit");
    $st->execute([$room, $after]);
    return array_reverse($st->fetchAll());
}
function chat_mark_read(int $me, string $room): void {
    $pdo = staff_db();
    $st = $pdo->prepare('SELECT MAX(id) FROM staff_msgs WHERE room=?'); $st->execute([$room]); $last = (int)$st->fetchColumn();
    $pdo->prepare('INSERT INTO staff_reads (staff_id, room, last_id) VALUES (?,?,?) ON CONFLICT(staff_id, room) DO UPDATE SET last_id=excluded.last_id')->execute([$me, $room, $last]);
}
function chat_post(array $me, string $room, string $text, ?array $f = null): int {
    $text = clean($text, 4000);
    $file = $fname = $mime = null; $size = 0;
    if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загрузился');
        if ($f['size'] > 20 * 1048576) throw new RuntimeException('Файл больше 20 МБ');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: 'application/octet-stream';
        if (preg_match('#^(text/html|application/x-(php|httpd|sh|executable|msdownload))#', $mime)) throw new RuntimeException('Такой тип файла нельзя отправить');
        $fname = mb_substr(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u', '_', (string)$f['name']), 0, 150) ?: 'file';
        $dir = STAFF_FILES . '/' . date('Y-m');
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $file = date('Y-m') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(6));
        if (!move_uploaded_file($f['tmp_name'], STAFF_FILES . '/' . $file)) throw new RuntimeException('Не удалось сохранить файл');
        $size = (int)$f['size'];
    }
    if ($text === '' && !$file) throw new RuntimeException('Пустое сообщение');
    staff_db()->prepare('INSERT INTO staff_msgs (room, author_id, text, file, fname, mime, size, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$room, $me['id'], $text, $file, $fname, $mime, $size, date('Y-m-d H:i:s')]);
    $id = (int)staff_db()->lastInsertId();
    chat_mark_read((int)$me['id'], $room);
    return $id;
}
function chat_msg_json(array $m, int $me): array {
    $t = h((string)$m['text']);
    $t = preg_replace('~(https?://[^\s<]+)~u', '<a href="$1" target="_blank" rel="noopener">$1</a>', $t);
    $t = preg_replace('~\b(ИТ-\d{6}-\d+)\b~u', '<a href="/lk/admin/?n=$1">$1</a>', $t);   // номера заказов — ссылкой
    return ['id' => (int)$m['id'], 'mine' => (int)$m['author_id'] === $me, 'author' => $m['name'] ?: ($m['login'] ?: 'сотрудник'),
            'time' => date('d.m H:i', strtotime($m['created_at'])), 'html' => nl2br($t),
            'file' => $m['file'] ? ['url' => '/lk/admin/?chat_file=' . $m['id'], 'name' => $m['fname'], 'size' => (int)$m['size'], 'img' => str_starts_with((string)$m['mime'], 'image/')] : null];
}

/* ---------- обязательный профиль и подтверждение контактов ---------- */
function sms_configured(): bool { $c = cfg('sms'); return is_array($c) && !empty($c['api_id']); }
function sms_send(string $phone, string $text): bool {   // SMS.ru: https://sms.ru/api/send
    $c = cfg('sms'); $to = preg_replace('/\D/', '', $phone);
    $ch = curl_init('https://sms.ru/sms/send?' . http_build_query(['api_id' => $c['api_id'], 'to' => $to, 'msg' => $text, 'json' => 1] + (!empty($c['from']) ? ['from' => $c['from']] : [])));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
    return ($r['status'] ?? '') === 'OK' && (($r['sms'][$to]['status'] ?? '') === 'OK');
}
/* чего не хватает в профиле: [] — можно работать */
function staff_missing(array $s): array {
    $m = [];
    foreach (['name' => 'ФИО', 'position' => 'должность', 'phone' => 'телефон', 'birthday' => 'дата рождения', 'email' => 'e-mail'] as $k => $t) if (trim((string)$s[$k]) === '') $m[] = $t;
    if ($s['email'] && !$s['email_verified']) $m[] = 'подтверждение e-mail';
    if ($s['phone'] && !$s['phone_verified'] && sms_configured()) $m[] = 'подтверждение телефона';
    return $m;
}
function staff_send_code(array $s, string $kind): string {   // kind: email | phone
    $pdo = staff_db(); $target = (string)$s[$kind];
    if ($target === '') throw new RuntimeException('Сначала укажите и сохраните ' . ($kind === 'email' ? 'e-mail' : 'телефон'));
    $st = $pdo->prepare('SELECT sent_at FROM staff_codes WHERE staff_id=? AND kind=?'); $st->execute([$s['id'], $kind]);
    if (($last = (int)$st->fetchColumn()) && time() - $last < 60) throw new RuntimeException('Код уже отправлен, повторить можно через минуту');
    $code = (string)random_int(100000, 999999);
    if ($kind === 'email') {
        if (!send_mail($target, 'Код подтверждения e-mail — ИНФОТЕХ', "Код для подтверждения e-mail в кабинете сотрудника ИНФОТЕХ: $code\nКод действует 15 минут. Если вы его не запрашивали, просто удалите письмо."))
            throw new RuntimeException('Письмо не отправилось: почта сайта не настроена. Администратор может подтвердить вручную на сервере: php app/cli-staff.php verify ' . $s['login']);
    } else {
        if (!sms_configured()) throw new RuntimeException('SMS-сервис не подключён');
        if (!sms_send($target, "ИНФОТЕХ: код подтверждения $code")) throw new RuntimeException('SMS не отправилось, попробуйте позже');
    }
    $pdo->prepare('INSERT OR REPLACE INTO staff_codes (staff_id, kind, target, code_hash, until, tries, sent_at) VALUES (?,?,?,?,?,0,?)')
        ->execute([$s['id'], $kind, $target, password_hash($code, PASSWORD_DEFAULT), time() + 900, time()]);
    return $kind === 'email' ? "Код отправлен на $target" : "Код отправлен по SMS на $target";
}
function staff_check_code(array $s, string $kind, string $code): void {
    $pdo = staff_db();
    $st = $pdo->prepare('SELECT * FROM staff_codes WHERE staff_id=? AND kind=?'); $st->execute([$s['id'], $kind]); $c = $st->fetch();
    if (!$c || $c['until'] < time() || $c['target'] !== (string)$s[$kind]) throw new RuntimeException('Код устарел — запросите новый');
    if ($c['tries'] >= 5) throw new RuntimeException('Слишком много попыток — запросите новый код');
    if (!password_verify(preg_replace('/\D/', '', $code), $c['code_hash'])) {
        $pdo->prepare('UPDATE staff_codes SET tries=tries+1 WHERE staff_id=? AND kind=?')->execute([$s['id'], $kind]);
        throw new RuntimeException('Неверный код');
    }
    $pdo->prepare("UPDATE staff SET {$kind}_verified=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $s['id']]);
    $pdo->prepare('DELETE FROM staff_codes WHERE staff_id=? AND kind=?')->execute([$s['id'], $kind]);
}
