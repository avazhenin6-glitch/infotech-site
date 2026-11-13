#!/usr/bin/env python3
"""
Обновление каталога магазина ИНФОТЕХ из прайс-листа фирмы «1С».

    python3 update_catalog.py price_1c.xls                 # catalog.js и «Не вошли в каталог.xlsx» в текущей папке
    python3 update_catalog.py price_1c.xls --out ./public

Нужны: Python 3.10+ и пакеты:  pip install pandas python-calamine openpyxl
Правила отбора (зарубежные продукты, NFR, 1С:Фреш кроме Базового/ПРОФ/апгрейда и т.д.) — функция reason() ниже.
"""
import argparse,os,re,json,datetime
import pandas as pd
ap=argparse.ArgumentParser();ap.add_argument('price');ap.add_argument('--out',default='.');a=ap.parse_args()
raw=pd.read_excel(a.price,header=None,engine='calamine')
PRICE_DATE=datetime.date.today().strftime('%d.%m.%Y')
MON={'января':1,'февраля':2,'марта':3,'апреля':4,'мая':5,'июня':6,'июля':7,'августа':8,'сентября':9,'октября':10,'ноября':11,'декабря':12}
for v in raw.iloc[0].tolist():
    m=re.match(r'(\d{1,2})\s+(\S+)\s+(\d{4})',str(v))
    if m and m.group(2) in MON: PRICE_DATE=f"{int(m.group(1)):02d}.{MON[m.group(2)]:02d}.{m.group(3)}"
df=raw.iloc[2:].reset_index(drop=True).iloc[:,:11]
df.columns=['code','name','x','cur','rec','dealer','pp','vat','date','reestr','comment']

# re-parse with category tracking for разделы 2/5/7
S=lambda x:'' if x is None or x!=x else str(x)
sec=sub=grp=cat=None;rows=[]
for i,r in df.iterrows():
    c=str(r.code).strip() if r.code==r.code else ''
    nm=r['name'] if r['name']==r['name'] else None
    if nm is None:
        if not c: continue
        if c.startswith('Раздел'): sec=c;sub=grp=cat=None
        elif re.match(r'^\d\.\d',c): sub=c;grp=cat=None
        elif c.startswith(('Продажа','Консультация','Обращаем','Рекомендованные','Информационное','Цена для','Подробности','http','Программно-аппаратные','Начиная')) or len(c)>90: pass
        elif c.isupper() and (S(sub).startswith('1.3') or len(re.findall('[А-ЯЁ]',c))>=4): cat=c;grp=None
        else: grp=c
        continue
    rows.append(dict(code=c,name=re.sub(r'\s+',' ',str(nm)).strip(),sec=sec,sub=sub,cat=cat,grp=grp,cur=r.cur,rec=r.rec,comment=('' if r.comment!=r.comment else str(r.comment).strip())))
it=pd.DataFrame(rows)
it['price']=pd.to_numeric(it.rec,errors='coerce')
S=lambda x:'' if x is None or x!=x else str(x)
EXCLUDE_NAMES=r'^(?:Лицензия Агент 1С|Лицензия "1С-Финконтроль 8"|Агент резервного копирования 1С:Облачный архив|1С-Ритейл Чекер|1С-ЭТП \(ПП "Астрал-ЭТ"\)|Клиентская лицензия "1С-Коннект:)'
FRESH_OK=r'через Интернет\. (?:Базовый|ПРОФ) на \d+ мес|Апгрейд с Базовый(?: с прерыванием)? на ПРОФ, доплата за \d+'
FOREIGN=r'Узбекист|Казахст|Беларус|Белорус|Кыргызст|Киргиз|Таджикист|Армени|Азербайдж|Грузи[ия]|Молдов|Украин|Латви|Литв|Эстони|Монголи|Румыни|Польш|Вьетнам|Туркмен|Абхаз|нерезидент|для РК\b|\bКР\b|Казахстан'
PARTNER=r'Продажа партнерам|Продается партнерам|Продажа только партнерам|партнерам-франчайзи$|Сертифицированного Обучения|Сертифицированного обучения|дистрибьюторам|производителям и поставщикам компьютерных|учебным заведениям|учебными заведениями|Продажа партнерам фирмы|только партнерам'
def latin_ratio(s):
    L=len(re.findall('[A-Za-z]',s));C=len(re.findall('[А-Яа-яЁё]',s));return L/(L+C+1e-9)
