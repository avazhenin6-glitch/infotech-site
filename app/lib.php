<?php
/* Общие функции: настройки, база заказов (SQLite), каталог, почта, Telegram. */
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');
const APP_DIR    = __DIR__;
const PUBLIC_DIR = __DIR__ . '/../public';

function cfg(?string $key = null) {
    static $c = null;
    if ($c === null) {
        $file = APP_DIR . '/config.php';
        if (!is_file($file)) { $file = APP_DIR . '/config.example.php'; }
        $c = require $file;
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = APP_DIR . '/data';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    $pdo = new PDO('sqlite:' . $dir . '/site.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        number TEXT UNIQUE,
        type TEXT NOT NULL,              -- order | lead
        status TEXT NOT NULL DEFAULT 'Новый',
        created_at TEXT NOT NULL,
        name TEXT, email TEXT, phone TEXT, inn TEXT, company TEXT,
        delivery TEXT, payment TEXT, city TEXT, need_install INTEGER DEFAULT 0,
        comment TEXT,
        total_rub REAL DEFAULT 0, total_usd REAL DEFAULT 0,
        items_json TEXT,
        ip TEXT, user_agent TEXT,
        manager_note TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hits (ip TEXT, at INTEGER)");
    return $pdo;
}

/* Каталог читается из того же catalog.js, что и сайт: цены на сервере всегда совпадают с витриной. */
function catalog(): array {
    static $map = null;
    if ($map !== null) return $map;
    $raw = file_get_contents(PUBLIC_DIR . '/catalog.js');
    $pos = strpos($raw, 'window.CATALOG=');
    $json = rtrim(substr($raw, $pos + strlen('window.CATALOG=')), ";\n\r ");
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $map = [];
    foreach ($data['items'] as $it) {
        [$code, $name, $c, $s, $price, $cur, $e] = $it;
        $map[(string)$code] = ['code' => (string)$code, 'name' => $name, 'price' => (float)$price,
            'cur' => $cur === '$' ? 'USD' : 'RUB', 'e' => (int)$e,
            'cat' => $data['cats'][$c]['n'] ?? '', 'sub' => $data['cats'][$c]['s'][$s] ?? ''];
    }
    // оборудование от дистрибьюторов (tools/supply_sync.py): [sku, name, catIdx, price, inStock, img]
    $sraw = @file_get_contents(PUBLIC_DIR . '/supply.js');
    if ($sraw && ($p = strpos($sraw, 'window.SUPPLY=')) !== false) {
        $sd = json_decode(rtrim(substr($sraw, $p + strlen('window.SUPPLY=')), ";\n\r "), true);
        foreach (($sd['items'] ?? []) as $it) {
            [$code, $name, $c, $price, $inStock] = $it;
            $map[(string)$code] = ['code' => (string)$code, 'name' => $name, 'price' => (float)$price, 'cur' => 'RUB', 'e' => 0,
                'cat' => 'Компьютеры и комплектующие', 'sub' => $sd['cats'][$c] ?? '', 'in_stock' => (int)$inStock];
        }
    }
    return $map;
}

function client_ip(): string {
    return $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}

function rate_limited(): bool {
    $ip = client_ip(); $now = time();
    $pdo = db();
    $pdo->prepare('DELETE FROM hits WHERE at < ?')->execute([$now - 600]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE ip = ?'); $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= (int)cfg('rate_limit')) return true;
    $pdo->prepare('INSERT INTO hits (ip, at) VALUES (?, ?)')->execute([$ip, $now]);
    return false;
}

function clean(?string $s, int $max = 500): string {
    $s = trim((string)$s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    return mb_substr($s, 0, $max);
}

function money_rub(float $v): string { return number_format($v, 0, ',', ' ') . ' ₽'; }
function money_usd(float $v): string { return '$' . number_format($v, 2, '.', ','); }
/* телефон: только российские номера, формат +7 (XXX) XXX-XX-XX */
function norm_phone(string $p): ?string {
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) === 11 && ($d[0] === '7' || $d[0] === '8')) $d = '7' . substr($d, 1);
    elseif (strlen($d) === 10) $d = '7' . $d;
    else return null;
    return sprintf('+7 (%s) %s-%s-%s', substr($d, 1, 3), substr($d, 4, 3), substr($d, 7, 2), substr($d, 9, 2));
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- Почта ---------------- */
function send_mail(string $to, string $subject, string $text): bool {
    $mode = cfg('mail_transport');
    if ($mode === 'none' || !$to) return false;
    $from = cfg('mail_from'); $fromName = cfg('mail_from_name');
    $encSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>';
    $body = chunk_split(base64_encode($text));
    $headers = "From: $encFrom\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    if ($mode === 'mail') {
        return @mail($to, $encSubj, $body, $headers);
    }
    try { return smtp_send($to, $encSubj, $headers, $body); }
    catch (Throwable $e) { error_log('SMTP: ' . $e->getMessage()); return false; }
}

function smtp_send(string $to, string $encSubj, string $headers, string $body): bool {
    $s = cfg('smtp');
    $host = ($s['secure'] === 'ssl' ? 'ssl://' : '') . $s['host'];
    $fp = @stream_socket_client($host . ':' . $s['port'], $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException("connect $errstr");
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
    $cmd = function (string $c, array $ok, string $label = '') use ($fp, $read) { fwrite($fp, $c . "\r\n"); $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException(($label ?: mb_substr(trim($c), 0, 40)) . ' -> ' . trim($r)); return $r; };
    $read();
    $name = parse_url((string)cfg('site_url'), PHP_URL_HOST) ?: 'localhost';
    $cmd("EHLO $name", [250]);
    if ($s['secure'] === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
        $cmd("EHLO $name", [250]);
    }
    if ($s['user'] !== '') {
        $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($s['user']), [334], 'AUTH (логин)'); $cmd(base64_encode($s['pass']), [235], 'AUTH (пароль)');
    }
    $cmd('MAIL FROM:<' . cfg('mail_from') . '>', [250]);
    foreach (array_filter(array_map('trim', explode(',', $to))) as $rcpt) $cmd("RCPT TO:<$rcpt>", [250, 251]);
    $cmd('DATA', [354]);
    $msg = "To: $to\r\nSubject: $encSubj\r\nDate: " . date('r') . "\r\n" . $headers . "\r\n" . $body;
    $msg = preg_replace('/^\./m', '..', $msg);
    $cmd($msg . "\r\n.", [250], 'DATA (письмо)');
    $cmd('QUIT', [221]);
    fclose($fp);
    return true;
}

function send_telegram(string $text): string {
    $t = cfg('telegram_bot_token'); $chat = cfg('telegram_chat_id');
    if (!$t || !$chat || !function_exists('curl_init')) return 'не настроен';
    $api = rtrim((string)(cfg('telegram_api') ?: 'https://api.telegram.org'), '/');
    $ch = curl_init("$api/bot$t/sendMessage");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000)]]);
    if ($px = cfg('telegram_proxy')) curl_setopt($ch, CURLOPT_PROXY, $px);
    $r = curl_exec($ch);
    if ($r === false) { $err = curl_error($ch); curl_close($ch); error_log('Telegram: ' . $err); return 'ошибка соединения: ' . $err; }
    curl_close($ch);
    $j = json_decode((string)$r, true);
    if (!empty($j['ok'])) return 'ok';
    $msg = (string)($j['description'] ?? $r);
    error_log('Telegram: ' . $msg);
    return 'ошибка: ' . $msg;
}
