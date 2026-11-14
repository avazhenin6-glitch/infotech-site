<?php
/* Производственный календарь РФ (пятидневка): праздники и переносы.
   2026 — постановление Правительства РФ от 24.09.2025 № 1466; 2027 — от 17.09.2026 № 1187.
   Добавить год или региональный праздник без правки кода: 'holidays' => ['2028-01-02', …], 'workdays' => ['2028-…'] в app/config.php. */
declare(strict_types=1);

const WC_HOLIDAYS = [   // нерабочие дни, выпадающие на пн–пт
    '2026-01-01', '2026-01-02', '2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09',
    '2026-02-23', '2026-03-09', '2026-05-01', '2026-05-11', '2026-06-12', '2026-11-04', '2026-12-31',
    '2027-01-01', '2027-01-04', '2027-01-05', '2027-01-06', '2027-01-07', '2027-01-08',
    '2027-02-22', '2027-02-23', '2027-03-08', '2027-05-03', '2027-05-10', '2027-06-14', '2027-11-04', '2027-11-05', '2027-12-31',
];
const WC_WORKDAYS = ['2027-02-20'];   // рабочие субботы

function wc_is_workday(string $date): bool {
    static $hol = null, $work = null;
    if ($hol === null) {
        $hol = array_flip(array_merge(WC_HOLIDAYS, (array)(cfg('holidays') ?? [])));
        $work = array_flip(array_merge(WC_WORKDAYS, (array)(cfg('workdays') ?? [])));
    }
    $d = substr($date, 0, 10);
    if (isset($work[$d])) return true;
    if (isset($hol[$d])) return false;
    return (int)date('N', strtotime($d)) < 6;
}
/* n рабочих дней после даты (сама дата не считается) */
function wc_add_workdays(string $from, int $n): string {
    $t = strtotime(substr($from, 0, 10));
    while ($n > 0) { $t = strtotime('+1 day', $t); if (wc_is_workday(date('Y-m-d', $t))) $n--; }
    return date('Y-m-d', $t);
}
/* n-й рабочий день месяца 'YYYY-MM' */
function wc_nth_workday(string $ym, int $n): string {
    $t = strtotime("$ym-01");
    for (;;) { if (wc_is_workday(date('Y-m-d', $t)) && --$n === 0) return date('Y-m-d', $t); $t = strtotime('+1 day', $t); }
}
/* предыдущий рабочий день */
function wc_prev_workday(string $date): string {
    $t = strtotime(substr($date, 0, 10));
    do { $t = strtotime('-1 day', $t); } while (!wc_is_workday(date('Y-m-d', $t)));
    return date('Y-m-d', $t);
}
