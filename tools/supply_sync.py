#!/usr/bin/env python3
"""
Каталог оборудования ИНФОТЕХ: импорт от дистрибьюторов → витрина pbk.spb.ru.

Регламент (CLAUDE.md): источник — официальные B2B-каналы (API, YML/XML, CSV, Excel).
Исключение до подключения API: тип источника "site" — вежливый сбор с открытого сайта
(robots.txt, честный User-Agent, паузы, остановка при отказе, не больше --limit товаров).

Команды:
  python3 tools/supply_sync.py probe  [--supplier ID] [--url URL]   — проверить источник, ничего не записывая
  python3 tools/supply_sync.py run    [--supplier ID] [--limit N]   — обновить каталог
  python3 tools/supply_sync.py status                                — последний запуск
  python3 tools/supply_sync.py render                                — пересобрать витрину из базы без загрузки

Только стандартная библиотека Python 3.9+ (Excel — если установлен openpyxl).
"""
import argparse, csv, datetime, gzip, hashlib, html, io, json, math, os, random, re, sqlite3, subprocess, sys, time
import urllib.error, urllib.parse, urllib.request, urllib.robotparser
import xml.etree.ElementTree as ET
from html.parser import HTMLParser

TOOLS = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(TOOLS)
PUB = os.path.join(ROOT, 'public')
DATA = os.path.join(ROOT, 'app', 'data')
DOMAIN = 'https://pbk.spb.ru'
UA = 'PBK-CatalogSync/1.0 (+https://pbk.spb.ru; info@pbk.spb.ru)'
LOG = os.path.join(DATA, 'parser_sync.log')
NOW = lambda: datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')


# ---------------------------------------------------------------- журнал и уведомления
def log(msg):
    line = f'{NOW()}  {msg}'
    print(line, flush=True)
    os.makedirs(DATA, exist_ok=True)
    try:
        if os.path.exists(LOG) and os.path.getsize(LOG) > 5_000_000:
            os.replace(LOG, LOG + '.1')
        with open(LOG, 'a', encoding='utf-8') as f:
            f.write(line + '\n')
    except OSError:
        pass


def notify(subject, text):
    """Письмо и Telegram через app/cli-notify.php (без персональных данных)."""
    php = os.path.join(ROOT, 'app', 'cli-notify.php')
    if not os.path.exists(php):
        return
    try:
        subprocess.run(['php', php, subject, text], timeout=90, capture_output=True)
    except Exception as e:  # уведомление не должно ронять синхронизацию
        log(f'уведомление не отправлено: {e}')


# ---------------------------------------------------------------- сеть
class Blocked(Exception):
    pass


class Fetcher:
    def __init__(self, delay=1.0, auth_headers=None):
        self.delay = delay
        self.last = 0.0
        self.headers = {'User-Agent': UA, 'Accept-Language': 'ru-RU,ru;q=0.9'}
        self.headers.update(auth_headers or {})
        self.count = 0

    def get(self, url, binary=False, max_bytes=None):
        wait = self.delay - (time.time() - self.last)
        if wait > 0:
            time.sleep(wait)
        for attempt in range(3):
            self.last = time.time()
            req = urllib.request.Request(url, headers=self.headers)
            try:
                with urllib.request.urlopen(req, timeout=40) as r:
                    self.count += 1
                    data = r.read(max_bytes + 1 if max_bytes else -1)
                    ctype = r.headers.get('Content-Type', '')
                    if r.headers.get('Content-Encoding') == 'gzip' or url.endswith('.gz'):
                        try:
                            data = gzip.decompress(data)
                        except OSError:
                            pass
                    if binary:
                        return data, ctype
                    cs = re.search(r'charset=([\w-]+)', ctype)
                    return data.decode(cs.group(1) if cs else 'utf-8', 'replace'), ctype
            except urllib.error.HTTPError as e:
                if e.code in (401, 403):
                    raise Blocked(f'{e.code} — доступ запрещён ({url})')
                if e.code in (429, 503) and attempt < 2:
                    ra = e.headers.get('Retry-After', '')
                    pause = int(ra) if ra.isdigit() else 30 * (attempt + 1)
                    if pause > 600:
                        raise Blocked(f'{e.code} — источник просит паузу {pause} с')
                    log(f'  {e.code}, пауза {pause} с и повтор')
                    time.sleep(pause)
                    continue
                raise
            except urllib.error.URLError:
                if attempt < 2:
                    time.sleep(10)
                    continue
                raise
        raise RuntimeError(f'не удалось загрузить {url}')


