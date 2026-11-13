#!/usr/bin/env python3
"""
Сборка многостраничного сайта ИНФОТЕХ из шаблонов.

    python3 tools/build_site.py

Источники:
  tools/site/partials/*.html — общие блоки (шапка, подвал, корзина, тарифы, магазин, калькулятор…)
  tools/site/services.py      — тексты страниц услуг
  tools/site/icons.json       — иконки услуг
  public/catalog.js           — каталог (для ссылок на разделы магазина)
Результат — HTML-страницы в public/. Стили и скрипт общие: public/assets/site.css, public/assets/site.js.
"""
import json, os, re, sys, html, datetime
BASE = os.path.dirname(os.path.abspath(__file__))
SITE = os.path.join(BASE, 'site')
PUB = os.path.join(os.path.dirname(BASE), 'public')
sys.path.insert(0, SITE)
from services import SERVICES, GROUPS  # noqa

DOMAIN = 'https://pbk.spb.ru'
ORG_LD = json.dumps({"@context": "https://schema.org", "@type": "Organization",
    "name": "ИНФОТЕХ", "legalName": "ООО «Информационные технологии»", "alternateName": ["ООО «ИНФОТЕХ»", "Информационные технологии"],
    "url": DOMAIN + "/", "logo": DOMAIN + "/logo-light-s.png", "email": "info@pbk.spb.ru", "telephone": "+7-812-928-68-84",
    "taxID": "7811653356", "identifier": "ОГРН 1177847218977",
    "address": {"@type": "PostalAddress", "postalCode": "188680", "addressRegion": "Ленинградская область",
                "addressLocality": "д. Токкари, Всеволожский р-н", "streetAddress": "ул. Центральная, д. 14Г", "addressCountry": "RU"}},
    ensure_ascii=False)
ICONS = json.load(open(os.path.join(SITE, 'icons.json'), encoding='utf-8'))
raw = open(os.path.join(PUB, 'catalog.js'), encoding='utf-8').read()
CAT = json.loads(raw[raw.index('window.CATALOG=') + 15:].rstrip(';\n '))
BY = {s['slug']: s for s in SERVICES}
E = html.escape

def part(name):
    return open(os.path.join(SITE, 'partials', name + '.html'), encoding='utf-8').read()

KICK = '<div class="kicker"><svg viewBox="0 0 14 18"><path d="M2 2l9 7-9 7" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round"/></svg>{}</div>'
ARROW = '<svg class="arrow" viewBox="0 0 18 12"><path d="M0 6h15M10 1l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2"/></svg>'

def icon(k, cls='ic', stroke='#4B7BA6'):
    return f'<svg class="{cls}" viewBox="0 0 48 48" fill="none" stroke="{stroke}" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">{ICONS.get(k, "")}</svg>'

