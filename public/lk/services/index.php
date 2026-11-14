<?php
declare(strict_types=1);
require __DIR__ . '/../../../app/lk.php';
$u = lk_require();
$body = '<h1>Серверы и услуги 1С</h1>' . lk_trace()
    . '<div class="card cut"><h2>Скоро в кабинете</h2><p>Здесь появятся арендованные серверы (тариф, конфигурация, оплаченный период, продление, запрос дополнительных ресурсов) и договоры сопровождения 1С:ИТС с остатком часов специалиста.</p>'
    . '<p>Пока по вопросам аренды и сопровождения — ваш менеджер: <a href="tel:+78129286884">8 (812) 928-68-84</a>, <a href="mailto:info@pbk.spb.ru">info@pbk.spb.ru</a>.</p>'
    . '<div class="acts"><a class="btn" href="/uslugi/arenda-servera-1c/">Тарифы аренды серверов</a><a class="btn ghost" href="/uslugi/soprovozhdenie-1c/">Тарифы сопровождения</a><a class="btn ghost" href="/lk/support/">Создать обращение</a></div></div>';
lk_page('Серверы и 1С', 'services', $body, $u);