def reason(r):
    sec,sub=S(r['sec']),S(r['sub'])
    if sec.startswith('Раздел 2'): return 'Продукты партнёрских фирм «1С:Совместимо» (не продукты 1С)'
    if sec.startswith('Раздел 6'): return 'Одежда, сувениры и печатная продукция с логотипом 1С'
    if sub.startswith('1.4'): return 'Бумажная документация к платформе'
    if sub.startswith('4.4.') : return 'Материалы только для Центров сертифицированного обучения'
    if re.match(r'^4\.3\s',sub) and re.search(FRESH_OK,r['name']) and not re.search('ERP',r['name']): return ''
    if sub.startswith('4.3 ') or sub.startswith('4.3 ') or sub.startswith('4.3  '): return '1С:Фреш — прочие тарифы (ERP, КОРП, СПЕЦ, БизнесСтарт, отраслевые, доп. сеансы и базы)'
    if S(r['cat']).startswith('МЕЖДУНАРОДНЫЕ'): return 'Зарубежные продукты'
    if S(r['cat']).startswith('КОМПЛЕКТЫ ОТРАСЛЕВЫХ РЕШЕНИЙ ДЛЯ ОБУЧЕНИЯ'): return 'Комплекты для вузов и колледжей'
    if r.cur!='руб.' and not (sec.startswith('Раздел 5') and r.cur=='USD'): return 'Зарубежные продукты (цена не в рублях)' if r.cur==r.cur else 'Нет цены'
    txt=r['name']+' '+r.comment
    if re.search(FOREIGN,txt): return 'Зарубежные продукты'
    if sec.startswith('Раздел 1') and latin_ratio(r['name'])>0.5: return 'Зарубежные продукты (англоязычные версии)'
    if re.match(EXCLUDE_NAMES,r['name']): return 'Исключено по решению ИНФОТЕХ (сервис не продаётся)'
    if re.search(r'\bNFR\b',r['name']): return 'NFR-лицензии (только для партнёров, не для продажи)'
    if re.search(r'\bOEM\b',r['name']): return 'OEM-поставки для сборщиков ПК'
    if re.search(PARTNER,r.comment): return 'Продаётся только партнёрам/ЦСО/вузам, не конечным клиентам'
    if re.search(r'для обучения в высших|для вузов|комплекта для вузов',r['name'],re.I): return 'Комплекты для вузов и колледжей'
    if re.search(r'Антикризисная электронная поставка для разработчиков|Временная электронная лицензия для изучения',r['name']): return 'Специальные/временные поставки для разработчиков'
    if r.price!=r.price or r.price<=0: return 'Нет рекомендованной цены'
    return ''
it['reason']=it.apply(reason,axis=1)
# also exclude Раздел 3 subsections not chosen handled above; sub 3.4 none
inc=it[it.reason==''].copy(); exc=it[it.reason!=''].copy()

