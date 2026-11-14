<?php
/* Коммерческое предложение в PDF (стиль ИНФОТЕХ): по заказу (?n=) — владельцу или менеджеру; по корзине (?cart=код:кол,…) — всем */
declare(strict_types=1);
require __DIR__ . '/../../app/lk.php';
require APP_DIR . '/vendor/tfpdf/font/unifont/ttfonts.php';
require APP_DIR . '/vendor/tfpdf/tfpdf.php';
lk_session();

$cat = catalog();
$items = []; $order = null; $client = '';
if (isset($_GET['n'])) {
    $u = lk_user();
    if (lk_is_manager()) { $st = lk_db()->prepare("SELECT * FROM orders WHERE number=? AND type='order'"); $st->execute([(string)$_GET['n']]); $order = $st->fetch() ?: null; }
    elseif ($u) $order = lk_order_for_user((string)$_GET['n'], (int)$u['id']);
    if (!$order) { http_response_code(404); exit('Заказ не найден'); }
    $items = lk_items($order);
    $client = $order['company'] ? $order['company'] . ($order['inn'] ? ', ИНН ' . $order['inn'] : '') : (string)$order['name'];
} else {
    if (lk_throttle('kp', 30, 3600)) { http_response_code(429); exit('Слишком много запросов'); }
    foreach (explode(',', (string)($_GET['cart'] ?? '')) as $pair) {
        [$code, $q] = array_pad(explode(':', $pair, 2), 2, '1');
        $p = $cat[$code] ?? null; $q = max(1, min(9999, (int)$q));
        if ($p && count($items) < 100) $items[] = ['code' => $code, 'name' => $p['name'], 'qty' => $q, 'price' => $p['price'], 'cur' => $p['cur'], 'sum' => $p['price'] * $q];
    }
    if (!$items) { http_response_code(400); exit('Корзина пуста'); }
}

class KP extends tFPDF {
    function Footer(): void {
        $this->SetY(-16); $this->SetDrawColor(206, 228, 242); $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetFont('Inter', '', 7.5); $this->SetTextColor(106, 131, 151);
        $this->Cell(0, 6, 'ООО «ИНФОТЕХ» · ИНН 7811653356 · КПП 470601001 · ОГРН 1177847218977 · 8 (812) 928-68-84 · info@pbk.spb.ru · pbk.spb.ru', 0, 0, 'L');
        $this->Cell(0, 6, 'Стр. ' . $this->PageNo(), 0, 0, 'R');
    }
    /* строка таблицы с переносом наименования */
    function Row(array $w, array $cells, array $align, float $lh = 5): void {
        $nb = 1;
        foreach ($cells as $i => $t) $nb = max($nb, $this->Lines($w[$i], (string)$t));
        $h = $lh * $nb + 3;
        if ($this->GetY() + $h > $this->PageBreakTrigger) $this->AddPage();
        $x = $this->GetX(); $y = $this->GetY();
        foreach ($cells as $i => $t) {
            $this->SetXY($x, $y + 1.5); $this->MultiCell($w[$i], $lh, (string)$t, 0, $align[$i]);
            $x += $w[$i];
        }
        $this->SetDrawColor(206, 228, 242); $this->Line(15, $y + $h, 195, $y + $h); $this->SetXY(15, $y + $h);
    }
    function Lines(float $w, string $t): int {
        $words = preg_split('/\s+/u', $t); $n = 1; $line = '';
        foreach ($words as $wd) { $try = $line === '' ? $wd : "$line $wd"; if ($this->GetStringWidth($try) > $w - 2) { $n++; $line = $wd; } else $line = $try; }
        return $n;
    }
}
$pdf = new KP('P', 'mm', 'A4');
$pdf->SetTitle('Коммерческое предложение ИНФОТЕХ', true);
$pdf->SetAuthor('ООО «ИНФОТЕХ»', true);
$pdf->AddFont('Inter', '', 'Inter-Regular.ttf', true);
$pdf->AddFont('Inter', 'B', 'Inter-SemiBold.ttf', true);
$pdf->SetMargins(15, 15, 15); $pdf->SetAutoPageBreak(true, 22); $pdf->AddPage();

/* шапка: тёмная плашка со скошенным углом, логотип, линия «печатной платы» */
$pdf->SetFillColor(0, 28, 27); $pdf->Rect(0, 0, 210, 38, 'F');
$pdf->SetFillColor(75, 123, 166); $pdf->Rect(0, 38, 210, 1.2, 'F');
$pdf->Image(APP_DIR . '/vendor/kp-logo-dark.jpg', 15, 11, 70);
$pdf->SetTextColor(206, 228, 242); $pdf->SetFont('Inter', '', 9);
$pdf->SetXY(120, 11); $pdf->MultiCell(75, 5, "8 (812) 928-68-84\ninfo@pbk.spb.ru\npbk.spb.ru", 0, 'R');
$pdf->SetDrawColor(75, 123, 166); $pdf->SetLineWidth(0.4);
$pdf->Line(15, 47, 80, 47); $pdf->Line(80, 47, 86, 44); $pdf->Line(86, 44, 120, 44); $pdf->Line(120, 44, 126, 47); $pdf->Line(126, 47, 195, 47);
$pdf->SetFillColor(75, 123, 166); $pdf->Rect(79, 46, 2, 2, 'F'); $pdf->Rect(125, 46, 2, 2, 'F');
$pdf->SetLineWidth(0.2);

