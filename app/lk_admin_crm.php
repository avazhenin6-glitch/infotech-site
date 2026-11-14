<?php
/* Кабинет менеджера: клиенты (по ИНН, DaData), форма счёта, список счетов, загрузка из 1С, настройки реквизитов. */
declare(strict_types=1);

const CRM_ACTS = ['client_save', 'inv_new', 'imp_upload', 'imp_run', 'imp_clear', 'set_company', 'set_bank', 'set_bank_del', 'set_bank_def', 'set_dadata'];
const CRM_UNITS = ['шт', 'мес.', 'ч', 'усл.', 'компл.'];

function crm_client_fields_from_post(): array {
    return ['inn' => $_POST['inn'] ?? '', 'kpp' => $_POST['kpp'] ?? '', 'ogrn' => $_POST['ogrn'] ?? '', 'name' => $_POST['name'] ?? '', 'full_name' => $_POST['full_name'] ?? '',
            'address' => $_POST['address'] ?? '', 'director' => $_POST['director'] ?? '', 'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '',
            'contract' => $_POST['contract'] ?? '', 'note' => $_POST['note'] ?? ''];
}
function crm_enrich(array $d): array {   // DaData поверх данных из файла: заполняет пустые поля
    if (!crm_dadata_token() || !($x = lk_dadata_inn((string)$d['inn']))) return $d;
    foreach (['kpp', 'ogrn', 'address', 'full_name', 'director'] as $k) if (empty($d[$k]) && !empty($x[$k])) $d[$k] = trim($x[$k]);
    if (empty($d['name']) && !empty($x['name'])) $d['name'] = $x['name'];
    return $d;
}