# ---------- categories ----------
FAM=[ # (subcat, regex) for Продукты 1С (sections 1.1,1.2)
 ('1С:Предприятие 8 — лицензии',r'^1С:Предприятие 8(\.3)?( ПРОФ| КОРП)?\.? ?(Клиентская|Сервер|Лицензия на сервер)|Клиентская лицензия|Лицензия на сервер|Сервер МИНИ|Расширенная Корпоративная Лицензия|1С:Предприятие\.Элемент|Сервер взаимодействия|Технологическая электронная|8z|Комплект разработчика|Комплект специалиста|Версия для обучения программированию'),
 ('1С:Бухгалтерия 8',r'^1С:Бухгалтерия( 8| для 1|\b)(?! государств| некоммерч)|^1С:Упрощенка|^1С:Предприниматель|^1С:БизнесСтарт|Бухгалтерия КОРП МСФО'),
 ('1С:Зарплата и управление персоналом 8',r'Зарплата и управление персоналом'),
 ('1С:Управление торговлей 8',r'Управление торговлей'),
 ('1С:Розница 8 и кассовые программы',r'1С:Розница|1С:Касса|Мобильная касса|Рабочее место кассира|Кладовщик'),
 ('1С:Управление нашей фирмой 8',r'Управление нашей фирмой'),
 ('1С:Комплексная автоматизация 8',r'Комплексная автоматизация|Комплект прикладных решений'),
 ('1С:ERP и решения для холдингов',r'ERP|Управление холдингом|Корпорация|MDM|Perform|Налоговый мониторинг'),
 ('1С:Документооборот 8',r'Документооборот|Договоры 8|Учет обращений|1С:Архив'),
 ('Решения для госучреждений',r'государственного учреждения|Свод отчетов|Бюджетная отчетность|Бюджет муниципального|Бюджет поселения|Государственные и муниципальные закупки|Вещевое довольствие|Закупки\. Дополнение'),
 ('Некоммерческие организации, ТСЖ, гаражи',r'некоммерческой|Гаражи|Садовод'),
 ('Небольшие программы: Налогоплательщик, Платежные документы',r'Налогоплательщик|Платежные документы|Отчетность предпринимателя|Деньги'),
 ('1С:Электронное обучение',r'Электронное обучение'),
 ('Корпоративные инструменты и платформа',r'.'),
]
SERV=[('Для бухгалтера и кадровика',('4.7 ','4.23','4.24','4.38','4.36')),
      ('Для бизнеса',('4.8 ','4.10','4.11','4.20','4.21','4.22','4.25','4.28','4.29','4.30','4.31','4.32','4.33','4.34','4.35','4.37')),
      ('Электронный документооборот и подпись',('4.12','4.13')),
      ('Для розничной торговли',('4.14','4.16','4.17','4.27')),
      ('1С:Фреш — 1С через Интернет (Базовый, ПРОФ, апгрейд)',('4.3 ',)),
      ('1С через Интернет',('4.5 ',)),
      ('Для бюджетных учреждений',('4.9 ',)),
      ('Сервисы поддержки',('4.4 ','4.6 ','4.19','4.26'))]
STOP={'И','ДЛЯ','В','С','НА'}
def tc(s):
    if not s: return s
    out=[]
    for k,w in enumerate(s.split(' ')):
        core=re.sub(r'[^A-Za-zА-ЯЁа-яё]','',w)
        if re.search('[A-Za-z]',core) or (2<=len(core)<=4 and core not in STOP) or core=='ГЛОНАСС': out.append(w)
        elif k==0: out.append(w[:1]+w[1:].lower())
        else: out.append(w.lower())
    return ' '.join(out)
def classify(r):
    sec,sub=S(r['sec']),S(r['sub'])
    if sub.startswith(('1.1','1.2')):
        for n,rx in FAM:
            if re.search(rx,r['name']): return ('products','Продукты 1С',n)
    if sub.startswith(('1.6','1.7','1.8')): return ('products','Продукты 1С','СУБД и ОС для 1С')
    if sub.startswith('1.3'): return ('industry','Отраслевые решения',tc(S(r['cat'])) or 'Прочие')
    if sec.startswith('Раздел 4'):
        if sub.startswith(('4.1 ','4.1\t','4.1  ','4.2','4.18')) or sub.startswith('4.1 ') or re.match(r'^4\.1\s',sub): return ('its','Сопровождение 1С:ИТС','Тарифы 1С:ИТС' if re.match(r'^4\.1\s',sub) else ('1С:КП Отраслевой' if sub.startswith('4.2') else 'Продление поддержки УПП'))
        for n,keys in SERV:
            if any(sub.startswith(k) for k in keys): return ('services','Сервисы 1С',n)
        return ('services','Сервисы 1С','Прочие сервисы')
    if sec.startswith('Раздел 3'):
        m={'3.2':'Книги и материалы по 1С:Предприятию 8','3.5':'Материалы по учёту','3.7':'Подготовка к экзаменам 1С:Профессионал','3.8':'Правовые базы данных'}
        return ('edu','Учебные материалы',m.get(sub[:3],'Прочее'))
    if sec.startswith('Раздел 8'): return ('edu','Учебные материалы','Образовательные программы')
    if sec.startswith('Раздел 5'): return ('hw','Торговое оборудование',re.sub(r'^\d+\.\d+\s*','',sub))
    if sec.startswith('Раздел 7'): return ('vendors','ПО других вендоров',tc(S(r['cat'])) or 'Прочее')
    return ('other','Прочее','Прочее')