$num = 'КП-' . ($order ? $order['number'] : date('ymd-Hi'));
$pdf->SetXY(15, 54); $pdf->SetTextColor(0, 28, 27); $pdf->SetFont('Inter', 'B', 18);
$pdf->Cell(0, 9, 'Коммерческое предложение', 0, 1);
$pdf->SetFont('Inter', '', 10); $pdf->SetTextColor(75, 123, 166);
$pdf->Cell(0, 6, "№ $num от " . date('d.m.Y') . ($order ? ' · по заказу № ' . $order['number'] : ''), 0, 1);
if ($client) { $pdf->SetTextColor(0, 28, 27); $pdf->Cell(0, 6, 'Для: ' . $client, 0, 1); }
$pdf->Ln(5);

/* спецификация */
$w = [9, 83, 30, 14, 22, 22]; $al = ['C', 'L', 'L', 'C', 'R', 'R'];
$pdf->SetFillColor(206, 228, 242); $pdf->SetTextColor(0, 28, 27); $pdf->SetFont('Inter', 'B', 8.5);
foreach (['№', 'Наименование', 'Артикул', 'Кол-во', 'Цена', 'Сумма'] as $i => $t) $pdf->Cell($w[$i], 8, $t, 0, 0, $al[$i] === 'L' ? 'L' : 'C', true);
$pdf->Ln(); $pdf->SetFont('Inter', '', 8.5);
$rub = 0.0; $usd = 0.0; $hw = false; $pre = false;
$fmt = fn($v, $cur) => $cur === 'USD' ? '$' . number_format((float)$v, 2, '.', ' ') : number_format((float)$v, 0, ',', ' ') . ' ₽';
foreach ($items as $i => $it) {
    $cur = $it['cur'] ?? 'RUB'; $cur === 'USD' ? $usd += $it['sum'] : $rub += $it['sum'];
    $m = lk_product_meta((string)$it['code']); $p = $cat[$it['code']] ?? [];
    if (lk_is_hw_item($it)) $hw = true;
    if (($p['in_stock'] ?? 1) === 2) $pre = true;
    $name = $it['name'] . ($m['warranty'] ? "\nГарантия: " . $m['warranty'] : '') . (($p['in_stock'] ?? 1) === 2 ? "\nПод заказ — срок подтверждает менеджер" : '');
    $pdf->Row($w, [$i + 1, $name, $it['code'], $it['qty'], $fmt($it['price'], $cur), $fmt($it['sum'], $cur)], $al);
}
$pdf->Ln(3); $pdf->SetFont('Inter', 'B', 12); $pdf->SetTextColor(0, 28, 27);
$pdf->Cell(0, 8, 'Итого: ' . number_format($rub, 0, ',', ' ') . ' ₽' . ($usd ? ' + $' . number_format($usd, 2, '.', ' ') . ' (по курсу ЦБ на дату оплаты)' : ''), 0, 1, 'R');
$pdf->SetFont('Inter', '', 9); $pdf->SetTextColor(106, 131, 151);
$pdf->Cell(0, 5, 'НДС не облагается: ООО «ИНФОТЕХ» применяет упрощённую систему налогообложения.', 0, 1, 'R');
$pdf->Ln(6);

/* условия */
$cond = [
    'Цены' => 'Указаны предварительно по текущему прайсу. Окончательная стоимость фиксируется в счёте.' . ($hw ? ' Счёт на оборудование и комплектующие действует 3 банковских дня — после этого цены пересчитываются по актуальному прайсу.' : ''),
    'Сроки поставки' => 'Позиции в наличии отгружаются после поступления оплаты.' . ($pre ? ' Позиции «под заказ» поставляются со склада дистрибьютора, срок подтверждает менеджер перед выставлением счёта.' : '') . ' Электронные лицензии 1С направляются на e-mail.',
] + ($hw ? ['Гарантия' => 'Официальная гарантия производителя на оборудование; сроки указаны в спецификации или в карточках товаров на сайте.'] : []) + [
    'Оплата' => 'Безналичная оплата по счёту. Документы для бухгалтерии: счёт, УПД / акт.',
];
foreach ($cond as $k => $v) {
    if ($pdf->GetY() > 255) $pdf->AddPage();
    $pdf->SetFillColor(75, 123, 166); $pdf->Rect(15, $pdf->GetY() + 1.5, 1.5, 1.5, 'F');
    $pdf->SetX(19); $pdf->SetFont('Inter', 'B', 9.5); $pdf->SetTextColor(0, 28, 27); $pdf->Cell(0, 5.5, $k, 0, 1);
    $pdf->SetX(19); $pdf->SetFont('Inter', '', 9); $pdf->SetTextColor(40, 60, 70); $pdf->MultiCell(176, 4.8, $v); $pdf->Ln(2.5);
}
$pdf->Ln(4); $pdf->SetFont('Inter', '', 9); $pdf->SetTextColor(0, 28, 27);
$pdf->MultiCell(0, 5, "С уважением, ООО «ИНФОТЕХ»\nПартнёр фирмы «1С» · 8 (812) 928-68-84 · info@pbk.spb.ru");

header('Cache-Control: private, no-store');
$pdf->Output('I', "$num.pdf", true);