def page(path, title, desc, body, nav='', service='', contact_title='Обсудим вашу IT-инфраструктуру'):
    R = '/'          # все ссылки от корня сайта: одинаково работают на любой странице
    HOME = '/'
    out = f'''<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{E(title)}</title>
<meta name="description" content="{E(desc)}">
<link rel="canonical" href="{DOMAIN}/{path.replace('index.html', '')}">
<meta property="og:title" content="{E(title)}"><meta property="og:description" content="{E(desc)}"><meta property="og:type" content="website">
<link rel="icon" href="{R}logo-light-s.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{R}assets/site.css">
<script type="application/ld+json">{ORG_LD}</script>
</head>
<body>
{part('header')}
{body}
{part('contact')}
{part('footer')}
{part('overlays')}
<script>window.ROOT={json.dumps(R)};</script>
<script src="{R}catalog.js"></script>
<script src="{R}assets/site.js"></script>
</body>
</html>
'''
    for k in ['about', 'uslugi', 'rent', 'shop', 'support']:
        out = out.replace('{{NAV_%s}}' % k, ' aria-current="page"' if k == nav else '')
    out = (out.replace('{ORG_LD}', ORG_LD).replace('{{R}}', R).replace('{{HOME}}', HOME).replace('{{SERVICE}}', E(service))
              .replace('{{CONTACT_TITLE}}', E(contact_title)))
    full = os.path.join(PUB, path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    open(full, 'w', encoding='utf-8').write(out)
    return path

def crumbs(items):
    return '<nav class="pcrumbs" aria-label="Навигация">' + ' <span>→</span> '.join(
        f'<a href="{h}">{E(t)}</a>' if h else f'<span aria-current="page">{E(t)}</span>' for t, h in items) + '</nav>'

def phero(title, lead, cr, btns='', ic=''):
    return f'''<section class="phero">
  <div class="wrap phero-grid">
    <div class="rv">{cr}<h1>{E(title)}</h1><p class="lead">{E(lead)}</p>{f'<div class="hero-cta">{btns}</div>' if btns else ''}</div>
    {f'<div class="phero-ic rv">{icon(ic, "big", "#CEE4F2")}</div>' if ic else ''}
  </div>
</section>
'''

def svc_card(s, R):
    return f'''<a class="svc-link outline cut" href="{R}uslugi/{s['slug']}/"><div class="inner cut">{icon(s['icon'])}<h4>{E(s['title'])}</h4><p>{E(s['short'])}</p><span class="more">Подробнее {ARROW}</span></div></a>'''

def cat_index(key):
    return next((i for i, c in enumerate(CAT['cats']) if c['k'] == key), None)

def shop_block(key, R):
    ci = cat_index(key)
    if ci is None:
        return ''
    c = CAT['cats'][ci]
    cnt = {}
    for it in CAT['items']:
        if it[2] == ci:
            cnt[it[3]] = cnt.get(it[3], 0) + 1
    chips = ''.join(f'<a class="chip cut" href="{R}magazin-1c/?cat={key}&amp;sub={E(sn)}">{E(sn)} <small>{cnt.get(si, 0)}</small></a>'
                    for si, sn in enumerate(c['s']) if cnt.get(si))
    extra = f'<a class="btn btn-primary cut" href="{R}magazin-1c/?e=1">Электронная поставка {ARROW}</a>' if key == 'products' else ''
    return f'''<section class="shopteaser">
  <div class="wrap">
    <div class="sec-head rv"><div>{KICK.format('Магазин 1С')}<h2 class="plaque cut-tl">{E(c['n'])} в магазине</h2></div>
    <p>{sum(cnt.values())} позиций с ценами по прайсу фирмы «1С» от {CAT['date']}. Выберите раздел — откроется каталог с поиском и корзиной.</p></div>
    <div class="chips rv">{chips}</div>
    <div class="hero-cta rv" style="margin-top:24px"><a class="btn btn-light cut" href="{R}magazin-1c/?cat={key}">Открыть каталог {ARROW}</a>{extra}</div>
  </div>
</section>
'''

def includes_block(s):
    cards = ''.join(f'<div class="svc outline cut rv"><div class="inner cut"><h4>{E(t)}</h4><p>{E(d)}</p></div></div>' for t, d in s['includes'])
    return f'''<section id="includes">
  <div class="wrap">
    <div class="sec-head rv"><div>{KICK.format('Состав услуги')}<h2 class="plaque cut-tl">Что входит</h2></div><p>{E(s['short'])}</p></div>
    <div class="cards">{cards}</div>
  </div>
</section>
'''

def steps_block(steps, title='Как мы работаем'):
    st = ''.join(f'<div class="step rv"><span class="pt"></span><small>{i:02d}</small><h4>{E(t)}</h4><p>{E(d)}</p></div>' for i, (t, d) in enumerate(steps, 1))
    return f'''<section style="padding-top:40px">
  <div class="wrap">
    <div class="sec-head rv"><div>{KICK.format('Порядок работы')}<h2 style="font-size:clamp(28px,3.4vw,42px)">{E(title)}</h2></div></div>
    <div class="steps">{st}</div>
  </div>
</section>
'''

def related_block(slugs, R):
    cards = ''.join(svc_card(BY[x], R) for x in slugs if x in BY)
    return f'''<section class="related">
  <div class="wrap">
    <div class="sec-head rv"><div>{KICK.format('Смотрите также')}<h2 style="font-size:clamp(26px,3vw,36px)">Связанные услуги</h2></div>
    <a class="btn btn-back cut" href="{R}uslugi/">Все услуги {ARROW}</a></div>
    <div class="cards">{cards}</div>
  </div>
</section>
'''

built = []

# ---------- главная ----------
dirs = ''
for gk, gn, gd in GROUPS:
    items = ''.join(f'<li><a href="/uslugi/{s["slug"]}/">{icon(s["icon"], "mini")}<span><b>{E(s["title"])}</b><small>{E(s["short"])}</small></span></a></li>'
                    for s in SERVICES if s['group'] == gk)
    dirs += f'<div class="dir outline cut rv"><div class="inner cut"><h3>{E(gn)}</h3><p>{E(gd)}</p><ul>{items}</ul></div></div>'
home_dirs = f'''<section id="napravleniya" style="background:#fff">
  <div class="wrap">
    <div class="sec-head rv"><div>{KICK.format('Направления')}<h2 class="plaque cut-tl">Услуги ИНФОТЕХ</h2></div>
    <p>Три направления, которые закрывают IT-потребности компании целиком: их можно заказать по отдельности или как единый комплекс.</p></div>
    <div class="dirs">{dirs}</div>
    <div class="hero-cta rv" style="margin-top:28px"><a class="btn btn-primary cut" href="/magazin-1c/">Магазин программ 1С {ARROW}</a><a class="btn btn-back cut" href="/uslugi/">Все услуги</a></div>
  </div>
</section>
'''
home = part('hero') + part('bus1') + part('about') + home_dirs + part('compare') + part('calc') + part('bus2') + part('steps')
built.append(page('index.html', 'ИНФОТЕХ — аренда серверов для 1С, программы 1С и IT-инфраструктура',
                  'Аренда серверов для 1С от 5 900 ₽/мес, продажа программ и лицензий 1С с электронной поставкой, внедрение, сопровождение и поставка оборудования. ООО «ИНФОТЕХ», Санкт-Петербург.',
                  home, nav='about'))

# ---------- магазин ----------
shop = part('shop').replace('<h2 class="plaque cut-tl">Программные продукты 1С</h2>', '<h2 class="plaque cut-tl">Каталог</h2>')
built.append(page('magazin-1c/index.html', 'Магазин программ 1С — лицензии, ИТС, сервисы, оборудование | ИНФОТЕХ',
                  'Программы и лицензии 1С по рекомендованным ценам фирмы «1С», электронная поставка на e-mail, 1С:ИТС, 1С:Фреш, сервисы и торговое оборудование. Быстрый поиск и корзина.',
                  phero('Магазин программ 1С', 'Лицензии и программы 1С, отраслевые решения, сервисы, 1С:ИТС и торговое оборудование по рекомендованным ценам фирмы «1С». Электронные поставки — на e-mail.',
                        crumbs([('Главная', '/'), ('Магазин 1С', '')]),
                        f'<a class="btn btn-light cut" href="/magazin-1c/?e=1">Электронная поставка {ARROW}</a><a class="btn btn-ghost" href="/uslugi/litsenzii-1c/">Помощь с выбором</a>') + shop,
                  nav='shop', service='Магазин 1С', contact_title='Поможем подобрать программы 1С'))

# ---------- хаб услуг ----------
hub = ''
for gk, gn, gd in GROUPS:
    cards = ''.join(svc_card(s, '/') for s in SERVICES if s['group'] == gk)
    hub += f'''<section id="{gk}" class="hubgrp">
  <div class="wrap">
    <div class="sec-head rv"><div>{KICK.format('Направление')}<h2 class="plaque cut-tl">{E(gn)}</h2></div><p>{E(gd)}</p></div>
    <div class="cards">{cards}</div>
  </div>
</section>
'''
built.append(page('uslugi/index.html', 'Услуги ИНФОТЕХ — 1С, аренда серверов, оборудование',
                  'Все услуги ИНФОТЕХ: лицензии и внедрение 1С, сопровождение, аренда серверов для 1С, резервное копирование, администрирование, поставка оборудования.',
                  phero('Услуги', 'Полный цикл IT-решений для бизнеса: от сервера и рабочих мест до бизнес-логики в 1С. Выберите услугу — на её странице состав работ, порядок и цены.',
                        crumbs([('Главная', '/'), ('Услуги', '')]),
                        f'<a class="btn btn-light cut" href="/uslugi/arenda-servera-1c/">Тарифы аренды серверов {ARROW}</a><a class="btn btn-ghost" href="/uslugi/soprovozhdenie-1c/">Сопровождение 1С</a>') + hub,
                  nav='uslugi'))

# ---------- страницы услуг ----------
NAVMAP = {'arenda-servera-1c': 'rent', 'soprovozhdenie-1c': 'support'}
for s in SERVICES:
    R = '/'
    gname = next(g[1] for g in GROUPS if g[0] == s['group'])
    btns = f'<a class="btn btn-light cut" href="#contact">Оставить заявку {ARROW}</a>'
    if s.get('cta2'):
        t, h = s['cta2']
        btns += f'<a class="btn btn-ghost" href="{h if h.startswith("#") else R + h}">{E(t)}</a>'
    body = phero(s['title'], s['lead'], crumbs([('Главная', R), ('Услуги', R + 'uslugi/'), (gname, R + 'uslugi/#' + s['group']), (s['title'], '')]), btns, s['icon'])
    body += includes_block(s)
    for b in s.get('blocks', []):
        if b == 'rent': body += part('rent')
        elif b == 'calc': body += part('calc')
        elif b == 'support': body += part('support')
        elif b == 'its': body += part('its')
        elif b.startswith('shop:'): body += shop_block(b.split(':', 1)[1], R)
    body += steps_block(s['steps'])
    body += related_block(s['related'], R)
    built.append(page(f"uslugi/{s['slug']}/index.html", f"{s['title']} — ИНФОТЕХ", s['lead'][:200], body,
                      nav=NAVMAP.get(s['slug'], 'uslugi'), service=s['title'], contact_title='Обсудим вашу задачу'))

# ---------- sitemap, robots ----------
today = datetime.date.today().isoformat()
urls = [p.replace('index.html', '') for p in built] + ['privacy.html']
open(os.path.join(PUB, 'sitemap.xml'), 'w', encoding='utf-8').write(
    '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    ''.join(f'  <url><loc>{DOMAIN}/{u}</loc><lastmod>{today}</lastmod></url>\n' for u in urls) + '</urlset>\n')
open(os.path.join(PUB, 'robots.txt'), 'w').write(f'User-agent: *\nDisallow: /admin/\nDisallow: /api/\nSitemap: {DOMAIN}/sitemap.xml\n')
print('Собрано страниц:', len(built))