inc[['ck','cn','sc']]=inc.apply(lambda r:pd.Series(classify(r)),axis=1)
def is_e(r):
    n=r['name'].lower()
    if 'коробочн' in n: return 0
    if r.ck=='hw': return 0
    if 'электрон' in n: return 1
    if r.ck in('services','its'): return 1
    if r.ck=='vendors' and not re.search('программно-аппаратн|токен|ключ|носител|usb',n): return 1
    return 0
inc['e']=inc.apply(is_e,axis=1)
STATUS=r'ЦКБ|Центр компетенции|Центры ERP|Центр ERP|1С:КОРП|"1С:КОРП"|статус'
inc['status']=inc.comment.str.contains(STATUS,regex=True)
print(len(inc),len(exc))
print('electronic',inc.e.sum())


# ---------- собственные услуги ИНФОТЕХ (tools/own_prices.json) ----------
OWN=json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)),'own_prices.json'),encoding='utf-8'))
def months(n): return f"{n} месяц" if n==1 else (f"{n} месяца" if n in (2,3,4) else f"{n} месяцев")
own_rows=[]
for t in OWN['server_tariffs']:
    for m,d in OWN['discounts'].items():
        m=int(m); price=round(t['price']*m*(1-d))
        nm=f"Аренда сервера для 1С «{t['name']}» ({t['cpu']} vCPU, {t['ram']} ГБ RAM, {t['disk']} ГБ) — {months(m)}"+(f", скидка {int(d*100)}%" if d else '')
        own_rows.append(dict(code=f"INF-SRV-{t['id']}-{m:02d}",name=nm,price=price,cn='Услуги ИНФОТЕХ',sc='Аренда серверов для 1С'))
for t in OWN['support_tariffs']:
    own_rows.append(dict(code=f"INF-SUP-{t['n']}",name=f"Абонентское обслуживание 1С, тариф {t['n']}: до {t['hours']} ч в месяц ({t['rate']:,} ₽/ч), 1 месяц".replace(f"{t['rate']:,}",f"{t['rate']:,}".replace(',',' ')),price=t['fee'],cn='Услуги ИНФОТЕХ',sc='Абонентское обслуживание 1С'))
own_df=pd.DataFrame(own_rows).assign(ck='own',e=2,cur='руб.',status=False,comment='',reason='')
inc=pd.concat([own_df,inc],ignore_index=True)

# ---------- export JS ----------
ORDER=['own','products','industry','services','its','edu','hw','vendors']
cats=[];sc_index={}
for ck in ORDER:
    g=inc[inc.ck==ck]
    if g.empty: continue
    subs=[n for n,_ in FAM if n in set(g.sc)]+(['СУБД и ОС для 1С'] if 'СУБД и ОС для 1С' in set(g.sc) else []) if ck=='products' else [n for n,_ in SERV if n in set(g.sc)] if ck=='services' else list(dict.fromkeys(g.sc)) if ck=='own' else sorted(set(g.sc),key=lambda x:(x.startswith('Проч'),x))
    cats.append({'k':ck,'n':g.cn.iloc[0],'s':subs})
    for j,sn in enumerate(subs): sc_index[(ck,sn)]=(len(cats)-1,j)
items=[]
for _,r in inc.iterrows():
    ci,si=sc_index[(r.ck,r.sc)]
    p=float(r.price); p=int(p) if p==int(p) else round(p,2)
    items.append([r.code,r['name'],ci,si,p,'$' if r.cur=='USD' else '',int(r.e)])
order={k:i for i,k in enumerate(ORDER)}
items.sort(key=lambda x:(x[2],x[3]))
js='/* Каталог ИНФОТЕХ, сформирован из прайс-листа фирмы "1С" от '+PRICE_DATE+'. Цены — рекомендованные розничные. */\nwindow.CATALOG='+json.dumps({'cats':cats,'items':items,'date':PRICE_DATE,'own':OWN},ensure_ascii=False,separators=(',',':'))+';'
open(os.path.join(a.out,'catalog.js'),'w',encoding='utf-8').write(js)
print('js bytes',len(js.encode()),'items',len(items))

# ---------- Excel: что не вошло ----------
from openpyxl import Workbook
from openpyxl.styles import Font,PatternFill,Alignment
from openpyxl.utils import get_column_letter
e=exc; i=inc

