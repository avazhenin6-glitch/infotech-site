<?php
/* CRM: реквизиты ИНФОТЕХ и расчётные счета (в БД, правятся в «Настройки»), клиенты по ИНН (DaData), импорт из 1С (xlsx). */
declare(strict_types=1);

function crm_db(): PDO {
    static $done = false;
    $pdo = lk_db();
    if ($done) return $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
    $cols = array_column($pdo->query('PRAGMA table_info(user_companies)')->fetchAll(), 'name');
    foreach (['full_name' => 'TEXT', 'director' => 'TEXT', 'phone' => 'TEXT', 'email' => 'TEXT', 'contract' => 'TEXT', 'note' => 'TEXT', 'kind' => 'TEXT', 'updated_at' => 'TEXT'] as $c => $t)
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE user_companies ADD COLUMN $c $t");
    $pdo->exec("CREATE INDEX IF NOT EXISTS user_companies_inn ON user_companies(inn)");
    $done = true;
    return $pdo;
}
function setting(string $key, $default = null) {
    $st = crm_db()->prepare('SELECT value FROM settings WHERE key=?'); $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : json_decode((string)$v, true);
}
function setting_put(string $key, $value): void {
    crm_db()->prepare('INSERT INTO settings (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

/* ---------- реквизиты ИНФОТЕХ ---------- */
function crm_company(): array {
    $c = (array)setting('company', []);
    return [
        'address'    => (string)($c['address'] ?? cfg('company_address') ?? ''),
        'phone'      => (string)($c['phone'] ?? '8 (812) 928-68-84'),
        'director'   => (string)($c['director'] ?? cfg('director_name') ?? ''),
        'accountant' => (string)($c['accountant'] ?? ''),
        'note'       => (string)($c['note'] ?? ''),
    ];
}
/* расчётные счета: [id => [id, name, bik, corr, account, default]] — из настроек, иначе из config.php 'bank' */
function crm_banks(): array {
    $list = (array)setting('banks', []);
    if (!$list) {
        $b = cfg('bank');
        if (is_array($b) && !empty($b['account'])) $list = [['id' => 'cfg', 'name' => $b['name'] ?? '', 'bik' => $b['bik'] ?? '', 'corr' => $b['corr'] ?? '', 'account' => $b['account'], 'default' => true]];
    }
    $out = [];
    foreach ($list as $b) $out[(string)$b['id']] = $b;
    return $out;
}
function crm_bank(?string $id = null): array {
    $all = crm_banks();
    if ($id !== null && isset($all[$id])) return $all[$id];
    foreach ($all as $b) if (!empty($b['default'])) return $b;
    if ($all) return reset($all);
    throw new RuntimeException('Не заполнены банковские реквизиты ИНФОТЕХ (Настройки → Расчётные счета): счёт не выставлен');
}
function crm_bank_add(array $b, bool $default = false): string {
    $list = array_values((array)setting('banks', []));
    foreach ($list as $x) if ($x['account'] === $b['account']) return (string)$x['id'];
    $id = 'b' . substr(md5($b['account']), 0, 6);
    if ($default || !$list) foreach ($list as &$x) $x['default'] = false;
    unset($x);
    $list[] = ['id' => $id, 'name' => $b['name'], 'bik' => $b['bik'], 'corr' => $b['corr'], 'account' => $b['account'], 'default' => $default || !$list];
    setting_put('banks', $list);
    return $id;
}
function crm_bank_label(array $b): string { return $b['name'] . ', р/с …' . substr((string)$b['account'], -4); }

/* ---------- DaData ---------- */
function crm_dadata_token(): string { return (string)(setting('dadata_token') ?: cfg('dadata_token') ?: ''); }
function crm_inn_ok(string $inn): bool {   // контрольные суммы ИНН ЮЛ (10) и ФЛ/ИП (12)
    if (!preg_match('/^\d{10}(\d{2})?$/', $inn)) return false;
    $d = array_map('intval', str_split($inn));
    $c = fn(array $w) => array_sum(array_map(fn($k, $x) => $k * $x, $w, array_slice($d, 0, count($w)))) % 11 % 10;
    if (strlen($inn) === 10) return $c([2, 4, 10, 3, 5, 9, 4, 6, 8]) === $d[9];
    return $c([7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === $d[10] && $c([3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === $d[11];
}

/* ---------- клиенты ---------- */
function crm_short_name(string $full): string {   // Общество с ограниченной ответственностью "X" → ООО «X»; «X ООО» (отчёт 1С) → ООО «X»
    $s = trim(preg_replace('/\s+/u', ' ', $full));
    $s = preg_replace('/^Общество с ограниченной ответственностью\s*/ui', 'ООО ', $s);
    $s = preg_replace('/^Акционерное общество\s*/ui', 'АО ', $s);
    $s = preg_replace('/^Индивидуальный предприниматель\s*/ui', 'ИП ', $s);
    if (preg_match('/^(.+?)\s+(ООО|АО|ПАО|ЗАО)$/u', $s, $m)) $s = $m[2] . ' ' . $m[1];
    $s = preg_replace('/"([^"]+)"/u', '«$1»', $s);
    if (preg_match('/^(ООО|АО|ПАО|ЗАО) ([^«].*)$/u', $s, $m)) $s = $m[1] . ' «' . $m[2] . '»';
    return $s;
}
function crm_client_by_inn(string $inn): ?array {
    $st = crm_db()->prepare('SELECT c.*, u.email AS user_email, u.name AS user_name FROM user_companies c JOIN users u ON u.id=c.user_id WHERE c.inn=? ORDER BY c.id LIMIT 1');
    $st->execute([$inn]);
    return $st->fetch() ?: null;
}
function crm_client(int $cid): ?array {
    $st = crm_db()->prepare('SELECT c.*, u.email AS user_email, u.name AS user_name, u.phone AS user_phone FROM user_companies c JOIN users u ON u.id=c.user_id WHERE c.id=?');
    $st->execute([$cid]);
    return $st->fetch() ?: null;
}
function crm_placeholder_email(string $inn): string { return 'inn' . $inn . '@client.invalid'; }
function crm_is_placeholder(string $email): bool { return str_ends_with($email, '.invalid'); }
/* создать или дополнить клиента; возвращает [company_id, user_id, created(bool)] */
function crm_client_save(array $d, ?int $cid = null): array {
    $pdo = crm_db(); $now = date('Y-m-d H:i:s');
    $inn = preg_replace('/\D/', '', (string)($d['inn'] ?? ''));
    if (!crm_inn_ok($inn)) throw new RuntimeException('Проверьте ИНН: ' . ($inn ?: 'не указан'));
    $email = mb_strtolower(trim((string)($d['email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Проверьте e-mail');
    $phone = trim((string)($d['phone'] ?? ''));
    if ($phone !== '' && !($phone = norm_phone($phone))) throw new RuntimeException('Телефон — в формате +7 (XXX) XXX-XX-XX');
    $f = ['kpp' => preg_replace('/\D/', '', (string)($d['kpp'] ?? '')), 'ogrn' => preg_replace('/\D/', '', (string)($d['ogrn'] ?? '')),
          'name' => clean($d['name'] ?? '', 250), 'full_name' => clean($d['full_name'] ?? '', 400), 'address' => clean($d['address'] ?? '', 400),
          'director' => clean($d['director'] ?? '', 200), 'phone' => $phone ?: '', 'email' => $email, 'contract' => clean($d['contract'] ?? '', 200),
          'note' => clean($d['note'] ?? '', 2000), 'kind' => strlen($inn) === 12 ? 'ip' : 'ul'];
    if ($f['name'] === '') $f['name'] = $f['full_name'] ? crm_short_name($f['full_name']) : '';
    if ($f['name'] === '') throw new RuntimeException('Укажите наименование клиента');
    $ex = $cid ? crm_client($cid) : crm_client_by_inn($inn);
    if ($ex) {
        if ($cid) $upd = $f + ['inn' => $inn];   // правка карточки — все поля как ввели
        else {   // импорт — только пустые поля; полное наименование из счёта уточняет краткое из отчёта
            $upd = []; foreach ($f as $k => $v) if ($v !== '' && trim((string)$ex[$k]) === '') $upd[$k] = $v;
            if (isset($upd['full_name']) && $f['name'] !== '') $upd['name'] = $f['name'];
        }
        if ($upd) {
            $sets = implode(', ', array_map(fn($k) => "$k=?", array_keys($upd)));
            $pdo->prepare("UPDATE user_companies SET $sets, updated_at=? WHERE id=?")->execute([...array_values($upd), $now, $ex['id']]);
        }
        // e-mail клиента для кабинета и писем: заменяем заглушку на настоящий
        if ($email !== '' && crm_is_placeholder((string)$ex['user_email']) && !lk_user_by_email($email))
            $pdo->prepare('UPDATE users SET email=?, name=COALESCE(NULLIF(name,\'\'), ?), phone=COALESCE(NULLIF(phone,\'\'), ?) WHERE id=?')->execute([$email, $f['director'] ?: $f['name'], $phone ?: null, $ex['user_id']]);
        return [(int)$ex['id'], (int)$ex['user_id'], false];
    }
    $u = $email !== '' ? lk_user_by_email($email) : null;
    if (!$u) {
        $pdo->prepare('INSERT INTO users (email, name, phone, created_at) VALUES (?,?,?,?)')->execute([$email ?: crm_placeholder_email($inn), $f['director'] ?: $f['name'], $phone ?: null, $now]);
        $uid = (int)$pdo->lastInsertId();
    } else $uid = (int)$u['id'];
    $pdo->prepare('INSERT INTO user_companies (user_id, inn, kpp, ogrn, name, full_name, address, director, phone, email, contract, note, kind, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, $inn, $f['kpp'], $f['ogrn'], $f['name'], $f['full_name'], $f['address'], $f['director'], $f['phone'], $f['email'], $f['contract'], $f['note'], $f['kind'], $now, $now]);
    return [(int)$pdo->lastInsertId(), $uid, true];
}
function crm_clients(string $q = ''): array {
    $pdo = crm_db();
    $rows = $pdo->query("SELECT c.*, u.email AS user_email,
        (SELECT COUNT(*) FROM orders o WHERE o.company_id=c.id AND o.type='order') AS orders_n,
        (SELECT COALESCE(SUM(COALESCE(o.invoice_amount,o.total_rub)-COALESCE(o.paid_amount,0)),0) FROM orders o WHERE o.company_id=c.id AND o.type='order' AND o.status IN ('Счёт выставлен','Частично оплачен','Платёж на проверке','Срок резерва истёк')) AS debt,
        (SELECT COUNT(*) FROM services s WHERE s.company_id=c.id AND s.status<>'Закрыт') AS svc_n
        FROM user_companies c JOIN users u ON u.id=c.user_id ORDER BY c.name")->fetchAll();
    if ($q === '') return $rows;
    return array_values(array_filter($rows, fn($c) => mb_stripos($c['name'] . ' ' . $c['full_name'] . ' ' . $c['inn'] . ' ' . $c['email'] . ' ' . $c['user_email'] . ' ' . $c['director'], $q) !== false));
}

/* ---------- xlsx без зависимостей ---------- */
function xlsx_zip_read(string $path): array {   // [имя => содержимое] для нужных файлов
    $want = fn($n) => $n === 'xl/sharedStrings.xml' || preg_match('#^xl/worksheets/sheet1\.xml$#', $n) || $n === 'xl/workbook.xml';
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive(); $out = [];
        if ($z->open($path) !== true) throw new RuntimeException('Файл не похож на Excel (xlsx)');
        for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if ($want($n)) $out[$n] = $z->getFromIndex($i); }
        $z->close();
        return $out;
    }
    $raw = (string)file_get_contents($path);
    $eocd = strrpos($raw, "PK\x05\x06");
    if ($eocd === false) throw new RuntimeException('Файл не похож на Excel (xlsx)');
    $e = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_off', substr($raw, $eocd + 4, 16));
    $p = $e['cd_off']; $out = [];
    for ($i = 0; $i < $e['entries']; $i++) {
        $h = unpack('Vsig/vver/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voff', substr($raw, $p, 46));
        $name = substr($raw, $p + 46, $h['nlen']);
        $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        if (!$want($name)) continue;
        $l = unpack('Vsig/vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($raw, $h['off'], 30));
        $data = substr($raw, $h['off'] + 30 + $l['nlen'] + $l['elen'], $h['csize']);
        $out[$name] = $h['method'] === 8 ? (string)gzinflate($data) : $data;
    }
    return $out;
}
/* строки первого листа: [номер строки => [номер столбца (1..) => значение]] */
function xlsx_rows(string $path): array {
    $f = xlsx_zip_read($path);
    if (empty($f['xl/worksheets/sheet1.xml'])) throw new RuntimeException('В файле нет листа с данными');
    $ss = [];
    if (!empty($f['xl/sharedStrings.xml'])) {
        $x = simplexml_load_string($f['xl/sharedStrings.xml'], 'SimpleXMLElement', LIBXML_NONET);
        foreach ($x->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; foreach ($si->r as $r) $t .= (string)$r->t; $ss[] = $t; }
    }
    $x = simplexml_load_string($f['xl/worksheets/sheet1.xml'], 'SimpleXMLElement', LIBXML_NONET);
    $rows = [];
    foreach ($x->sheetData->row as $row) {
        foreach ($row->c as $c) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', (string)$c['r'], $m)) continue;
            $col = 0; foreach (str_split($m[1]) as $ch) $col = $col * 26 + ord($ch) - 64;
            $t = (string)$c['t'];
            $v = $t === 's' ? ($ss[(int)$c->v] ?? '') : ($t === 'inlineStr' ? (string)$c->is->t : (string)$c->v);
            if ($v !== '') $rows[(int)$m[2]][$col] = $v;
        }
    }
    ksort($rows);
    return $rows;
}

/* ---------- разбор выгрузок 1С ---------- */
const CRM_MONTHS = ['янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'мая' => 5, 'май' => 5, 'июн' => 6, 'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12];
function crm_month_num(string $w): ?int {
    $w = mb_strtolower($w);
    if (isset(CRM_MONTHS[mb_substr($w, 0, 3)])) return CRM_MONTHS[mb_substr($w, 0, 3)];
    foreach (['ян' => 1, 'фе' => 2, 'ап' => 4, 'ав' => 8, 'се' => 9, 'ок' => 10, 'но' => 11, 'де' => 12] as $k => $n) if (mb_substr($w, 0, 2) === $k) return $n;   // опечатки: «нобярь»
    return null;
}
function crm_period(string $text): ?string {   // «… за ноябрь 2026 года» → 2026-11
    if (preg_match('/за\s+([а-яё]+)\s+(\d{4})/ui', $text, $m) && ($n = crm_month_num($m[1]))) return sprintf('%04d-%02d', $m[2], $n);
    return null;
}
function crm_detect(array $rows): string {
    foreach ($rows as $r) foreach ($r as $v) {
        if (preg_match('/^Сч[её]т на оплату №/u', $v)) return 'invoice';
        if ($v === 'Покупатель.ИНН') return 'report';
    }
    return 'unknown';
}
function crm_parse_buyer(string $s): array {   // « ООО "ВТМ",  ИНН 7838504785,  КПП 783901001,  190020, Город …»
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    $inn = preg_match('/ИНН\s*(\d{10,12})/u', $s, $m) ? $m[1] : '';
    $kpp = preg_match('/КПП\s*(\d{9})/u', $s, $m) ? $m[1] : '';
    $name = trim(preg_split('/,\s*ИНН/u', $s)[0]);
    $addr = '';
    if (preg_match($kpp ? '/КПП\s*\d{9},\s*(.+)$/u' : '/ИНН\s*\d{10,12},\s*(.+)$/u', $s, $m)) $addr = trim($m[1]);
    return ['full_name' => $name, 'name' => crm_short_name($name), 'inn' => $inn, 'kpp' => $kpp, 'address' => $addr];
}
function crm_parse_invoice(array $rows): array {
    $inv = ['number' => '', 'date' => '', 'buyer' => [], 'basis' => '', 'bank' => [], 'items' => [], 'total' => 0.0, 'supplier' => '', 'director' => '', 'accountant' => ''];
    $after = function (array $row, int $col) { foreach ($row as $c => $v) if ($c > $col && trim($v) !== '') return trim($v); return ''; };
    $accounts = [];
    foreach ($rows as $rn => $row) foreach ($row as $c => $v) {
        $v = trim($v);
        if (preg_match('/^Сч[её]т на оплату № ?(\S+) от (\d{1,2}) ([а-яё]+) (\d{4})/u', $v, $m) && ($mn = crm_month_num($m[3]))) { $inv['number'] = $m[1]; $inv['date'] = sprintf('%04d-%02d-%02d', $m[4], $mn, $m[2]); }
        elseif ($v === 'Покупатель') $inv['buyer'] = crm_parse_buyer($after($row, $c));
        elseif ($v === 'Поставщик') $inv['supplier'] = $after($row, $c);
        elseif ($v === 'Основание:') { $b = preg_replace('/^Договор:\s*/u', '', $after($row, $c)); $inv['basis'] = $b === 'Без договора' ? '' : $b; }
        elseif ($v === 'БИК') { $inv['bank']['bik'] = str_pad(preg_replace('/\D/', '', $after($row, $c)), 9, '0', STR_PAD_LEFT); foreach ($row as $c2 => $v2) if ($c2 < $c && preg_match('/\pL/u', $v2)) { $inv['bank']['name'] = trim($v2); break; } }
        elseif ($v === 'Сч. №') $accounts[] = preg_replace('/\D/', '', $after($row, $c));
        elseif ($v === 'Руководитель' && empty($inv['director'])) $inv['director'] = $after($row, $c);
        elseif ($v === 'Бухгалтер' && empty($inv['accountant'])) $inv['accountant'] = $after($row, $c);
    }
    $inv['bank']['corr'] = $accounts[0] ?? ''; $inv['bank']['account'] = $accounts[1] ?? '';
    // таблица позиций: столбцы по заголовкам
    $hdr = null; $cols = [];
    foreach ($rows as $rn => $row) if (in_array('Товар (Услуга)', array_map('trim', $row), true) || in_array('Товары (работы, услуги)', array_map('trim', $row), true)) {
        $hdr = $rn;
        foreach ($row as $c => $v) $cols[['№' => 'n', 'Товар (Услуга)' => 'name', 'Товары (работы, услуги)' => 'name', 'Код' => 'code', 'Кол-во' => 'qty', 'Ед.' => 'unit', 'Цена' => 'price', 'Сумма' => 'sum'][trim($v)] ?? '_'] = $c;
        break;
    }
    if ($hdr) foreach ($rows as $rn => $row) {
        if ($rn <= $hdr) continue;
        if (in_array('Итого:', array_map('trim', $row), true)) break;
        $name = trim($row[$cols['name'] ?? 0] ?? ''); $n = trim($row[$cols['n'] ?? 0] ?? '');
        if ($name === '' || !ctype_digit($n) || !preg_match('/\pL/u', $name)) continue;   // строка нумерации столбцов «1 2 3 …» — не позиция
        $num = fn($k) => (float)str_replace([' ', ','], ['', '.'], (string)($row[$cols[$k] ?? 0] ?? '0'));
        $it = ['name' => $name, 'code' => trim($row[$cols['code'] ?? 0] ?? ''), 'qty' => $num('qty') ?: 1, 'unit' => trim($row[$cols['unit'] ?? 0] ?? '') ?: 'шт', 'price' => $num('price'), 'sum' => $num('sum')];
        if (!$it['sum']) $it['sum'] = round($it['qty'] * $it['price'], 2);
        $inv['items'][] = $it; $inv['total'] += $it['sum'];
    }
    $inv['total'] = round($inv['total'], 2);
    if (!$inv['number'] || !$inv['date'] || !$inv['buyer'] || !$inv['items']) throw new RuntimeException('Не удалось разобрать счёт: нет номера, даты, покупателя или позиций');
    return $inv;
}
function crm_parse_report(array $rows): array {   // отчёт «Выручка по покупателям»: строка с именем, под ней ИНН
    $out = []; $prev = null;
    foreach ($rows as $rn => $row) {
        $a = trim((string)($row[1] ?? ''));
        if ($prev !== null && preg_match('/^\d{10}(\d{2})?$/', $a)) $out[$a] = ['inn' => $a, 'name' => crm_short_name($prev['a']), 'revenue' => (float)($prev['d'] ?? 0)];
        $prev = ['a' => $a, 'd' => $row[4] ?? null];
    }
    return array_values($out);
}
function crm_parse_supplier(string $s): array {   // адрес и телефон ИНФОТЕХ из строки «Поставщик» счёта 1С
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    $phone = preg_match('/тел\.?:?\s*([+\d][\d\s()+-]{8,})$/u', $s, $m) ? trim($m[1]) : '';
    $addr = preg_match('/КПП\s*\d{9},\s*(.+?)(?:,\s*тел.*)?$/u', $s, $m) ? trim($m[1]) : '';
    if ($phone && ($n = norm_phone($phone))) $phone = $n;
    return ['address' => $addr, 'phone' => $phone];
}