function adm_crm_post(): void {
    $act = (string)$_POST['act']; $by = mgr_name();
    try {
        switch ($act) {
            case 'client_save':
                $cid = (int)($_POST['cid'] ?? 0);
                [$id, , $new] = crm_client_save(crm_client_fields_from_post(), $cid ?: null);
                lk_flash($new ? 'Клиент добавлен' : 'Карточка клиента сохранена'); lk_redirect('/lk/admin/?client=' . $id);
            case 'inv_new':
                $c = crm_client((int)$_POST['cid']);
                if (!$c) throw new RuntimeException('Клиент не найден');
                $items = [];
                foreach ((array)($_POST['it_name'] ?? []) as $i => $n)
                    $items[] = ['name' => clean($n, 500), 'qty' => (float)str_replace(',', '.', (string)($_POST['it_qty'][$i] ?? 0)), 'unit' => clean($_POST['it_unit'][$i] ?? 'шт', 10),
                                'price' => (float)str_replace([' ', ','], ['', '.'], (string)($_POST['it_price'][$i] ?? 0))];
                $date = preg_match('/^\d{4}-\d\d-\d\d$/', (string)($_POST['date'] ?? '')) ? $_POST['date'] : bill_today();
                $due = preg_match('/^\d{4}-\d\d-\d\d$/', (string)($_POST['due'] ?? '')) ? $_POST['due'] : wc_add_workdays($date, BILL_DUE_WORKDAY);
                if ($due < $date) throw new RuntimeException('Срок оплаты раньше даты счёта');
                $inv = inv_create(['user_id' => (int)$c['user_id'], 'company_id' => (int)$c['id'], 'items' => $items, 'date' => $date, 'due' => $due,
                    'basis' => clean($_POST['basis'] ?? '', 200), 'bank_id' => (string)($_POST['bank'] ?? ''), 'send' => !empty($_POST['send']), 'by' => $by, 'comment' => clean($_POST['comment'] ?? '', 1000)]);
                $o = lk_db()->query('SELECT number FROM orders WHERE id=' . (int)$inv['order_id'])->fetchColumn();
                lk_flash("Счёт {$inv['number']} на " . money_rub((float)$inv['amount']) . ' создан' . (!empty($_POST['send']) && !crm_is_placeholder((string)$c['user_email']) ? ' и отправлен клиенту' : ''));
                lk_redirect('/lk/admin/?n=' . urlencode((string)$o));
            case 'imp_upload':
                $parsed = $_SESSION['imp'] ?? []; $errs = [];   // дозагрузка частями: добавляем к уже разобранным
                $files = $_FILES['files'] ?? [];
                foreach ((array)($files['name'] ?? []) as $i => $fname) {
                    if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) { $errs[] = "$fname: не загрузился"; continue; }
                    if ($files['size'][$i] > 10 * 1048576) { $errs[] = "$fname: больше 10 МБ"; continue; }
                    try {
                        $rows = xlsx_rows($files['tmp_name'][$i]);
                        $type = crm_detect($rows);
                        $key = $type === 'invoice' ? 'inv:' . ($pi = crm_parse_invoice($rows))['number'] . ':' . $pi['date'] : 'file:' . $fname;
                        if (in_array($key, array_column($parsed, 'key'), true)) continue;
                        if ($type === 'invoice') $parsed[] = ['type' => 'invoice', 'file' => $fname, 'key' => $key, 'inv' => $pi];
                        elseif ($type === 'report') $parsed[] = ['type' => 'report', 'file' => $fname, 'key' => $key, 'clients' => crm_parse_report($rows)];
                        else $errs[] = "$fname: не похоже на счёт или отчёт по покупателям из 1С";
                    } catch (RuntimeException $e) { $errs[] = "$fname: " . $e->getMessage(); }
                }
                $_SESSION['imp'] = $parsed;
                if ($errs) lk_flash(implode('; ', $errs), $parsed ? 'wait' : 'bad');
                lk_redirect('/lk/admin/?import=1');
            case 'imp_clear': unset($_SESSION['imp']); lk_redirect('/lk/admin/?import=1');
            case 'imp_run':
                $parsed = $_SESSION['imp'] ?? [];
                if (!$parsed) throw new RuntimeException('Нечего загружать — выберите файлы заново');
                $res = adm_crm_import($parsed, (array)($_POST['svc'] ?? []), $by);
                unset($_SESSION['imp']);
                lk_flash($res); lk_redirect('/lk/admin/?clients=1');
            case 'set_company':
                if (!staff_is_admin()) throw new RuntimeException('Реквизиты меняет администратор');
                $phone = trim((string)($_POST['phone'] ?? ''));
                if ($phone !== '' && !($phone = norm_phone($phone))) throw new RuntimeException('Телефон — в формате +7 (XXX) XXX-XX-XX');
                setting_put('company', ['address' => clean($_POST['address'] ?? '', 300), 'phone' => $phone ?: '', 'director' => clean($_POST['director'] ?? '', 100),
                                        'accountant' => clean($_POST['accountant'] ?? '', 100), 'note' => clean($_POST['note'] ?? '', 600)]);
                lk_flash('Реквизиты сохранены'); lk_redirect('/lk/admin/?settings=1');
            case 'set_bank':
                if (!staff_is_admin()) throw new RuntimeException('Нет прав');
                $b = ['name' => clean($_POST['bname'] ?? '', 200), 'bik' => preg_replace('/\D/', '', (string)($_POST['bik'] ?? '')), 'corr' => preg_replace('/\D/', '', (string)($_POST['corr'] ?? '')), 'account' => preg_replace('/\D/', '', (string)($_POST['account'] ?? ''))];
                if (!$b['name'] || strlen($b['bik']) !== 9 || strlen($b['corr']) !== 20 || strlen($b['account']) !== 20) throw new RuntimeException('Проверьте: БИК — 9 цифр, корр. и расчётный счёт — по 20 цифр');
                crm_bank_add($b, !empty($_POST['def']));
                lk_flash('Расчётный счёт добавлен'); lk_redirect('/lk/admin/?settings=1');
            case 'set_bank_del': case 'set_bank_def':
                if (!staff_is_admin()) throw new RuntimeException('Нет прав');
                $list = array_values((array)setting('banks', [])); $id = (string)$_POST['bid'];
                if ($act === 'set_bank_del') {
                    $used = (int)lk_db()->query('SELECT COUNT(*) FROM invoices WHERE bank_id=' . lk_db()->quote($id))->fetchColumn();
                    if ($used) throw new RuntimeException("По этому счёту уже выставлено счетов: $used — удалить нельзя, сделайте основным другой");
                    $list = array_values(array_filter($list, fn($b) => $b['id'] !== $id));
                    if ($list && !array_filter($list, fn($b) => !empty($b['default']))) $list[0]['default'] = true;
                } else foreach ($list as &$b) $b['default'] = $b['id'] === $id;
                unset($b);
                setting_put('banks', $list); lk_flash('Готово'); lk_redirect('/lk/admin/?settings=1');
            case 'set_dadata':
                if (!staff_is_admin()) throw new RuntimeException('Нет прав');
                $t = trim((string)($_POST['token'] ?? ''));
                if ($t !== '' && !preg_match('/^[0-9a-f]{40}$/i', $t)) throw new RuntimeException('Ключ DaData — 40 символов (0-9, a-f), раздел «API-ключ» в личном кабинете dadata.ru');
                setting_put('dadata_token', $t);
                lk_flash($t ? 'Ключ DaData сохранён — автозаполнение по ИНН включено' : 'Ключ DaData удалён'); lk_redirect('/lk/admin/?settings=1');
        }
    } catch (RuntimeException $e) {
        lk_flash($e->getMessage(), 'bad');
        lk_redirect($_SERVER['HTTP_REFERER'] ?? '/lk/admin/?clients=1');
    }
}