# ---------------------------------------------------------------- разбор HTML-страницы товара (schema.org / OpenGraph / таблицы)
class PageParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.ld, self.meta, self.links, self.tables, self.dl = [], {}, [], [], []
        self.itemprops = {}
        self._script = None
        self._cap = []          # открытые itemprop без content: [имя, глубина, текст]
        self._depth = 0
        self._table = None
        self._row = None
        self._cell = None
        self._dt = None
        self._dd = None
        self.title = ''
        self._in_title = False

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        self._depth += 1
        if tag == 'script' and 'ld+json' in (a.get('type') or ''):
            self._script = []
        if tag == 'title':
            self._in_title = True
        if tag == 'meta':
            k = a.get('property') or a.get('name') or a.get('itemprop')
            if k and a.get('content'):
                self.meta.setdefault(k.lower(), a['content'])
        if tag == 'a' and a.get('href'):
            self.links.append(a['href'])
        ip = a.get('itemprop')
        if ip:
            val = a.get('content') or a.get('href') or a.get('src')
            if val:
                self.itemprops.setdefault(ip, val)
            elif tag not in ('img', 'link', 'meta'):
                self._cap.append([ip, self._depth, []])
        if tag == 'table':
            self._table = []
        elif tag == 'tr' and self._table is not None:
            self._row = []
        elif tag in ('td', 'th') and self._row is not None:
            self._cell = []
        elif tag == 'dt':
            self._dt = []
        elif tag == 'dd':
            self._dd = []
        if tag in ('br', 'img', 'meta', 'link', 'input', 'hr'):
            self._depth -= 1

    def handle_endtag(self, tag):
        if tag == 'script' and self._script is not None:
            self.ld.append(''.join(self._script))
            self._script = None
        if tag == 'title':
            self._in_title = False
        if tag in ('td', 'th') and self._cell is not None and self._row is not None:
            self._row.append(' '.join(''.join(self._cell).split()))
            self._cell = None
        elif tag == 'tr' and self._row is not None and self._table is not None:
            if self._row:
                self._table.append(self._row)
            self._row = None
        elif tag == 'table' and self._table is not None:
            self.tables.append(self._table)
            self._table = None
        elif tag == 'dt' and self._dt is not None:
            self._dtv = ' '.join(''.join(self._dt).split())
            self._dt = None
        elif tag == 'dd' and self._dd is not None:
            self.dl.append([getattr(self, '_dtv', ''), ' '.join(''.join(self._dd).split())])
            self._dd = None
        while self._cap and self._cap[-1][1] >= self._depth:
            ip, _, txt = self._cap.pop()
            self.itemprops.setdefault(ip, ' '.join(''.join(txt).split()))
        self._depth -= 1

    def handle_data(self, d):
        if self._script is not None:
            self._script.append(d)
            return
        if self._in_title:
            self.title += d
        for c in self._cap:
            c[2].append(d)
        if self._cell is not None:
            self._cell.append(d)
        if self._dt is not None:
            self._dt.append(d)
        if self._dd is not None:
            self._dd.append(d)


def _ld_objects(texts):
    out = []
    for t in texts:
        try:
            j = json.loads(t.strip())
        except Exception:
            continue
        stack = [j]
        while stack:
            x = stack.pop()
            if isinstance(x, list):
                stack.extend(x)
            elif isinstance(x, dict):
                out.append(x)
                if '@graph' in x:
                    stack.append(x['@graph'])
    return out


def _types(o):
    t = o.get('@type')
    return [t] if isinstance(t, str) else (t or [])


def _num(v):
    if v is None:
        return None
    s = re.sub(r'[^\d,.]', '', str(v).replace('\xa0', '')).replace(',', '.')
    if s.count('.') > 1:
        s = s.replace('.', '', s.count('.') - 1)
    try:
        return float(s) if s else None
    except ValueError:
        return None


def parse_product(page_html, url, overrides=None):
    """Товар со страницы: только из стандартной разметки schema.org/OpenGraph и таблиц характеристик."""
    ov = overrides or {}
    p = PageParser()
    try:
        p.feed(page_html)
    except Exception:
        pass
    objs = _ld_objects(p.ld)
    prod = next((o for o in objs if 'Product' in _types(o)), None)
    crumbs = []
    bl = next((o for o in objs if 'BreadcrumbList' in _types(o)), None)
    if bl:
        for it in sorted(bl.get('itemListElement') or [], key=lambda i: int(i.get('position', 0)) if isinstance(i, dict) else 0):
            if isinstance(it, dict):
                n = it.get('name')
                if not n and isinstance(it.get('item'), dict):
                    n = it['item'].get('name')
                if n:
                    crumbs.append(html.unescape(str(n)).strip())
    name = sku = desc = vendor = None
    price = None
    stock = None
    images = []
    if prod:
        name = prod.get('name')
        sku = prod.get('sku') or prod.get('mpn') or prod.get('productID')
        desc = prod.get('description')
        b = prod.get('brand')
        vendor = b.get('name') if isinstance(b, dict) else b
        mpn = prod.get('mpn')
        img = prod.get('image')
        for i in (img if isinstance(img, list) else [img]):
            if isinstance(i, dict):
                i = i.get('url') or i.get('contentUrl')
            if i:
                images.append(str(i))
        offers = prod.get('offers')
        offers = offers if isinstance(offers, list) else [offers] if offers else []
        for o in offers:
            if not isinstance(o, dict):
                continue
            price = price or _num(o.get('price') or o.get('lowPrice'))
            av = str(o.get('availability') or '')
            if av:
                stock = 1 if re.search(r'InStock|LimitedAvailability|OnlineOnly', av) else 0
    else:
        mpn = None
    ip = p.itemprops
    name = name or ip.get('name') or p.meta.get('og:title') or p.title.strip()
    sku = sku or ip.get('sku') or ip.get('mpn') or ip.get('productID')
    price = price or _num(ip.get('price'))
    if stock is None and ip.get('availability'):
        stock = 1 if re.search(r'InStock', ip['availability']) else 0
    if not images:
        if ip.get('image'):
            images.append(ip['image'])
        elif p.meta.get('og:image'):
            images.append(p.meta['og:image'])
    desc = desc or ip.get('description') or p.meta.get('og:description') or p.meta.get('description')
    text = re.sub(r'<[^>]+>', ' ', page_html)
    if price is None and ov.get('price_regex'):
        m = re.search(ov['price_regex'], page_html, re.S | re.I)
        price = _num(m.group(1)) if m else None
    if stock is None:
        if ov.get('stock_out_regex') and re.search(ov['stock_out_regex'], text, re.I):
            stock = 0
        elif ov.get('stock_in_regex') and re.search(ov['stock_in_regex'], text, re.I):
            stock = 1
    if not sku and ov.get('sku_regex'):
        m = re.search(ov['sku_regex'], page_html, re.S | re.I)
        sku = m.group(1).strip() if m else None
    # характеристики: самая большая таблица «параметр — значение» или списки dt/dd
    specs = []
    pairs = [t for t in p.tables if sum(1 for r in t if len(r) == 2) >= 3]
    if pairs:
        best = max(pairs, key=lambda t: sum(1 for r in t if len(r) == 2))
        specs = [[r[0].rstrip(':'), r[1]] for r in best if len(r) == 2 and r[0] and r[1]]
    if len(p.dl) > len(specs):
        specs = [[k.rstrip(':'), v] for k, v in p.dl if k and v]
    if name and crumbs and crumbs[-1].strip().lower() == str(name).strip().lower():
        crumbs = crumbs[:-1]
    if crumbs and re.match(r'(главная|home|каталог)$', crumbs[0], re.I):
        crumbs = crumbs[1:]
    clean = lambda s: html.unescape(re.sub(r'<[^>]+>', ' ', str(s or ''))).strip()
    return {
        'supplier_code': clean(sku) or ('URL-' + hashlib.md5(url.encode()).hexdigest()[:10]),
        'part_number': clean(mpn or ''),
        'vendor': clean(vendor),
        'name': ' '.join(clean(name).split()),
        'category': ' / '.join(crumbs),
        'price_in': price,
        'stock': stock,
        'images': [urllib.parse.urljoin(url, i) for i in images][:8],
        'description': re.sub(r'\s+([.,;:!?])', r'\1', ' '.join(clean(desc).split()))[:4000],
        'specs': specs[:80],
        'url': url,
        '_has_product_markup': bool(prod or ip.get('price')),
    }


