<?php
/* Доступ клиентов аренды: OpenVPN-учётка (PPP secret) на MikroTik.
   Закрыть доступ = secret disabled=yes + разорвать активное подключение; открыть = disabled=no.
   Настройки — app/config.php:
     'mikrotik' => ['url' => 'https://192.168.88.1', 'user' => 'crm', 'pass' => '…', 'verify_tls' => true],
   REST API RouterOS 7.1+ (IP → Services → www-ssl). Отдельный пользователь с группой: policy=read,write,api,rest-api,
   доступ разрешён только с IP сервера сайта. Без настроек CRM работает вручную: списки «Закрыть / открыть доступ». */
declare(strict_types=1);

function mt_configured(): bool {
    $c = cfg('mikrotik');
    return is_array($c) && !empty($c['url']) && !empty($c['user']);
}
function mt_req(string $method, string $path, ?array $body = null): array {
    $c = cfg('mikrotik');
    $ch = curl_init(rtrim((string)$c['url'], '/') . '/rest' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERPWD => $c['user'] . ':' . ($c['pass'] ?? ''), CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => ($c['verify_tls'] ?? true) !== false, CURLOPT_SSL_VERIFYHOST => ($c['verify_tls'] ?? true) !== false ? 2 : 0,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code >= 400) throw new RuntimeException("MikroTik $method $path: " . ($err ?: "HTTP $code " . mb_substr((string)$raw, 0, 200)));
    return json_decode((string)$raw, true) ?: [];
}
function mt_id(string $id): string { return str_replace('%2A', '*', rawurlencode($id)); }   // .id вида *1A — «*» в пути допустима
/* $open=false — закрыть доступ, true — открыть. Возвращает текст для журнала. */
function mt_set_access(string $login, bool $open): string {
    if ($login === '' || !preg_match('/^[\w.@-]{1,64}$/u', $login)) throw new RuntimeException('Не указана VPN-учётка клиента');
    $q = '?name=' . rawurlencode($login);
    $sec = mt_req('GET', '/ppp/secret' . $q);
    if (!$sec) throw new RuntimeException("На MikroTik нет учётки «{$login}»");
    mt_req('PATCH', '/ppp/secret/' . mt_id((string)$sec[0]['.id']), ['disabled' => $open ? 'no' : 'yes']);
    $kicked = 0;
    if (!$open) foreach (mt_req('GET', '/ppp/active' . $q) as $a) { mt_req('DELETE', '/ppp/active/' . mt_id((string)$a['.id'])); $kicked++; }
    return ($open ? 'доступ открыт' : 'доступ закрыт') . " на MikroTik ({$login})" . ($kicked ? ", разорвано подключений: $kicked" : '');
}