function adm_crm_import(array $parsed, array $svcFor, string $by): string {
    $pdo = lk_db(); $n = ['cl_new' => 0, 'cl_upd' => 0, 'inv' => 0, 'svc' => 0, 'skip' => []];
    // клиенты из отчёта
    foreach ($parsed as $p) if ($p['type'] === 'report') foreach ($p['clients'] as $cl) {
        if (crm_client_by_inn($cl['inn'])) { $n['cl_upd']++; continue; }
        try { [, , $new] = crm_client_save(crm_enrich(['inn' => $cl['inn'], 'name' => $cl['name']])); $n['cl_new'] += $new ? 1 : 0; }
        catch (RuntimeException $e) { $n['skip'][] = $cl['name'] . ': ' . $e->getMessage(); }
    }
    $invs = array_values(array_filter($parsed, fn($p) => $p['type'] === 'invoice'));
    if ($invs) {
        // реквизиты ИНФОТЕХ из счетов — если ещё не заполнены
        $co = (array)setting('company', []);
        $sup = crm_parse_supplier((string)$invs[0]['inv']['supplier']);
        foreach (['address' => $sup['address'], 'phone' => $sup['phone'], 'director' => $invs[0]['inv']['director'], 'accountant' => $invs[0]['inv']['accountant']] as $k => $v)
            if (empty($co[$k]) && $v) $co[$k] = $v;
        setting_put('company', $co);
        // расчётные счета: самый частый — основной
        $freq = []; $banks = [];
        foreach ($invs as $p) if (!empty($p['inv']['bank']['account'])) { $a = $p['inv']['bank']['account']; $freq[$a] = ($freq[$a] ?? 0) + 1; $banks[$a] = $p['inv']['bank']; }
        arsort($freq);
        foreach (array_keys($freq) as $i => $a) crm_bank_add($banks[$a] + ['name' => $banks[$a]['name'] ?? 'Банк'], $i === 0 && !setting('banks'));
    }
    usort($invs, fn($a, $b) => strnatcmp($a['inv']['number'], $b['inv']['number']));
    foreach ($invs as $p) {
        $inv = $p['inv'];
        try {
            [$cid, $uid] = crm_client_save(crm_enrich($inv['buyer'] + ['contract' => $inv['basis']]));
            $bankId = !empty($inv['bank']['account']) ? crm_bank_add($inv['bank']) : null;
            $period = null; $sid = null;
            $rent = count($inv['items']) === 1 && mb_stripos($inv['items'][0]['name'], 'аренда сервера') !== false ? crm_period($inv['items'][0]['name']) : null;
            if ($rent && in_array($inv['number'], $svcFor, true)) {
                $st = $pdo->prepare("SELECT * FROM services WHERE company_id=? AND kind='server' AND status<>'Закрыт' ORDER BY id LIMIT 1"); $st->execute([$cid]);
                $s = $st->fetch();
                $paidTo = date('Y-m-t', strtotime("$rent-01 -1 month"));
                if (!$s) {
                    $now = date('Y-m-d H:i:s');
                    $pdo->prepare("INSERT INTO services (user_id, company_id, kind, title, tariff_code, period_months, price, vpn_status, status, paid_until, contract, note, created_at, updated_at)
                                   VALUES (?,?,'server','Аренда сервера','',1,?,'Активен','Активен',?,?,?,?,?)")
                        ->execute([$uid, $cid, $inv['total'], $paidTo, $inv['basis'] ?: null, 'Создано при загрузке счёта № ' . $inv['number'] . ' из 1С. Укажите VPN-учётку.', $now, $now]);
                    $sid = (int)$pdo->lastInsertId(); $n['svc']++;
                } else $sid = (int)$s['id'];
                if (bill_invoice_for($sid, $rent)) { $sid = null; }   // за этот месяц счёт уже есть — грузим без привязки
                else $period = $rent;
            }
            inv_create(['user_id' => $uid, 'company_id' => $cid, 'number' => $inv['number'], 'date' => $inv['date'], 'due' => wc_add_workdays($inv['date'], BILL_DUE_WORKDAY),
                'items' => $inv['items'], 'basis' => $inv['basis'], 'bank_id' => $bankId, 'legacy' => true, 'send' => false, 'by' => $by,
                'service_id' => $sid, 'period' => $period, 'comment' => 'Счёт загружен из 1С (' . $p['file'] . ')', 'delivery' => $rent ? 'Аренда сервера' : '']);
            $n['inv']++;
        } catch (RuntimeException $e) { $n['skip'][] = "счёт № {$inv['number']}: " . $e->getMessage(); }
    }
    return 'Загружено: новых клиентов ' . $n['cl_new'] . ($n['cl_upd'] ? ", уже были — {$n['cl_upd']}" : '') . ', счетов ' . $n['inv'] . ($n['svc'] ? ', услуг аренды создано ' . $n['svc'] : '')
        . ($n['skip'] ? '. Пропущено: ' . implode('; ', $n['skip']) : '');
}

function crm_client_form(?array $c): string {
    $v = fn($k) => h((string)($c[$k] ?? ''));
    $tok = (bool)crm_dadata_token();
    return '<form method="post" class="svcform" id="clientForm">' . lk_csrf_field() . '<input type="hidden" name="act" value="client_save"><input type="hidden" name="cid" value="' . (int)($c['id'] ?? 0) . '">'
        . '<div class="row"><label>ИНН<input name="inn" id="cInn" value="' . $v('inn') . '" inputmode="numeric" pattern="\d{10}|\d{12}" required></label>'
        . '<label>&nbsp;<button type="button" class="btn ghost" id="innFill"' . ($tok ? '' : ' title="Ключ DaData не задан (Настройки)"') . '>Заполнить по ИНН</button></label>'
        . '<label>КПП<input name="kpp" value="' . $v('kpp') . '" inputmode="numeric" pattern="\d{9}"></label><label>ОГРН / ОГРНИП<input name="ogrn" value="' . $v('ogrn') . '" inputmode="numeric"></label></div>'
        . '<p class="muted small" id="innMsg">' . ($tok ? 'Введите ИНН и нажмите «Заполнить» — реквизиты подтянутся из ЕГРЮЛ/ЕГРИП (DaData).' : 'Автозаполнение выключено: администратор может добавить ключ DaData в «Настройки». Пока заполните вручную.') . '</p>'
        . '<div class="row"><label>Наименование (кратко)<input name="name" value="' . $v('name') . '" placeholder="ООО «Ромашка»" required></label><label>Полное наименование<input name="full_name" value="' . $v('full_name') . '"></label></div>'
        . '<div class="row"><label>Юридический адрес<input name="address" value="' . $v('address') . '"></label><label>Руководитель<input name="director" value="' . $v('director') . '"></label></div>'
        . '<div class="row"><label>E-mail для счетов и кабинета<input type="email" name="email" value="' . h(($c && !crm_is_placeholder((string)$c['user_email'])) ? (string)($c['email'] ?: $c['user_email']) : (string)($c['email'] ?? '')) . '"></label>'
        . '<label>Телефон<input type="tel" name="phone" value="' . $v('phone') . '" placeholder="+7 (___) ___-__-__"></label><label>Договор (основание в счетах)<input name="contract" value="' . $v('contract') . '" placeholder="Договор № 7 от 25.11.2020"></label></div>'
        . '<label>Заметка<textarea name="note" rows="2">' . $v('note') . '</textarea></label>'
        . '<button class="btn">' . ($c ? 'Сохранить' : 'Добавить клиента') . '</button></form>'
        . '<script>(function(){var b=document.getElementById("innFill"),f=document.getElementById("clientForm"),m=document.getElementById("innMsg");b.onclick=function(){var inn=f.inn.value.replace(/\D/g,"");if(!/^(\d{10}|\d{12})$/.test(inn)){m.textContent="ИНН — 10 цифр (организация) или 12 (ИП)";return}m.textContent="Ищу…";'
        . 'fetch("/lk/admin/?inn_lookup="+inn,{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(j.exists&&!' . (int)($c['id'] ?? 0) . '){m.innerHTML=\'Клиент с этим ИНН уже есть: <a href="?client=\'+j.exists+\'">открыть карточку</a>\';return}if(!j.ok){m.textContent=j.error;return}'
        . '["kpp","ogrn","name","full_name","address","director"].forEach(function(k){if(j.data[k]&&!f[k].value)f[k].value=j.data[k]});m.textContent="Заполнено по данным ЕГРЮЛ/ЕГРИП"+(j.data.status&&j.data.status!=="ACTIVE"?" — внимание: организация не действующая ("+j.data.status+")":"")})}})();</script>';
}

function adm_crm_get(): void {
    if (isset($_GET['inn_lookup'])) {
        header('Content-Type: application/json'); $inn = preg_replace('/\D/', '', (string)$_GET['inn_lookup']);
        $ex = crm_client_by_inn($inn);
        if (!crm_inn_ok($inn)) { echo json_encode(['ok' => false, 'error' => 'Неверный ИНН (не сходится контрольная сумма)']); exit; }
        if (!crm_dadata_token()) { echo json_encode(['ok' => false, 'exists' => $ex['id'] ?? null, 'error' => 'Автозаполнение не подключено: нужен ключ DaData в «Настройки». Заполните вручную.'], JSON_UNESCAPED_UNICODE); exit; }
        $d = lk_dadata_inn($inn);
        echo json_encode($d ? ['ok' => true, 'data' => $d, 'exists' => $ex['id'] ?? null] : ['ok' => false, 'exists' => $ex['id'] ?? null, 'error' => 'По этому ИНН ничего не найдено'], JSON_UNESCAPED_UNICODE); exit;
    }
    if (isset($_GET['clients'])) {
        $q = trim((string)($_GET['q'] ?? '')); $rows = '';
        foreach (crm_clients($q) as $c) {
            $mail = crm_is_placeholder((string)$c['user_email']) ? '' : (string)$c['user_email'];
            $rows .= '<tr><td><a href="?client=' . $c['id'] . '"><b>' . h($c['name']) . '</b></a><br><span class="muted">ИНН ' . h($c['inn']) . ($c['kpp'] ? ' · КПП ' . h($c['kpp']) : '') . '</span></td>'
                . '<td>' . ($mail ? h($mail) : '<span class="muted">нет e-mail</span>') . ($c['phone'] ? '<br>' . h($c['phone']) : '') . '</td><td>' . (int)$c['orders_n'] . '</td>'
                . '<td>' . ((float)$c['debt'] > 0 ? '<b>' . money_rub((float)$c['debt']) . '</b>' : '—') . '</td><td>' . ((int)$c['svc_n'] ?: '—') . '</td></tr>';
        }
        mgr_page('Клиенты', '<h1>Клиенты</h1><p><a class="btn" href="?client=new">Новый клиент по ИНН</a> <a class="btn ghost" href="?import=1">Загрузить из 1С (Excel)</a> <a href="?users=1" class="muted">учётные записи кабинета</a></p>'
            . '<form class="search" method="get"><input type="hidden" name="clients" value="1"><input name="q" value="' . h($q) . '" placeholder="Название, ИНН, e-mail, руководитель"><button class="btn">Найти</button></form>'
            . '<div class="card cut"><table class="tbl"><thead><tr><th>Клиент</th><th>Контакты</th><th>Счетов</th><th>К оплате</th><th>Услуг</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5" class="muted">Клиентов пока нет — добавьте по ИНН или загрузите из 1С.</td></tr>') . '</tbody></table></div>');
    }
    if (isset($_GET['client']) && !isset($_GET['invoice'])) {
        $c = $_GET['client'] === 'new' ? null : crm_client((int)$_GET['client']);
        if ($_GET['client'] !== 'new' && !$c) { http_response_code(404); mgr_page('Клиент', '<h1>Клиент не найден</h1>'); }
        $extra = '';
        if ($c) {
            $inv = '';
            $st = lk_db()->prepare("SELECT o.*, i.legacy, i.bank_id FROM orders o LEFT JOIN invoices i ON i.order_id=o.id WHERE o.company_id=? AND o.type='order' ORDER BY o.id DESC"); $st->execute([$c['id']]);
            foreach ($st as $o) $inv .= '<tr><td><a href="?n=' . urlencode($o['number']) . '">' . h($o['invoice_number'] ? '№ ' . $o['invoice_number'] : $o['number']) . '</a>' . ($o['legacy'] ? ' <span class="muted">(1С)</span>' : '') . '</td><td>' . lk_date($o['invoice_date'] ?: $o['created_at']) . '</td>'
                . '<td>' . h(mb_strimwidth(implode('; ', array_column(lk_items($o), 'name')), 0, 90, '…')) . '</td><td>' . money_rub((float)($o['invoice_amount'] ?: $o['total_rub'])) . '</td><td>' . lk_badge($o['status']) . '</td></tr>';
            $svc = '';
            $st = lk_db()->prepare("SELECT * FROM services WHERE company_id=? ORDER BY id"); $st->execute([$c['id']]);
            foreach ($st as $s) $svc .= '<li><a href="?svc=' . $s['id'] . '">' . h(($s['title'] ?: SVC_KINDS[$s['kind']]) . ' — ' . SVC_KINDS[$s['kind']]) . '</a> · ' . h($s['status']) . ' · оплачено до ' . lk_date($s['paid_until']) . '</li>';
            $lk = crm_is_placeholder((string)$c['user_email']) ? '<p class="badge wait">Нет e-mail: счета не уходят клиенту, вход в кабинет недоступен. Укажите e-mail в карточке.</p>'
                : '<p class="muted">Кабинет клиента: ' . h($c['user_email']) . ' · <a href="?as=' . $c['user_id'] . '">открыть глазами клиента</a></p>';
            $extra = '<div class="card cut"><h2>Счета и заказы</h2><p><a class="btn" href="?invoice=new&client=' . $c['id'] . '">Выставить счёт</a></p>'
                . ($inv ? '<table class="tbl"><thead><tr><th>Счёт</th><th>Дата</th><th>Позиции</th><th>Сумма</th><th>Статус</th></tr></thead><tbody>' . $inv . '</tbody></table>' : '<p class="muted">Счетов пока нет.</p>') . '</div>'
                . '<div class="card cut"><h2>Услуги</h2>' . ($svc ? '<ul>' . $svc . '</ul>' : '<p class="muted">Нет подключённых услуг.</p>') . '<p><a href="?svc=new&uid=' . $c['user_id'] . '">Подключить услугу (аренда, сопровождение, ИТС)</a></p>' . $lk . '</div>';
        }
        mgr_page($c ? $c['name'] : 'Новый клиент', '<p><a href="?clients=1">← Все клиенты</a></p><h1>' . ($c ? h($c['name']) : 'Новый клиент') . '</h1><div class="card cut">' . crm_client_form($c) . '</div>' . $extra);
    }
    if (isset($_GET['invoice']) && $_GET['invoice'] === 'new') {
        $c = crm_client((int)($_GET['client'] ?? 0));
        if (!$c) { lk_flash('Выберите клиента — счёт выставляется из его карточки', 'wait'); lk_redirect('/lk/admin/?clients=1'); }
        $banks = crm_banks();
        if (!$banks) { lk_flash('Сначала добавьте расчётный счёт ИНФОТЕХ в «Настройки»', 'bad'); lk_redirect(staff_is_admin() ? '/lk/admin/?settings=1' : '/lk/admin/?client=' . $c['id']); }
        $bopt = ''; foreach ($banks as $b) $bopt .= '<option value="' . h($b['id']) . '"' . (!empty($b['default']) ? ' selected' : '') . '>' . h(crm_bank_label($b)) . '</option>';
        $uopt = implode('', array_map(fn($u) => '<option>' . h($u) . '</option>', CRM_UNITS));
        $noMail = crm_is_placeholder((string)$c['user_email']);
        $row = '<tr><td><input name="it_name[]" required placeholder="Наименование товара или услуги"></td><td><input name="it_qty[]" value="1" inputmode="decimal" class="q" required></td><td><input name="it_unit[]" list="units" value="шт" class="u"></td><td><input name="it_price[]" inputmode="decimal" class="p" required></td><td class="s">0,00</td><td><button type="button" class="link del">×</button></td></tr>';
        mgr_page('Новый счёт', '<p><a href="?client=' . $c['id'] . '">← ' . h($c['name']) . '</a></p><h1>Счёт на оплату</h1>'
            . '<form method="post" class="card cut svcform" id="invForm">' . lk_csrf_field() . '<input type="hidden" name="act" value="inv_new"><input type="hidden" name="cid" value="' . $c['id'] . '">'
            . '<p>Покупатель: <b>' . h($c['full_name'] ?: $c['name']) . '</b>, ИНН ' . h($c['inn']) . ($c['kpp'] ? ', КПП ' . h($c['kpp']) : '') . '</p>'
            . '<div class="row"><label>Дата счёта<input type="date" name="date" value="' . bill_today() . '" required></label><label>Оплатить до<input type="date" name="due" value="' . wc_add_workdays(bill_today(), BILL_DUE_WORKDAY) . '" required></label>'
            . '<label>На расчётный счёт<select name="bank">' . $bopt . '</select></label></div>'
            . '<label>Основание<input name="basis" value="' . h((string)$c['contract']) . '" placeholder="Договор № … от … (пусто — без основания)"></label>'
            . '<datalist id="units">' . $uopt . '</datalist><table class="tbl items"><thead><tr><th>Товар / услуга</th><th style="width:80px">Кол-во</th><th style="width:105px">Ед.</th><th style="width:120px">Цена, ₽</th><th style="width:120px">Сумма</th><th></th></tr></thead><tbody id="rows">' . $row . '</tbody>'
            . '<tfoot><tr><td colspan="4"><button type="button" class="btn ghost small" id="addRow">+ строка</button></td><td><b id="total">0,00</b></td><td></td></tr></tfoot></table>'
            . '<label>Комментарий для себя (в счёт не попадает)<input name="comment"></label>'
            . '<label class="chk"><input type="checkbox" name="send" value="1"' . ($noMail ? ' disabled' : ' checked') . '> Отправить счёт клиенту на e-mail' . ($noMail ? ' (у клиента нет e-mail)' : ' (' . h($c['user_email']) . ')') . '</label>'
            . '<p class="muted small">Номер присвоится автоматически: ИТ-000001, ИТ-000002… Счёт появится в кабинете клиента и в списке счетов; оплата с этим номером в назначении разнесётся сама.</p>'
            . '<button class="btn">Создать счёт</button></form>'
            . '<script>(function(){var t=document.getElementById("rows"),tpl=' . json_encode($row) . ';function num(v){return parseFloat(String(v).replace(/\s/g,"").replace(",","."))||0}'
            . 'function fmt(x){return x.toLocaleString("ru-RU",{minimumFractionDigits:2,maximumFractionDigits:2})}function calc(){var s=0;t.querySelectorAll("tr").forEach(function(r){var v=Math.round(num(r.querySelector(".q").value)*num(r.querySelector(".p").value)*100)/100;r.querySelector(".s").textContent=fmt(v);s+=v});document.getElementById("total").textContent=fmt(s)}'
            . 't.addEventListener("input",calc);t.addEventListener("click",function(e){if(e.target.classList.contains("del")&&t.rows.length>1){e.target.closest("tr").remove();calc()}});document.getElementById("addRow").onclick=function(){t.insertAdjacentHTML("beforeend",tpl)}})();</script>');
    }
    if (isset($_GET['invoices'])) {
        $f = (string)($_GET['f'] ?? 'open'); $rows = '';
        $banks = crm_banks();
        $sql = "SELECT i.*, o.number AS onum, o.status, o.paid_amount, COALESCE(c.name, o.company, o.email) AS client, c.id AS cid FROM invoices i JOIN orders o ON o.id=i.order_id LEFT JOIN user_companies c ON c.id=o.company_id"
             . ($f === 'open' ? " WHERE i.annulled=0 AND o.status IN ('Счёт выставлен','Частично оплачен','Платёж на проверке','Срок резерва истёк')" : '') . " ORDER BY i.issued DESC, i.id DESC LIMIT 500";
        $sum = 0.0;
        foreach (lk_db()->query($sql) as $i) {
            $sum += (float)$i['amount'] - (float)$i['paid_amount'];
            $rows .= '<tr><td><a href="?n=' . urlencode($i['onum']) . '"><b>№ ' . h($i['number']) . '</b></a>' . ($i['legacy'] ? ' <span class="muted">(1С)</span>' : '') . '</td><td>' . lk_date($i['issued']) . '</td>'
                . '<td>' . ($i['cid'] ? '<a href="?client=' . $i['cid'] . '">' . h($i['client']) . '</a>' : h($i['client'])) . '</td><td>' . money_rub((float)$i['amount']) . '</td><td>' . lk_date($i['due']) . '</td>'
                . '<td>' . ($i['annulled'] ? '<span class="badge bad">аннулирован</span>' : lk_badge($i['status'])) . '</td><td class="muted">' . h(isset($banks[$i['bank_id']]) ? crm_bank_label($banks[$i['bank_id']]) : '') . '</td></tr>';
        }
        mgr_page('Счета', '<h1>Счета</h1><div class="tabs"><a href="?invoices=1&f=open"' . ($f === 'open' ? ' class="on"' : '') . '>Неоплаченные</a><a href="?invoices=1&f=all"' . ($f === 'all' ? ' class="on"' : '') . '>Все</a></div>'
            . ($f === 'open' ? '<p>К оплате всего: <b>' . money_rub($sum) . '</b></p>' : '')
            . '<div class="card cut"><table class="tbl"><thead><tr><th>Счёт</th><th>Дата</th><th>Клиент</th><th>Сумма</th><th>Срок</th><th>Статус</th><th>Расчётный счёт</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="7" class="muted">Счетов нет.</td></tr>') . '</tbody></table></div>'
            . '<p class="muted">Новый счёт выставляется из карточки клиента: «Клиенты» → клиент → «Выставить счёт».</p>');
    }
    if (isset($_GET['import'])) {
        $parsed = $_SESSION['imp'] ?? [];
        $prev = '';
        foreach ($parsed as $p) {
            if ($p['type'] === 'report') {
                $r = '';
                foreach ($p['clients'] as $cl) { $ex = crm_client_by_inn($cl['inn']); $r .= '<tr><td>' . h($cl['name']) . '</td><td>' . h($cl['inn']) . (crm_inn_ok($cl['inn']) ? '' : ' <span class="badge bad">ИНН с ошибкой</span>') . '</td><td>' . ($ex ? '<span class="muted">уже есть</span>' : '<span class="badge ok">новый</span>') . '</td></tr>'; }
                $prev .= '<div class="card cut"><h2>Клиенты из отчёта «' . h($p['file']) . '» · ' . count($p['clients']) . '</h2><table class="tbl"><thead><tr><th>Покупатель</th><th>ИНН</th><th></th></tr></thead><tbody>' . $r . '</tbody></table></div>';
            }
        }
        $ir = '';
        foreach ($parsed as $p) if ($p['type'] === 'invoice') {
            $i = $p['inv']; $ex = lk_db()->prepare('SELECT 1 FROM invoices WHERE number=?'); $ex->execute([$i['number']]); $dup = (bool)$ex->fetchColumn();
            $rent = count($i['items']) === 1 && mb_stripos($i['items'][0]['name'], 'аренда сервера') !== false ? crm_period($i['items'][0]['name']) : null;
            $ir .= '<tr><td><b>№ ' . h($i['number']) . '</b><br>' . lk_date($i['date']) . '</td><td>' . h($i['buyer']['name']) . '<br><span class="muted">ИНН ' . h($i['buyer']['inn']) . (crm_client_by_inn($i['buyer']['inn']) ? ' · клиент есть' : ' · новый клиент') . '</span></td>'
                . '<td>' . implode('<br>', array_map(fn($it) => h($it['name']) . ' — ' . money_rub((float)$it['sum']), $i['items'])) . ($i['basis'] ? '<br><span class="muted">Основание: ' . h($i['basis']) . '</span>' : '') . '</td>'
                . '<td><b>' . money_rub($i['total']) . '</b><br><span class="muted">' . h(($i['bank']['name'] ?? '') . ' …' . substr((string)($i['bank']['account'] ?? ''), -4)) . '</span></td>'
                . '<td>' . ($dup ? '<span class="badge wait">уже загружен</span>' : ($rent ? '<label class="chk"><input type="checkbox" name="svc[]" value="' . h($i['number']) . '" checked> аренда за ' . h(bill_month_name($rent)) . ': создать услугу и привязать</label>' : '')) . '</td></tr>';
        }
        if ($ir) $prev .= '<div class="card cut"><h2>Счета</h2><p class="muted">Счета из 1С загружаются со своими номерами и пометкой «1С». По ним не будет автоматических напоминаний и отключений — только учёт и разнесение оплат. '
            . 'Услуга аренды нужна, чтобы с декабря счета выставлялись автоматически: абонентская плата = сумма счёта, «оплачено до» — конец предыдущего месяца.</p>'
            . '<table class="tbl"><thead><tr><th>Счёт</th><th>Покупатель</th><th>Позиции</th><th>Сумма</th><th></th></tr></thead><tbody>' . $ir . '</tbody></table></div>';
        mgr_page('Загрузка из 1С', '<p><a href="?clients=1">← Клиенты</a></p><h1>Загрузка из 1С</h1>'
            . '<div class="card cut"><form method="post" enctype="multipart/form-data" class="filters">' . lk_csrf_field() . '<input type="hidden" name="act" value="imp_upload">'
            . '<label>Файлы Excel из 1С (можно несколько)<input type="file" name="files[]" accept=".xlsx" multiple required></label><button class="btn">Разобрать</button></form>'
            . '<p class="muted small">За один раз — до 20 МБ (примерно 4 счёта из 1С). Если файлов больше, выбирайте частями: разобранные копятся ниже, потом «Загрузить в CRM» один раз.<br>Подходят: печатная форма «Счёт на оплату», сохранённая в Excel, и отчёт «Выручка по покупателям» (покупатель + ИНН). Клиенты ищутся по ИНН: новые создаются' . (crm_dadata_token() ? ' и дополняются данными ЕГРЮЛ (DaData)' : '') . ', существующие не дублируются.</p></div>'
            . ($prev ? '<form method="post">' . lk_csrf_field() . '<input type="hidden" name="act" value="imp_run">' . $prev . '<button class="btn">Загрузить в CRM</button> <button class="btn ghost" name="act" value="imp_clear" formnovalidate>Отменить</button></form>' : ''));
    }
    if (isset($_GET['settings'])) {
        if (!staff_is_admin()) { lk_flash('Настройки доступны администратору', 'bad'); lk_redirect('/lk/admin/'); }
        $co = crm_company(); $b = '';
        foreach ((array)setting('banks', []) as $x) {
            $f = lk_csrf_field() . '<input type="hidden" name="bid" value="' . h($x['id']) . '">';
            $b .= '<tr><td><b>' . h($x['name']) . '</b>' . (!empty($x['default']) ? ' <span class="badge ok">основной</span>' : '') . '</td><td>' . h($x['bik']) . '</td><td>' . h($x['corr']) . '</td><td>' . h($x['account']) . '</td><td>'
                . (empty($x['default']) ? '<form method="post" class="inline">' . $f . '<button class="link" name="act" value="set_bank_def">сделать основным</button></form> · ' : '')
                . '<form method="post" class="inline" onsubmit="return confirm(\'Удалить расчётный счёт?\')">' . $f . '<button class="link" name="act" value="set_bank_del" style="color:var(--muted)">удалить</button></form></td></tr>';
        }
        $cfgOnly = !setting('banks') && crm_banks();
        mgr_page('Настройки', '<h1>Настройки</h1>'
            . '<div class="card cut"><h2>Реквизиты ИНФОТЕХ для счетов</h2><p class="muted">ООО «ИНФОТЕХ», ИНН 7811653356, КПП 470601001 — постоянные.</p><form method="post" class="svcform">' . lk_csrf_field() . '<input type="hidden" name="act" value="set_company">'
            . '<div class="row"><label>Юридический адрес<input name="address" value="' . h($co['address']) . '"></label><label>Телефон в счёте<input type="tel" name="phone" value="' . h($co['phone']) . '"></label></div>'
            . '<div class="row"><label>Руководитель (расшифровка)<input name="director" value="' . h($co['director']) . '" placeholder="Важенин А. Н."></label><label>Бухгалтер (расшифровка, пусто — без строки)<input name="accountant" value="' . h($co['accountant']) . '"></label></div>'
            . '<label>Дополнительный текст внизу счёта<textarea name="note" rows="2">' . h($co['note']) . '</textarea></label><button class="btn">Сохранить</button></form></div>'
            . '<div class="card cut"><h2>Расчётные счета</h2>' . ($cfgOnly ? '<p class="badge wait">Сейчас используется счёт из app/config.php. Добавьте его здесь, чтобы выбирать между счетами.</p>' : '')
            . ($b ? '<table class="tbl"><thead><tr><th>Банк</th><th>БИК</th><th>Корр. счёт</th><th>Расчётный счёт</th><th></th></tr></thead><tbody>' . $b . '</tbody></table>' : '')
            . '<form method="post" class="svcform" style="margin-top:14px">' . lk_csrf_field() . '<input type="hidden" name="act" value="set_bank"><div class="row"><label>Банк<input name="bname" required placeholder="ПАО Сбербанк"></label><label>БИК<input name="bik" inputmode="numeric" pattern="\d{9}" required></label></div>'
            . '<div class="row"><label>Корр. счёт<input name="corr" inputmode="numeric" pattern="\d{20}" required></label><label>Расчётный счёт<input name="account" inputmode="numeric" pattern="\d{20}" required></label></div>'
            . '<label class="chk"><input type="checkbox" name="def" value="1"> Сделать основным</label><button class="btn ghost">Добавить счёт</button></form></div>'
            . '<div class="card cut"><h2>Автозаполнение по ИНН (DaData)</h2><p>' . (crm_dadata_token() ? '<span class="badge ok">подключено</span>' : '<span class="badge wait">не подключено</span>')
            . ' Ключ — в личном кабинете dadata.ru → «API-ключ». Бесплатно до 10 000 запросов в день. Ключ хранится только на сервере.</p>'
            . '<form method="post" class="filters">' . lk_csrf_field() . '<input type="hidden" name="act" value="set_dadata"><label>API-ключ<input type="password" name="token" autocomplete="off" placeholder="' . (crm_dadata_token() ? 'сохранён — введите новый, чтобы заменить' : '40 символов') . '"></label><button class="btn ghost">Сохранить</button></form></div>');
    }
}