# ---------------------------------------------------------------- источники
def src_site(sup, limit, fetch, probe=False, probe_url=None):
    """ВРЕМЕННО (до API): вежливый сбор с открытого сайта. robots.txt соблюдается, при отказе — остановка."""
    base = sup['source'].rstrip('/')
    rp = urllib.robotparser.RobotFileParser()
    try:
        robots, _ = fetch.get(base + '/robots.txt')
        rp.parse(robots.splitlines())
    except Blocked:
        raise
    except Exception as e:
        robots = ''
        rp.parse([])
        log(f'  robots.txt не получен: {e}')
    cd = rp.crawl_delay(UA) or rp.crawl_delay('*')
    if cd:
        fetch.delay = max(fetch.delay, float(cd))
    lo, hi = sup.get('delay_sec', [2, 4])
    fetch.delay = max(fetch.delay, float(lo))
    allowed = lambda u: rp.can_fetch(UA, u)
    prod_re = re.compile(sup.get('product_url_regex') or r'/(catalog|product|products|goods|item|tovar|p)/[^?#]+', re.I)
    inc = [re.compile(x, re.I) for x in sup.get('include_categories', [])]
    report = {'robots_found': bool(robots), 'crawl_delay': cd, 'robots_txt': robots.splitlines()[:40] if probe else None,
              'sitemaps': [], 'sitemaps_read': [], 'urls_in_sitemaps': 0, 'sample_urls': [], 'candidates': 0, 'samples': []}

    def urls_from_sitemaps():
        maps = list(sup.get('sitemaps') or rp.site_maps() or [base + '/sitemap.xml'])
        report['sitemaps'] = maps[:10]
        seen_maps, out = set(), []
        while maps and len(seen_maps) < (8 if probe else 30) and len(out) < 50000:
            m = maps.pop(0)
            if m in seen_maps or not allowed(m):
                continue
            seen_maps.add(m)
            try:
                xml, _ = fetch.get(m)
                root = ET.fromstring(xml.encode('utf-8', 'replace'))
            except Exception as e:
                log(f'  sitemap {m}: {e}')
                continue
            for el in root:                      # только <url><loc> / <sitemap><loc>, без <image:loc>
                loc = next((ch for ch in el if ch.tag.split('}')[-1] == 'loc'), None)
                if loc is not None and loc.text:
                    (maps if root.tag.endswith('sitemapindex') else out).append(loc.text.strip())
        report['sitemaps_read'] = sorted(seen_maps)[:40]
        report['urls_in_sitemaps'] = len(out)
        step = max(1, len(out) // 30)
        report['sample_urls'] = out[::step][:30]
        return out

    def urls_from_start():
        out, queue, seen = [], list(sup.get('start_urls') or []), set()
        while queue and len(seen) < sup.get('max_category_pages', 30) and len(out) < limit * 5:
            u = queue.pop(0)
            if u in seen or not allowed(u):
                continue
            seen.add(u)
            try:
                page, _ = fetch.get(u)
            except Blocked:
                raise
            except Exception as e:
                log(f'  {u}: {e}')
                continue
            pp = PageParser(); pp.feed(page)
            for h in pp.links:
                h = urllib.parse.urljoin(u, h).split('#')[0]
                if not h.startswith(base):
                    continue
                if prod_re.search(h) and h not in out:
                    out.append(h)
                elif sup.get('pagination_regex') and re.search(sup['pagination_regex'], h) and h not in seen:
                    queue.append(h)
        return out

    if probe_url:
        cands = [probe_url]
    else:
        cands = urls_from_start() if sup.get('start_urls') else [u for u in urls_from_sitemaps() if prod_re.search(u)]
    report['candidates'] = len(cands)
    offers, tried = [], 0
    max_try = 6 if probe else limit * 4
    for u in cands:
        if len(offers) >= (3 if probe else limit) or tried >= max_try:
            break
        if not allowed(u):
            continue
        tried += 1
        page, _ = fetch.get(u)
        o = parse_product(page, u, sup.get('overrides'))
        if probe:
            report['samples'].append({k: (v if k != 'description' else v[:200]) for k, v in o.items()})
        if not o.pop('_has_product_markup') or not o['name'] or not o['price_in']:
            continue
        if inc and not any(r.search(o['category']) for r in inc):
            continue
        offers.append(o)
    return (report if probe else offers)


def _auth(sup, secrets):
    a = sup.get('auth') or {}
    val = secrets.get(a.get('secret', ''), '')
    return {a['header']: val} if a.get('header') and val else {}


def _open_source(sup, fetch):
    s = sup['source']
    if re.match(r'https?://', s):
        data, _ = fetch.get(s, binary=True)
        return data
    with open(s, 'rb') as f:
        return f.read()


def src_yml(sup, limit, fetch):
    """YML (Яндекс.Маркет) / XML-фид: shop/categories + offers/offer."""
    root = ET.fromstring(_open_source(sup, fetch))
    cats, parent = {}, {}
    for c in root.iter('category'):
        cats[c.get('id')] = (c.text or '').strip()
        parent[c.get('id')] = c.get('parentId')

    def path(cid):
        out, n = [], 0
        while cid and cid in cats and n < 10:
            out.insert(0, cats[cid]); cid = parent.get(cid); n += 1
        return ' / '.join(out)

    stock_param = (sup.get('stock_param') or 'остаток').lower()
    offers = []
    for o in root.iter('offer'):
        g = lambda t: (o.findtext(t) or '').strip()
        params = [[pp.get('name', ''), (pp.text or '').strip()] for pp in o.findall('param')]
        stock = None
        for k, v in params:
            if k.lower() == stock_param:
                stock = int(_num(v) or 0)
        for t in ('stock', 'quantity', 'count', 'outlets/outlet'):
            el = o.find(t)
            if stock is None and el is not None:
                stock = int(_num(el.get('instock') or el.text) or 0)
        if stock is None:
            stock = 1 if o.get('available', 'true') != 'false' else 0
        name = g('name') or ' '.join(x for x in (g('typePrefix'), g('vendor'), g('model')) if x)
        offers.append({
            'supplier_code': o.get('id') or g('vendorCode'), 'part_number': g('vendorCode'), 'vendor': g('vendor'),
            'name': name, 'category': path(g('categoryId')), 'price_in': _num(g('price')), 'stock': stock,
            'images': [p.text.strip() for p in o.findall('picture') if p.text][:8],
            'description': ' '.join(re.sub(r'<[^>]+>', ' ', html.unescape(g('description'))).split())[:4000],
            'specs': [p for p in params if p[0].lower() != stock_param][:80], 'url': g('url')})
        if limit and len(offers) >= limit:
            break
    return offers


def src_csv(sup, limit, fetch):
    """CSV или Excel-прайс; соответствие колонок — в настройках поставщика (columns)."""
    raw = _open_source(sup, fetch)
    col = sup.get('columns', {})
    if sup['source'].lower().endswith(('.xlsx', '.xlsm')):
        import openpyxl  # apt install python3-openpyxl
        ws = openpyxl.load_workbook(io.BytesIO(raw), read_only=True, data_only=True).active
        it = ws.iter_rows(values_only=True)
        head = [str(h or '').strip() for h in next(it)]
        rows = (dict(zip(head, ['' if v is None else str(v) for v in r])) for r in it)
    else:
        txt = raw.decode(sup.get('encoding', 'utf-8-sig'), 'replace')
        rows = csv.DictReader(io.StringIO(txt), delimiter=sup.get('delimiter', ';'))
    offers = []
    for r in rows:
        g = lambda k: (r.get(col.get(k, ''), '') or '').strip()
        if not g('name') or not g('price'):
            continue
        st = g('stock')
        stock = int(_num(st) or 0) if re.search(r'\d', st) else (0 if re.search(r'нет|^0$|под заказ', st, re.I) else 1 if st else None)
        offers.append({'supplier_code': g('supplier_code') or g('part_number'), 'part_number': g('part_number'), 'vendor': g('vendor'),
                       'name': g('name'), 'category': g('category'), 'price_in': _num(g('price')), 'stock': stock,
                       'images': [x for x in re.split(r'[\s,;]+', g('image')) if x.startswith('http')][:8],
                       'description': g('description')[:4000], 'specs': [], 'url': ''})
        if limit and len(offers) >= limit:
            break
    return offers


SOURCES = {'yml': src_yml, 'xml': src_yml, 'csv': src_csv, 'xlsx': src_csv, 'site': src_site}


# ---------------------------------------------------------------- база
SCHEMA = '''
CREATE TABLE IF NOT EXISTS products(
  sku TEXT PRIMARY KEY, prefix TEXT, category TEXT, name TEXT, vendor TEXT, part_number TEXT,
  description TEXT, specs TEXT, images TEXT, markup REAL, cost REAL, price REAL, stock INTEGER, active INTEGER DEFAULT 1,
  created TEXT, updated TEXT);
CREATE TABLE IF NOT EXISTS offers(
  supplier TEXT, supplier_code TEXT, sku TEXT, part_number TEXT, vendor TEXT, supplier_category TEXT, url TEXT,
  price_in REAL, stock INTEGER, last_seen TEXT, PRIMARY KEY(supplier, supplier_code));
CREATE INDEX IF NOT EXISTS offers_sku ON offers(sku);
CREATE INDEX IF NOT EXISTS products_pn ON products(part_number);
CREATE TABLE IF NOT EXISTS counters(prefix TEXT PRIMARY KEY, n INTEGER);
CREATE TABLE IF NOT EXISTS runs(id INTEGER PRIMARY KEY, started TEXT, finished TEXT, status TEXT, info TEXT);
'''


def db():
    os.makedirs(DATA, exist_ok=True)
    c = sqlite3.connect(os.path.join(DATA, 'supply.sqlite'))
    c.row_factory = sqlite3.Row
    c.executescript(SCHEMA)
    return c


def norm_pn(s):
    return re.sub(r'[^0-9a-z]', '', (s or '').lower())


def our_category(cfg, supplier_category, name):
    hay = f'{supplier_category} {name}'
    for cat, rule in cfg['categories'].items():
        if cat.startswith('_'):
            continue
        if re.search(rule['match'], hay, re.I):
            return cat, rule['prefix']
    return 'Прочее', 'OTH'


def new_sku(c, prefix):
    row = c.execute('SELECT n FROM counters WHERE prefix=?', (prefix,)).fetchone()
    n = (row['n'] if row else 0) + 1
    c.execute('INSERT OR REPLACE INTO counters(prefix, n) VALUES (?,?)', (prefix, n))
    return f'PBK-{prefix}-{n:05d}'


def pick_markup(cfg, category):
    m = cfg['markup']
    if category in m.get('by_category', {}):
        return float(m['by_category'][category])
    if m.get('mode') == 'random_fixed':
        return round(random.uniform(float(m['min']), float(m['max'])), 2)
    return float(m.get('default', 4.0))


def retail(cost, markup, step):
    if not cost:
        return None
    return int(math.ceil(cost * (1 + markup / 100) / step) * step)


# ---------------------------------------------------------------- фото
def save_images(fetch, sku, urls, old, cfg):
    """Скачивает фото на свой сервер: public/uploads/products/<SKU>[-N].<ext>. Возвращает [{src,file}]."""
    os.makedirs(os.path.join(PUB, 'uploads', 'products'), exist_ok=True)
    old_map = {i['src']: i['file'] for i in (old or []) if os.path.exists(os.path.join(PUB, i['file'].lstrip('/')))}
    out, errors = [], 0
    for n, src in enumerate(urls[: cfg.get('max_images', 4)]):
        if src in old_map:
            out.append({'src': src, 'file': old_map[src]})
            continue
        try:
            data, ctype = fetch.get(src, binary=True, max_bytes=int(cfg.get('image_max_mb', 5) * 1_000_000))
        except Blocked:
            raise
        except Exception as e:
            log(f'  фото {sku}: {e}')
            errors += 1
            continue
        ext = {'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif'}.get(ctype.split(';')[0].strip())
        if not ext and data[:3] == b'\xff\xd8\xff':
            ext = 'jpg'
        elif not ext and data[:8] == b'\x89PNG\r\n\x1a\n':
            ext = 'png'
        elif not ext and data[:4] == b'RIFF' and data[8:12] == b'WEBP':
            ext = 'webp'
        if not ext or len(data) > cfg.get('image_max_mb', 5) * 1_000_000 or len(data) < 500:
            log(f'  фото {sku}: не изображение или слишком большое ({ctype}, {len(data)} байт)')
            errors += 1
            continue
        name = f'{sku}{"" if n == 0 else "-" + str(n + 1)}.{ext}'
        with open(os.path.join(PUB, 'uploads', 'products', name), 'wb') as f:
            f.write(data)
        out.append({'src': src, 'file': f'/uploads/products/{name}'})
    return out, errors


# ---------------------------------------------------------------- синхронизация
def load_json(path, default):
    try:
        with open(path, encoding='utf-8') as f:
            return json.load(f)
    except FileNotFoundError:
        return default


def run(args, cfg):
    secrets = load_json(os.path.join(DATA, 'supply-secrets.json'), {})
    c = db()
    started = NOW()
    today = datetime.date.today().isoformat()
    sups = [s for s in cfg['suppliers'] if (s['id'] == args.supplier if args.supplier else s.get('enabled'))]
    for s in [s for s in sups if s.get('until') and s['until'] < today]:
        log(f'{s["id"]}: срок временного источника истёк ({s["until"]}) — пропускаем. Отключите его в tools/supply_config.json.')
        notify('Каталог оборудования: временный источник отключён', f'{s["id"]}: срок до {s["until"]} истёк, сбор не выполняется. Подключите официальный API дистрибьютора.')
    sups = [s for s in sups if not (s.get('until') and s['until'] < today)]
    if not sups:
        log('нет включённых поставщиков (enabled: true в tools/supply_config.json)')
        return 1
    log(f'=== старт синхронизации: {", ".join(s["id"] for s in sups)}' + (f', не больше {args.limit} товаров' if args.limit else ''))
    summary, errors, failed = {}, [], []
    for sup in sups:
        fetch = Fetcher(cfg.get('request_delay_sec', 1.0), _auth(sup, secrets))
        t0 = time.time()
        try:
            offers = SOURCES[sup['type']](sup, args.limit or sup.get('limit') or 0, fetch)
        except Blocked as e:
            msg = f'{sup["id"]}: источник отказал в доступе — сбор остановлен. {e}'
            log('!! ' + msg); errors.append(msg); failed.append(sup['id'])
            continue
        except Exception as e:
            msg = f'{sup["id"]}: ошибка источника — {type(e).__name__}: {e}'
            log('!! ' + msg); errors.append(msg); failed.append(sup['id'])
            continue
        prev = c.execute('SELECT COUNT(*) FROM offers WHERE supplier=? AND stock>0', (sup['id'],)).fetchone()[0]
        log(f'  {sup["id"]}: получено {len(offers)} позиций за {time.time() - t0:.0f} с (ранее в наличии {prev})')
        if not offers or (prev and not (args.limit or sup.get('limit')) and len(offers) < prev * cfg.get('min_coverage', 0.6)):
            msg = f'{sup["id"]}: получено подозрительно мало позиций ({len(offers)} при {prev} ранее) — остатки не обнулялись'
            log('!! ' + msg); errors.append(msg); failed.append(sup['id'])
            if not offers:
                continue
        new = upd = img_err = 0
        seen = set()
        for o in offers:
            if not o.get('price_in') or not o.get('name'):
                continue
            seen.add(o['supplier_code'])
            row = c.execute('SELECT sku FROM offers WHERE supplier=? AND supplier_code=?', (sup['id'], o['supplier_code'])).fetchone()
            sku = row['sku'] if row else None
            if not sku and norm_pn(o['part_number']):    # тот же товар уже есть от другого поставщика
                for r in c.execute('SELECT sku, vendor FROM products WHERE part_number=?', (o['part_number'],)):
                    if not o['vendor'] or not r['vendor'] or o['vendor'].lower() == r['vendor'].lower():
                        sku = r['sku']; break
            cat, prefix = our_category(cfg, o['category'], o['name'])
            if not sku:
                sku = new_sku(c, prefix)
                c.execute('''INSERT INTO products(sku,prefix,category,name,vendor,part_number,description,specs,images,markup,stock,created,updated)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)''',
                          (sku, prefix, cat, o['name'], o['vendor'], o['part_number'], o['description'], json.dumps(o['specs'], ensure_ascii=False),
                           '[]', pick_markup(cfg, cat), 0, started, started))
                new += 1
            else:
                upd += 1
            pr = c.execute('SELECT * FROM products WHERE sku=?', (sku,)).fetchone()
            images = json.loads(pr['images'] or '[]')
            if o['images'] and [i['src'] for i in images] != o['images'][: cfg.get('max_images', 4)]:
                try:
                    images, e = save_images(fetch, sku, o['images'], images, cfg)
                    img_err += e
                except Blocked as e:
                    log(f'  фото: {e}')
            c.execute('''UPDATE products SET name=?, vendor=COALESCE(NULLIF(?,''),vendor), part_number=COALESCE(NULLIF(?,''),part_number),
                         description=COALESCE(NULLIF(?,''),description), specs=CASE WHEN ?='[]' THEN specs ELSE ? END, images=?, active=1, updated=? WHERE sku=?''',
                      (o['name'], o['vendor'], o['part_number'], o['description'], json.dumps(o['specs'], ensure_ascii=False),
                       json.dumps(o['specs'], ensure_ascii=False), json.dumps(images, ensure_ascii=False), started, sku))
            stock = o['stock'] if o['stock'] is not None else 1
            c.execute('''INSERT INTO offers(supplier,supplier_code,sku,part_number,vendor,supplier_category,url,price_in,stock,last_seen)
                         VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(supplier,supplier_code) DO UPDATE SET
                         sku=excluded.sku, part_number=excluded.part_number, vendor=excluded.vendor, supplier_category=excluded.supplier_category,
                         url=excluded.url, price_in=excluded.price_in, stock=excluded.stock, last_seen=excluded.last_seen''',
                      (sup['id'], o['supplier_code'], sku, o['part_number'], o['vendor'], o['category'], o['url'], o['price_in'], stock, started))
        # что пропало у поставщика — остаток 0 (только при полном, а не тестовом проходе)
        zeroed = 0
        if sup['id'] not in failed and not (args.limit or sup.get('limit')):
            zeroed = c.execute('UPDATE offers SET stock=0 WHERE supplier=? AND last_seen<>? AND stock<>0', (sup['id'], started)).rowcount
        c.commit()
        summary[sup['id']] = {'received': len(offers), 'new': new, 'updated': upd, 'zeroed': zeroed, 'image_errors': img_err, 'requests': fetch.count}
        log(f'  {sup["id"]}: новых {new}, обновлено {upd}, снято с наличия {zeroed}, ошибок фото {img_err}')
    reprice(c, cfg)
    stats = render(c, cfg)
    status = 'ok' if not errors else ('partial' if summary else 'error')
    c.execute('INSERT INTO runs(started,finished,status,info) VALUES (?,?,?,?)',
              (started, NOW(), status, json.dumps({'suppliers': summary, 'errors': errors, **stats}, ensure_ascii=False)))
    c.commit()
    with open(os.path.join(DATA, 'supply-status.json'), 'w', encoding='utf-8') as f:
        json.dump({'started': started, 'finished': NOW(), 'status': status, 'suppliers': summary, 'errors': errors, **stats}, f, ensure_ascii=False, indent=1)
    log(f'=== готово: {status}; на витрине {stats["shown"]} (в наличии {stats["in_stock"]})')
    if errors:
        notify('Каталог оборудования: ошибка обновления', '\n'.join(errors) + f'\n\nПодробности: {DOMAIN}/admin/?supply=1')
    return 0 if status != 'error' else 2


def reprice(c, cfg):
    """Цена витрины = минимальная закупочная у поставщиков с остатком × (1 + наценка товара) с округлением вверх."""
    step = int(cfg.get('round_to', 10))
    for p in c.execute('SELECT sku, markup FROM products').fetchall():
        offs = c.execute('SELECT price_in, stock FROM offers WHERE sku=?', (p['sku'],)).fetchall()
        avail = [o for o in offs if o['stock'] and o['stock'] > 0]
        pool = avail or offs
        cost = min((o['price_in'] for o in pool if o['price_in']), default=None)
        stock = sum(o['stock'] for o in avail)
        c.execute('UPDATE products SET cost=?, price=?, stock=? WHERE sku=?', (cost, retail(cost, p['markup'], step), stock, p['sku']))
    c.commit()


# ---------------------------------------------------------------- витрина
def E(s):
    return html.escape(str(s or ''), quote=True)


def render(c, cfg):
    hide = cfg.get('out_of_stock') == 'hide'
    rows = c.execute('SELECT * FROM products WHERE active=1 AND price IS NOT NULL ORDER BY category, name').fetchall()
    rows = [r for r in rows if not (hide and not r['stock'])]
    order = [k for k in cfg['categories'] if not k.startswith('_')]
    present = {r['category'] for r in rows}
    cats = [k for k in order if k in present] + sorted(present - set(order))
    items = []
    for r in rows:
        imgs = json.loads(r['images'] or '[]')
        items.append([r['sku'], r['name'], cats.index(r['category']), int(r['price']), 1 if r['stock'] else 0, imgs[0]['file'] if imgs else ''])
    stamp = datetime.datetime.now().strftime('%d.%m.%Y %H:%M')
    js = '/* Каталог оборудования ИНФОТЕХ — формируется tools/supply_sync.py, вручную не править */\nwindow.SUPPLY=' + \
         json.dumps({'date': stamp, 'cats': cats, 'items': items}, ensure_ascii=False, separators=(',', ':')) + ';\n'
    tmp = os.path.join(PUB, 'supply.js.tmp')
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(js)
    os.replace(tmp, os.path.join(PUB, 'supply.js'))
    # страницы товаров
    tpl_path = os.path.join(PUB, 'assets', 'tovar-template.html')
    pages = 0
    if os.path.exists(tpl_path):
        tpl = open(tpl_path, encoding='utf-8').read()
        allrows = c.execute('SELECT * FROM products WHERE price IS NOT NULL').fetchall()   # страницы не удаляем (SEO)
        for r in allrows:
            d = os.path.join(PUB, 'tovar', r['sku'])
            os.makedirs(d, exist_ok=True)
            with open(os.path.join(d, 'index.html'), 'w', encoding='utf-8') as f:
                f.write(product_page(tpl, r, hidden=hide and not r['stock']))
            pages += 1
        sm = ''.join(f'<url><loc>{DOMAIN}/tovar/{r["sku"]}/</loc><lastmod>{(r["updated"] or "")[:10]}</lastmod></url>\n' for r in allrows)
        with open(os.path.join(PUB, 'sitemap-products.xml'), 'w', encoding='utf-8') as f:
            f.write('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + sm + '</urlset>\n')
    return {'shown': len(items), 'in_stock': sum(1 for i in items if i[4]), 'categories': len(cats), 'pages': pages}


def product_page(tpl, r, hidden=False):
    imgs = json.loads(r['images'] or '[]')
    specs = json.loads(r['specs'] or '[]')
    url = f'{DOMAIN}/tovar/{r["sku"]}/'
    in_stock = bool(r['stock']) and not hidden
    price = f'{int(r["price"]):,}'.replace(',', ' ') + ' ₽'
    gallery = ''.join(f'<img src="{E(i["file"])}" alt="{E(r["name"])}{"" if n == 0 else " — фото " + str(n + 1)}" loading="{"eager" if n == 0 else "lazy"}">' for n, i in enumerate(imgs)) \
        or '<div class="pg-noimg">Фото уточняйте у менеджера</div>'
    spec_html = ''.join(f'<tr><th>{E(k)}</th><td>{E(v)}</td></tr>' for k, v in specs)
    buy = (f'<div class="pbuy-st ok">В наличии</div><div class="pbuy-row"><div class="qty pq"><button type="button" class="cut" data-pdec aria-label="Уменьшить">−</button>'
           f'<input value="1" data-pq inputmode="numeric" aria-label="Количество"><button type="button" class="cut" data-pinc aria-label="Увеличить">+</button></div>'
           f'<button class="btn btn-primary cut" data-buy="{E(r["sku"])}">В корзину</button></div>') if in_stock else \
          ('<div class="pbuy-st">Под заказ</div><p class="pbuy-note">Сейчас нет на складе поставщика. Оставьте заявку — менеджер уточнит срок поставки и цену.</p>'
           '<a class="btn btn-primary cut" href="#contact">Узнать срок поставки</a>')
    ld = {'@context': 'https://schema.org', '@type': 'Product', 'name': r['name'], 'sku': r['sku'], 'url': url,
          'description': (r['description'] or r['name'])[:500], 'category': r['category'],
          'offers': {'@type': 'Offer', 'price': int(r['price']), 'priceCurrency': 'RUB', 'url': url,
                     'availability': 'https://schema.org/' + ('InStock' if in_stock else 'PreOrder'),
                     'seller': {'@type': 'Organization', 'name': 'ИНФОТЕХ'}}}
    if r['vendor']:
        ld['brand'] = {'@type': 'Brand', 'name': r['vendor']}
    if imgs:
        ld['image'] = [DOMAIN + i['file'] for i in imgs]
    crumbs = {'@context': 'https://schema.org', '@type': 'BreadcrumbList', 'itemListElement': [
        {'@type': 'ListItem', 'position': 1, 'name': 'Главная', 'item': DOMAIN + '/'},
        {'@type': 'ListItem', 'position': 2, 'name': 'Магазин', 'item': DOMAIN + '/magazin-1c/'},
        {'@type': 'ListItem', 'position': 3, 'name': r['category'], 'item': DOMAIN + '/magazin-1c/?cat=supply&sub=' + urllib.parse.quote(r['category'])},
        {'@type': 'ListItem', 'position': 4, 'name': r['name']}]}
    lds = ''.join('<script type="application/ld+json">' + json.dumps(x, ensure_ascii=False).replace('</', '<\\/') + '</script>\n' for x in (ld, crumbs))
    cat_url = '/magazin-1c/?cat=supply&sub=' + urllib.parse.quote(r['category'])
    body = f'''<section class="phero"><div class="wrap"><div class="rv in">
<nav class="pcrumbs" aria-label="Навигация"><a href="/">Главная</a> → <a href="/magazin-1c/">Магазин</a> → <a href="{E(cat_url)}">{E(r['category'])}</a></nav>
<h1 class="ptitle-h">{E(r['name'])}</h1></div></div></section>
<section class="pcard"><div class="wrap pgrid">
  <div class="pgal cut">{gallery}</div>
  <div class="pinfo">
    <div class="pmeta"><span>Артикул: <b>{E(r['sku'])}</b></span>{f'<span>Производитель: {E(r["vendor"])}</span>' if r['vendor'] else ''}</div>
    <div class="pbuy cut"><div class="pprice">{price}</div><div class="pnote">Цена без НДС (УСН). Окончательную стоимость менеджер укажет в счёте.</div>{buy}</div>
    <ul class="pplus"><li>Официальная гарантия производителя</li><li>Оплата по счёту, документы для бухгалтерии</li><li>Сборка, установка и настройка — по запросу</li></ul>
  </div>
</div></section>
{f'<section class="pspecs"><div class="wrap"><h2>Характеристики</h2><table class="spect">{spec_html}</table></div></section>' if spec_html else ''}
{f'<section class="pdesc"><div class="wrap"><h2>Описание</h2><p>{E(r["description"])}</p></div></section>' if r['description'] else ''}
'''
    title = f'{r["name"]} — купить в ИНФОТЕХ'
    desc = f'{r["name"]}: {"в наличии" if in_stock else "под заказ"}, {price}. Поставка, установка и настройка — ИНФОТЕХ, Санкт-Петербург.'
    return (tpl.replace('/assets/tovar-template.html', f'/tovar/{r["sku"]}/')
               .replace('__PRODUCT_TITLE__', E(title)).replace('__PRODUCT_DESC__', E(desc)[:300])
               .replace('__PRODUCT_LD__', lds).replace('__PRODUCT_BODY__', body)
               .replace('__PRODUCT_SERVICE__', E('Товар: ' + r['sku'] + ' ' + r['name'])))


# ---------------------------------------------------------------- команды
def cmd_probe(args, cfg):
    sups = [s for s in cfg['suppliers'] if s['id'] == args.supplier] if args.supplier else [s for s in cfg['suppliers'] if s.get('enabled')]
    if not sups:
        print('Укажите --supplier ID'); return 1
    sup = sups[0]
    fetch = Fetcher(cfg.get('request_delay_sec', 1.0), _auth(sup, load_json(os.path.join(DATA, 'supply-secrets.json'), {})))
    print(f'Проверка источника {sup["id"]} ({sup["type"]}): {sup["source"]}\nUser-Agent: {UA}\n')
    try:
        if sup['type'] == 'site':
            rep = src_site(sup, 3, fetch, probe=True, probe_url=args.url)
        else:
            offers = SOURCES[sup['type']](sup, 5, fetch)
            rep = {'received': len(offers), 'samples': offers[:3]}
    except Blocked as e:
        print(f'ИСТОЧНИК ОТКАЗАЛ В ДОСТУПЕ: {e}\nСобирать данные с этого источника нельзя — нужен официальный канал (API/фид/прайс).')
        return 2
    print(json.dumps(rep, ensure_ascii=False, indent=1)[:12000])
    print(f'\nЗапросов к источнику: {fetch.count}')
    return 0


def cmd_status(args, cfg):
    st = load_json(os.path.join(DATA, 'supply-status.json'), None)
    print(json.dumps(st, ensure_ascii=False, indent=1) if st else 'Синхронизация ещё не запускалась')
    return 0


def main():
    ap = argparse.ArgumentParser(description='Каталог оборудования ИНФОТЕХ: импорт от дистрибьюторов')
    ap.add_argument('cmd', choices=['probe', 'run', 'status', 'render'])
    ap.add_argument('--supplier')
    ap.add_argument('--limit', type=int, default=0)
    ap.add_argument('--url')
    ap.add_argument('--config', default=os.path.join(TOOLS, 'supply_config.json'))
    args = ap.parse_args()
    cfg = load_json(args.config, None)
    if args.cmd == 'probe':
        return cmd_probe(args, cfg)
    if args.cmd == 'status':
        return cmd_status(args, cfg)
    lock = os.path.join(DATA, 'supply.lock')
    os.makedirs(DATA, exist_ok=True)
    try:
        fd = os.open(lock, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
        os.write(fd, str(os.getpid()).encode()); os.close(fd)
    except FileExistsError:
        if time.time() - os.path.getmtime(lock) < 6 * 3600:
            print('Синхронизация уже идёт'); return 3
        os.remove(lock); return main()
    try:
        if args.cmd == 'render':
            c = db(); reprice(c, cfg); print(render(c, cfg)); return 0
        return run(args, cfg)
    except Exception as e:
        log(f'!! сбой: {type(e).__name__}: {e}')
        notify('Каталог оборудования: сбой обновления', f'{type(e).__name__}: {e}')
        raise
    finally:
        try:
            os.remove(lock)
        except OSError:
            pass


if __name__ == '__main__':
    sys.exit(main())