S=lambda x:'' if x is None or x!=x else str(x)
wb=Workbook()
F=Font(name='Arial',size=10);H=Font(name='Arial',size=10,bold=True,color='F5FBFF');HF=PatternFill('solid',fgColor='001C1B')
T=Font(name='Arial',size=14,bold=True,color='001C1B')
def sheet(ws,headers,rows,widths,wrap=(2,8)):
    ws.append(headers)
    for c in range(1,len(headers)+1):
        x=ws.cell(1,c);x.font=H;x.fill=HF;x.alignment=Alignment(vertical='center',wrap_text=True)
    for r in rows: ws.append(r)
    for row in ws.iter_rows(min_row=2):
        for c in row: c.font=F; c.alignment=Alignment(vertical='top',wrap_text=c.column in wrap)
    for k,w in enumerate(widths,1): ws.column_dimensions[get_column_letter(k)].width=w
    ws.freeze_panes='A2';ws.auto_filter.ref=ws.dimensions;ws.row_dimensions[1].height=30
ws=wb.active;ws.title='Не вошли в каталог'
e=e.sort_values(['reason','sec','sub'])
rows=[[S(r.code),r['name'],S(r['sec']),S(r['sub']),S(r['cat']) or S(r['grp']),S(r.cur),(None if r.price!=r.price else float(r.price)),r.comment,r.reason] for _,r in e.iterrows()]
sheet(ws,['Код','Наименование','Раздел прайса','Подраздел','Группа','Валюта','Рекомендованная цена','Комментарий в прайсе 1С','Причина исключения'],rows,[16,70,28,30,24,8,14,40,42])
for c in ws['G'][1:]: c.number_format='#,##0.00'
n=ws.max_row
s=wb.create_sheet('Сводка',0)
s['A1']='Продукты прайс-листа 1С от '+PRICE_DATE+', не включённые в каталог сайта';s['A1'].font=T
s['A2']='Источник: price_1c.xls (прайс-лист фирмы «1С»). Чтобы вернуть позицию в каталог — сообщите её код или причину целиком.';s['A2'].font=Font(name='Arial',size=10,italic=True)
s.append([]);s.append(['Причина исключения','Позиций'])
for c in s[4]: c.font=H;c.fill=HF
reasons=list(e.reason.value_counts().index)
for k,rsn in enumerate(reasons):
    r=5+k; s.cell(r,1,rsn).font=F
    s.cell(r,2,f"=COUNTIF('Не вошли в каталог'!$I$2:$I${n},A{r})").font=F
tr=5+len(reasons)
s.cell(tr,1,'Итого не вошло').font=Font(name='Arial',size=10,bold=True)
s.cell(tr,2,f'=SUM(B5:B{tr-1})').font=Font(name='Arial',size=10,bold=True)
s.cell(tr+1,1,'Вошло в каталог сайта').font=F; s.cell(tr+1,2,len(i)).font=F
s.cell(tr+1,3,'позиций (значение из выгрузки каталога)').font=Font(name='Arial',size=9,italic=True,color='4B7BA6')
s.cell(tr+3,1,'Отдельно: в лист «Требуют статуса партнёра» вынесены позиции, которые ВОШЛИ в каталог, но 1С продаёт их только через партнёров со статусом (ЦКБ, Центр ERP, 1С:КОРП и т.п.). Проверьте, есть ли у вас такой статус.').font=Font(name='Arial',size=10,color='B5452F')
s.column_dimensions['A'].width=80;s.column_dimensions['B'].width=12;s.column_dimensions['C'].width=40
st=wb.create_sheet('Требуют статуса партнёра')
x=i[i.status]
rows=[[S(r.code),r['name'],r.cn,r.sc,float(r.price),r.comment] for _,r in x.iterrows()]
sheet(st,['Код','Наименование','Раздел каталога','Подраздел','Рекомендованная цена, ₽','Условие продажи (из прайса 1С)'],rows,[16,70,22,30,16,60],wrap=(2,6))
for c in st['E'][1:]: c.number_format='#,##0'
wb.save(os.path.join(a.out,'Не вошли в каталог.xlsx'))
print(n-1,len(x))

print('Готово: в каталоге',len(inc),'позиций, не вошло',len(exc),'· прайс от',PRICE_DATE)
