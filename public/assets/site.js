/* ИНФОТЕХ — общий скрипт сайта. Модули включаются, только если на странице есть их блок. */
const ROOT=window.ROOT||'';
const OWN=(window.CATALOG&&window.CATALOG.own)||{server_tariffs:[],discounts:{1:0},support_tariffs:[]};
const $=id=>document.getElementById(id);
const fmt=n=>Math.round(n).toLocaleString('ru-RU');
const MONTHS=m=>m==1?'1 месяц':(m<5?m+' месяца':m+' месяцев');
const CAT=window.CATALOG||{cats:[],items:[],date:''};
const ITEMS=CAT.items.map(a=>({code:a[0],n:a[1],c:a[2],s:a[3],price:a[4],cur:a[5],e:a[6],lc:(a[1]+' '+a[0]).toLowerCase().replace(/ё/g,'е')}));
const BY={};ITEMS.forEach(p=>BY[p.code]=p);
const P=code=>BY[code];
const rub=n=>Math.round(n).toLocaleString('ru-RU')+' ₽';
const usd=n=>'$'+n.toLocaleString('en-US',{maximumFractionDigits:2});
const money=(p,q=1)=>p.cur==='$'?usd(p.price*q):rub(p.price*q);
const KIND=p=>p.e===1?'Электронная поставка':p.e===2?'Услуга ИНФОТЕХ':'Физическая поставка';
const KIND_TAG=p=>p.e===1?'<span class="tag e cut">Электронная поставка</span>':p.e===2?'<span class="tag cut" style="background:var(--slate);color:var(--ghost)">Услуга ИНФОТЕХ</span>':'<span class="tag cut">Физическая поставка</span>';
const esc=t=>t.replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const SHOP=!!document.getElementById('shop');

if(document.getElementById('eco')){
/* ================= Схема экосистемы ================= */
const ECO = {
  core:{t:'Ваш бизнес в центре системы',d:'Все звенья — железо, серверы, учётные системы и поддержка — подобраны так, чтобы работать вместе. Наведите курсор или нажмите на любой узел схемы.'},
  '1c':{t:'1С: учёт, финансы, CRM, ERP',d:'Лицензии, внедрение, доработки и сопровождение. Базы сразу размещаются на подготовленном сервере.',href:'uslugi/vnedrenie-1c/',tr:'t-1c'},
  srv:{t:'Аренда серверов',d:'VPS, выделенные серверы и колокация в дата-центре. Конфигурации, оптимизированные под 1С, с резервным копированием.',href:'uslugi/arenda-servera-1c/',tr:'t-srv'},
  hw:{t:'Поставка оборудования',d:'Рабочие места, серверы, СХД, сети и ПО — подбор, поставка, монтаж и ввод в работу.',href:'uslugi/#oborudovanie',tr:'t-hw'},
  b24:{t:'Битрикс24',d:'Внедрение и развитие: коммуникации, задачи, документооборот. Двусторонний обмен с 1С.',href:'uslugi/integraciya-1c-bitrix24/',tr:'t-b24'},
  sec:{t:'Защита и резервирование',d:'Ежедневные резервные копии, контроль доступа, VPN между офисами и обновления безопасности.',href:'uslugi/rezervnoe-kopirovanie/',tr:'t-sec'},
  sup:{t:'Сопровождение 24/7',d:'Мониторинг серверов, линия консультаций по 1С и сервисное обслуживание оборудования — в одном договоре.',href:'uslugi/soprovozhdenie-1c/',tr:'t-sup'}
};
const svg = document.querySelector('#eco svg');
const NS='http://www.w3.org/2000/svg';
const padsG=svg.querySelector('#pads'), pulsesG=svg.querySelector('#pulses');
['t-1c','t-srv','t-hw','t-b24','t-sec','t-sup'].forEach((id,i)=>{
  const p=svg.getElementById(id), L=p.getTotalLength(), e=p.getPointAtLength(L), s=p.getPointAtLength(0);
  [s,e].forEach(pt=>{const c=document.createElementNS(NS,'circle');c.setAttribute('cx',pt.x);c.setAttribute('cy',pt.y);c.setAttribute('r',5.5);c.setAttribute('class','pad');c.setAttribute('fill','#001C1B');padsG.appendChild(c)});
  const dot=document.createElementNS(NS,'circle');dot.setAttribute('r',3.5);dot.setAttribute('class','pulse');
  const am=document.createElementNS(NS,'animateMotion');am.setAttribute('dur',(2.2+i*.35)+'s');am.setAttribute('repeatCount','indefinite');am.setAttribute('begin','0s');
  if(i%2) {am.setAttribute('keyPoints','1;0');am.setAttribute('keyTimes','0;1');am.setAttribute('calcMode','linear');}
  const mp=document.createElementNS(NS,'mpath');mp.setAttributeNS('http://www.w3.org/1999/xlink','href','#'+id);mp.setAttribute('href','#'+id);
  am.appendChild(mp);dot.appendChild(am);pulsesG.appendChild(dot);
});
const panel=document.getElementById('ecoPanel');
let locked=null;
function showNode(k){
  const n=ECO[k];
  svg.querySelectorAll('.node').forEach(g=>g.classList.toggle('on',g.dataset.k===k&&k!=='core'));
  svg.querySelectorAll('.trace').forEach(t=>t.classList.toggle('on',n.tr?t.id===n.tr:!!t.id));
  panel.innerHTML=`<h4>${n.t}</h4><p>${n.d}</p>`+(n.href?`<a href="${ROOT}${n.href}">Подробнее <svg class="arrow" viewBox="0 0 18 12"><path d="M0 6h15M10 1l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2"/></svg></a>`:'');
}
svg.querySelectorAll('.node').forEach(g=>{
  g.addEventListener('mouseenter',()=>showNode(g.dataset.k));
  g.addEventListener('mouseleave',()=>showNode(locked||'core'));
  g.addEventListener('click',()=>{locked=g.dataset.k;showNode(locked)});
});
showNode('core');

}
/* ================= Шина-разделитель ================= */
function drawBus(el){
  /* многолинейная шина: параллельные дорожки с концентрическими скруглениями 45°-ступеней */
  const W=1600,H=130,N=5,g=8,R=12,y0=14,y1=H-14-(N-1)*g,run=150,P=2*run+2*(2*R+(N-1)*g);
  const lines=[];
  for(let i=0;i<N;i++){
    let x=-60,p=`M${x} ${y0+i*g}`;
    while(x<W+60){
      // верхний участок → спуск
      let xc=x+run; const rA=R+(N-1-i)*g, rB=R+i*g, xv=xc+rA;
      p+=` H${xc} A${rA} ${rA} 0 0 1 ${xv} ${y0+i*g+rA} V${y1+i*g-rB} A${rB} ${rB} 0 0 0 ${xv+rB} ${y1+i*g}`;
      x=xc+2*R+(N-1)*g;
      // нижний участок → подъём
      xc=x+run; const rC=R+i*g, rD=R+(N-1-i)*g, xu=xc+rC;
      p+=` H${xc} A${rC} ${rC} 0 0 0 ${xu} ${y1+i*g-rC} V${y0+i*g+rD} A${rD} ${rD} 0 0 1 ${xu+rD} ${y0+i*g}`;
      x=xc+2*R+(N-1)*g;
    }
    lines.push(`<path d="${p}" fill="none" stroke="url(#bg${el.id})" stroke-width="3.4"/>`);
  }
  let ticks='';
  for(let x=-60+run*.5,k=0;x<W+60;x+=P/2,k++){
    const yy=(k%2?y1:y0)-7;
    ticks+=`<rect x="${x-2+(k%2?(2*R+(N-1)*g)/2:0)}" y="${yy}" width="5" height="${(N-1)*g+14}" fill="url(#bg${el.id})"/>`;
  }
  el.setAttribute('viewBox',`0 0 ${W} ${H}`);el.setAttribute('preserveAspectRatio','xMidYMid slice');
  el.innerHTML=`<defs><linearGradient id="bg${el.id}" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="${W}" y2="0"><stop offset="0" stop-color="#CEE4F2"/><stop offset=".5" stop-color="#4B7BA6"/><stop offset="1" stop-color="#001C1B"/></linearGradient></defs>${lines.join('')}${ticks}`;
}
document.querySelectorAll('svg.bus').forEach(drawBus);

if(document.getElementById('compare')){
/* ================= Сравнение ================= */
const CMP=[
  ['Ответственность','Каждый отвечает за своё звено — при сбое ищут виноватого','Один партнёр отвечает за весь контур: от сервера до отчёта в 1С'],
  ['Совместимость','Решения подбираются отдельно и конфликтуют между собой','Оборудование, серверы и 1С изначально настроены работать вместе'],
  ['Коммуникации','3–5 договоров, контактов и графиков работ','Один договор, один менеджер, одно окно поддержки'],
  ['Скорость запуска','Этапы ждут друг друга, сроки растягиваются','Работы ведутся параллельно по единому плану'],
  ['Стоимость владения','Двойные работы, простои, лишние лицензии и мощности','Оптимальная связка решений без переплат']
];
const SC={bad:[['3–5','подрядчиков'],['недели','на поиск причины сбоя'],['×1.4','стоимость владения*']],good:[['1','партнёр'],['часы','на решение инцидента'],['−25%','к стоимости владения*']]};
const rows=document.getElementById('cmpRows'),score=document.getElementById('cmpScore'),cmpSec=document.getElementById('compare');
rows.innerHTML=CMP.map(r=>`<div class="cmp-row cut"><b>${r[0]}</b><span></span></div>`).join('');
const cs=document.getElementById('cmpSvg');
function cmpVisual(mode){
  const nodes=[[60,60],[60,160],[60,260],[380,70],[380,250]], hub=[220,160];
  let s='';
  if(mode==='bad'){
    const pairs=[[0,3],[0,4],[1,3],[2,4],[1,4],[2,3],[0,1]];
    pairs.forEach(([a,b],i)=>{const A=nodes[a],B=nodes[b],mx=(A[0]+B[0])/2+(i%2?40:-40),my=(A[1]+B[1])/2+(i%3-1)*50;
      s+=`<path class="w" d="M${A[0]} ${A[1]} Q${mx} ${my} ${B[0]} ${B[1]}" stroke="rgba(206,228,242,.45)" stroke-dasharray="6 7"/>`});
    s+=`<g transform="translate(220 160)"><circle r="20" fill="none" stroke="rgba(206,228,242,.35)" stroke-width="2" stroke-dasharray="4 5"/><text text-anchor="middle" y="5" fill="#CEE4F2" font-size="18" font-family="Inter">?</text></g>`;
  }else{
    nodes.forEach(([x,y])=>{const mx=x<hub[0]?x+60:x-60;
      s+=`<path class="w" d="M${x} ${y} H${mx} L${hub[0]+(x<hub[0]?-46:46)} ${hub[1]+(y-hub[1])*.25} H${hub[0]}" stroke="#CEE4F2"/>`});
    s+=`<g transform="translate(220 160)"><polygon points="0,-48 42,-24 42,24 0,48 -42,24 -42,-24" fill="#4B7BA6" stroke="#CEE4F2" stroke-width="2"/><text text-anchor="middle" y="5" fill="#F5FBFF" font-size="13" font-weight="600" font-family="Inter">ИНФОТЕХ</text></g>`;
  }
  const lab=['1С','Серверы','Оборудование','Битрикс24','Поддержка'];
  nodes.forEach(([x,y],i)=>{s+=`<g transform="translate(${x} ${y})"><circle r="${mode==='bad'?9:8}" fill="#001C1B" stroke="${mode==='bad'?'rgba(206,228,242,.6)':'#CEE4F2'}" stroke-width="3"/><text text-anchor="${x<200?'start':'end'}" x="${x<200?-6:6}" y="${y<120?-18:28}" fill="rgba(245,251,255,.85)" font-size="13" font-family="Inter">${lab[i]}</text></g>`});
  cs.innerHTML=s;
}
function setMode(mode){
  const btns=document.querySelectorAll('#sw button'),knob=document.querySelector('#sw .knob');
  btns.forEach(b=>b.classList.toggle('on',b.dataset.mode===mode));
  const on=[...btns].find(b=>b.dataset.mode===mode);knob.style.left=on.offsetLeft+'px';knob.style.width=on.offsetWidth+'px';
  cmpSec.classList.toggle('good',mode==='good');
  rows.querySelectorAll('span').forEach((sp,i)=>{sp.style.opacity=0;setTimeout(()=>{sp.textContent=CMP[i][mode==='bad'?1:2];sp.style.opacity=1},180)});
  score.innerHTML=SC[mode].map(([b,t])=>`<div><b>${b}</b>${t}</div>`).join('')+`<div style="flex-basis:100%;font-size:11px;opacity:.6">* иллюстративная оценка, зависит от проекта</div>`;
  cmpVisual(mode);
}
document.querySelectorAll('#sw button').forEach(b=>b.onclick=()=>setMode(b.dataset.mode));
setMode('bad');
window.addEventListener('resize',()=>setMode(document.querySelector('#sw button.on').dataset.mode));
setTimeout(()=>{if(!cmpSec.dataset.touched)setMode('good')},5000);
document.getElementById('sw').addEventListener('click',()=>cmpSec.dataset.touched=1);

}
/* ================= Калькулятор: тарифы аренды из tools/own_prices.json (через catalog.js) ================= */
const CFG={buh:{n:'Бухгалтерия',k:1},unf:{n:'УНФ',k:1},ut:{n:'Управление торговлей',k:1.2},zup:{n:'ЗУП',k:1},ka:{n:'Комплексная автоматизация',k:1.4},erp:{n:'ERP',k:2}};
if($('calc')){
let cfg='unf',period=1,calcPick=null;
$('period').innerHTML=Object.entries(OWN.discounts).map(([m,d])=>`<button class="chip cut${m==1?' on':''}" data-m="${m}">${MONTHS(+m)}${d?' · −'+Math.round(d*100)+'%':''}</button>`).join('');
$('period').querySelectorAll('.chip').forEach(c=>c.onclick=()=>{period=+c.dataset.m;$('period').querySelectorAll('.chip').forEach(x=>x.classList.toggle('on',x===c));calc()});
document.querySelectorAll('#cfg .chip').forEach(c=>c.onclick=()=>{cfg=c.dataset.v;document.querySelectorAll('#cfg .chip').forEach(x=>x.classList.toggle('on',x===c));calc()});
['users','bases','size','lic','prem','sup'].forEach(id=>$(id).addEventListener('input',calc));
function packs(n){const sizes=[100,50,20,10,5,1],res=[];let left=n;for(const s of sizes){const k=Math.floor(left/s);if(k){res.push(k+'×'+s);left-=k*s}}return res.join(' + ')}
function fits(t,u,b,sz){return u<=t.max_users&&b<=t.max_bases&&t.disk>=sz*2+40}
function pickTariff(u,b,sz,prem){
  const k=CFG[cfg].k, ue=Math.ceil(u*k);  // тяжёлые конфигурации считаем «за полтора-два пользователя»
  const all=OWN.server_tariffs.filter(t=>fits(t,ue,b,sz));
  const line=all.filter(t=>t.line===(prem?'Премиум':'Стандарт'));
  const pool=(line.length?line:all).sort((a,c)=>a.price-c.price);
  return pool[0]||null;
}
let lastSummary='';
function calc(){
  const u=+$('users').value,b=+$('bases').value,sz=+$('size').value,c=CFG[cfg],d=OWN.discounts[period]||0;
  $('usersOut').textContent=u;$('basesOut').textContent=b;$('sizeOut').textContent=sz;
  const t=pickTariff(u,b,sz,$('prem').checked);calcPick=t;
  const server=u>5||cfg==='erp'||cfg==='ka'||sz>50;
  const L=[];
  if(t){
    $('tier').textContent='Тариф «'+t.name+'»';$('cpu').textContent=t.cpu;$('ram').textContent=t.ram;$('ssd').textContent=t.disk;
    const total=t.price*period*(1-d);
    $('price').textContent=fmt(total);$('priceUnit').textContent=period==1?'₽/мес':'₽ за '+MONTHS(period);
    $('priceLbl').textContent=period==1?'Аренда сервера':`Аренда сервера, ${fmt(total/period)} ₽/мес со скидкой ${Math.round(d*100)}%`;
    L.push(['Для кого',t.purpose],['Пользователей / баз',t.users+' / '+t.bases],['Резервные копии',t.backup.toLowerCase()+', хранится '+t.copies]);
    if(t.line!==($('prem').checked?'Премиум':'Стандарт')) L.push(['Линейка','в выбранной линейке подходящего тарифа нет — предлагаем '+t.line]);
    $('calcCart').style.display='';
  }else{
    $('tier').textContent='Индивидуальная конфигурация';$('cpu').textContent='—';$('ram').textContent='—';$('ssd').textContent='—';
    $('price').textContent='по запросу';$('priceUnit').textContent='';$('priceLbl').textContent='Больше 15 пользователей или баз — подберём выделенный сервер';
    $('calcCart').style.display='none';
  }
  L.push(['Режим работы 1С',server?'Клиент-сервер (PostgreSQL / MS SQL)':'Файловый'],['Конфигурация',c.n+', баз: '+b]);
  if($('lic').checked){L.push(['Клиентские лицензии',u+' шт. ('+packs(u)+')']);if(server)L.push(['Лицензия сервера 1С',u>50||cfg==='erp'?'КОРП':'ПРОФ'])}
  let sup=null;
  if($('sup').checked){const h=Math.max(4,Math.ceil(u*1.5+b));sup=OWN.support_tariffs.find(x=>x.hours>=h)||OWN.support_tariffs.at(-1);L.push(['Сопровождение',`тариф ${sup.n}: до ${sup.hours} ч, ${fmt(sup.fee)} ₽/мес`])}
  $('licLink').style.display=$('lic').checked?'block':'none';
  $('resList').innerHTML=L.map(([a,b])=>`<li><span>${a}</span><span>${b}</span></li>`).join('');
  calc.sup=sup;
  lastSummary=`Расчёт с сайта: ${c.n}, пользователей ${u}, баз ${b}, объём ${sz} ГБ. `+(t?`Тариф «${t.name}» (${t.cpu} vCPU / ${t.ram} ГБ / ${t.disk} ГБ), оплата за ${MONTHS(period)}: ${fmt(t.price*period*(1-d))} ₽.`:'Нужна индивидуальная конфигурация.')+($('lic').checked?' Нужны лицензии 1С.':'')+(sup?` Сопровождение: тариф ${sup.n}.`:'');
}
calc();
$('toReq').addEventListener('click',()=>{if($('msg'))$('msg').value=lastSummary});
$('calcCart').addEventListener('click',()=>{
  if(!calcPick)return;
  addToCart(`INF-SRV-${calcPick.id}-${String(period).padStart(2,'0')}`);
  if(calc.sup) addToCart(`INF-SUP-${calc.sup.n}`);
});
}
/* ================= Магазин 1С ================= */
/* Данные каталога — в файле catalog.js (сформирован из прайс-листа 1С). Формат позиции: [код, наименование, раздел, подраздел, цена, валюта ('' = ₽, '$' = USD), электронная поставка 1/0] */
let st={c:-1,s:-1,e:false,q:'',sort:'def',page:1};const PAGE=40;
const ECOUNT=ITEMS.filter(p=>p.e===1).length;
if(SHOP)$('eCount').textContent=ECOUNT.toLocaleString('ru-RU')+' позиций · отправка на e-mail';

/* дерево каталога */
function cnt(c,s){return ITEMS.filter(p=>p.c===c&&(s<0||p.s===s)&&(!st.e||p.e===1)).length}
function renderTree(){if(!SHOP)return;
  let h=`<button class="ti${st.c<0&&!st.q?' on':''}" data-c="-1" data-s="-1"><span>Все товары</span><em>${(st.e?ECOUNT:ITEMS.length).toLocaleString('ru-RU')}</em></button><h5>Каталог продукции</h5>`;
  CAT.cats.forEach((g,ci)=>{
    const n=cnt(ci,-1); if(!n) return;
    h+=`<div class="grp${st.c===ci?' open':''}"><button class="ti${st.c===ci&&st.s<0&&!st.q?' on':''}" data-c="${ci}" data-s="-1"><span><span class="ar">›</span> ${g.n}</span><em>${n}</em></button><div class="subs">`+
      g.s.map((sn,si)=>{const m=cnt(ci,si);return m?`<button class="ti${st.c===ci&&st.s===si&&!st.q?' on':''}" data-c="${ci}" data-s="${si}"><span>${sn}</span><em>${m}</em></button>`:''}).join('')+`</div></div>`;
  });
  $('tree').innerHTML=h;
  $('tree').querySelectorAll('.ti').forEach(b=>b.onclick=()=>{go(+b.dataset.c,+b.dataset.s);if(innerWidth<=1020)$('tree').classList.remove('open')});
}
function go(c,s){st.c=c;st.s=s;st.q='';st.page=1;$('psearch').value='';$('sugg').classList.remove('on');renderTree();renderShop();
  const top=$('shop').querySelector('.cat-grid').getBoundingClientRect().top+scrollY-90; if(scrollY>top+40||innerWidth<=1020) scrollTo({top,behavior:'smooth'});}
if(SHOP){$('eBtn').onclick=()=>{st.e=!st.e;$('eOnly').checked=st.e;st.page=1;sync()};
$('eOnly').onchange=e=>{st.e=e.target.checked;st.page=1;sync()};
$('psort').onchange=e=>{st.sort=e.target.value;st.page=1;renderShop()};
$('treeTgl').onclick=()=>$('tree').classList.toggle('open');}
function sync(){$('eBtn').classList.toggle('on',st.e);renderTree();renderShop()}

/* поиск */
function match(q,eOnly=st.e){
  const w=q.toLowerCase().replace(/ё/g,'е').split(/\s+/).filter(Boolean);if(!w.length)return[];
  const res=[];
  for(const p of ITEMS){
    if(eOnly&&p.e!==1)continue;
    if(!w.every(x=>p.lc.includes(x)))continue;
    // ранжирование: начало наименования → начало слова → вхождение
    let sc=0;for(const x of w){const i=p.lc.indexOf(x);sc+=i===0?0:(p.lc[i-1]===' '||p.lc[i-1]==':'||p.lc[i-1]=='«'||p.lc[i-1]=='"'||p.lc[i-1]=='(')?1:3}
    sc+=p.n.length/400; if(p.c>1)sc+=.5; // программы 1С выше отраслевых и сервисов
    res.push([sc,p]);
  }
  return res.sort((a,b)=>a[0]-b[0]).map(x=>x[1]);
}
function hl(t,q){let r=esc(t);q.split(/\s+/).filter(x=>x.length>0).forEach(x=>{r=r.replace(new RegExp('('+x.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>')});return r}
let sT;
if(SHOP){$('psearch').addEventListener('input',e=>{clearTimeout(sT);sT=setTimeout(()=>{
  const q=e.target.value.trim();
  if(q.length<1){$('sugg').classList.remove('on');if(st.q){st.q='';st.page=1;renderTree();renderShop()}return}
  const r=match(q);
  $('sugg').innerHTML=r.slice(0,7).map(p=>`<button data-code="${p.code}">${hl(p.n,q)}<span>${CAT.cats[p.c].n} · ${p.price!=null?money(p):''}</span></button>`).join('')+
    `<button class="all" data-all="1">${r.length?'Показать все результаты: '+r.length:'Ничего не найдено'}</button>`;
  $('sugg').classList.add('on');
  $('sugg').querySelectorAll('[data-code]').forEach(b=>b.onclick=()=>{showSearch(q,b.dataset.code)});
  $('sugg').querySelector('[data-all]').onclick=()=>showSearch(q);
},150)});
$('psearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();const q=e.target.value.trim();if(q)showSearch(q)}});
document.addEventListener('click',e=>{if(!e.target.closest('.qs'))$('sugg').classList.remove('on')});}
function showSearch(q,code){if(!SHOP){location.href=ROOT+'magazin-1c/?q='+encodeURIComponent(q)+(code?'&code='+encodeURIComponent(code):'');return}st.q=q;st.page=1;$('sugg').classList.remove('on');renderTree();renderShop(code);
  scrollTo({top:$('shop').querySelector('.cat-grid').getBoundingClientRect().top+scrollY-90,behavior:'smooth'})}

/* список */
function renderShop(focus){if(!SHOP)return;
  let L,title,crumbs='';
  if(st.q){L=match(st.q);title=`Поиск: «${esc(st.q)}»`;crumbs=`<a data-home>Каталог</a> → результаты поиска`}
  else{
    L=ITEMS.filter(p=>(st.c<0||p.c===st.c)&&(st.s<0||p.s===st.s)&&(!st.e||p.e===1));
    if(st.c<0){title=st.e?'Электронная поставка':'Все товары';crumbs=st.e?'<a data-home>Каталог</a> → электронная поставка':'Каталог продукции'}
    else{const g=CAT.cats[st.c];title=st.s<0?g.n:g.s[st.s];crumbs=`<a data-home>Каталог</a> → `+(st.s<0?g.n:`<a data-cat="${st.c}">${g.n}</a>`)}
  }
  if(focus){const f=L.findIndex(p=>p.code===focus);if(f>0)L=[L[f],...L.slice(0,f),...L.slice(f+1)]}
  const pv=p=>p.price*(p.cur==='$'?100:1);
  if(st.sort==='name')L=[...L].sort((a,b)=>a.n.localeCompare(b.n,'ru'));
  if(st.sort==='pa')L=[...L].sort((a,b)=>pv(a)-pv(b));
  if(st.sort==='pd')L=[...L].sort((a,b)=>pv(b)-pv(a));
  $('ptitle').innerHTML=title;$('crumbs').innerHTML=crumbs;
  // подразделы чипами
  if(!st.q&&st.c>=0){const g=CAT.cats[st.c];
    $('subchips').innerHTML=`<button class="chip cut${st.s<0?' on':''}" data-s="-1">Все</button>`+g.s.map((sn,si)=>cnt(st.c,si)?`<button class="chip cut${st.s===si?' on':''}" data-s="${si}">${sn}</button>`:'').join('');
    $('subchips').querySelectorAll('.chip').forEach(b=>b.onclick=()=>go(st.c,+b.dataset.s));
  } else $('subchips').innerHTML='';
  $('found').textContent=`Найдено: ${L.length.toLocaleString('ru-RU')}`+(st.e?' · только электронная поставка':'');
  const show=L.slice(0,st.page*PAGE);
  $('plist').innerHTML=show.length?show.map(p=>`<div class="prow cut">
    <div><h4>${st.q?hl(p.n,st.q):esc(p.n)}</h4><div class="meta"><span>Артикул: ${esc(p.code)}</span>${KIND_TAG(p)}${st.c<0||st.q?`<span>${CAT.cats[p.c].s[p.s]}</span>`:''}</div></div>
    <div class="pr">${money(p)}${p.cur==='$'?'<span class="usd">оплата в ₽ по курсу ЦБ</span>':''}</div>
    <button class="btn btn-primary cut${cart[p.code]?' added':''}" data-add="${esc(p.code)}">${cart[p.code]?'В корзине · '+cart[p.code]:'В корзину'}</button>
  </div>`).join(''):'<div class="empty">Ничего не найдено — попробуйте другой запрос или <a href="#contact" style="color:var(--slate);border-bottom:1px solid">напишите нам</a>.</div>';
  $('moreBtn').parentElement.style.display=L.length>show.length?'flex':'none';
  $('moreBtn').textContent=`Показать ещё (осталось ${L.length-show.length})`;
  $('plist').querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>addToCart(b.dataset.add));
  document.querySelectorAll('#crumbs [data-home]').forEach(a=>a.onclick=()=>go(-1,-1));
  document.querySelectorAll('#crumbs [data-cat]').forEach(a=>a.onclick=()=>go(+a.dataset.cat,-1));
}
if(SHOP)$('moreBtn').onclick=()=>{st.page++;renderShop()};

/* корзина */
let cart={};try{cart=JSON.parse(localStorage.getItem('infotech-cart2')||'{}')||{}}catch(e){cart={}}
const saveCart=()=>{try{localStorage.setItem('infotech-cart2',JSON.stringify(cart))}catch(e){}};
function cartTotals(){
  let rubS=0,usdS=0,count=0,eN=0,pN=0,sN=0;
  for(const[id,q]of Object.entries(cart)){const p=P(id);if(!p)continue;count+=q;if(p.cur==='$')usdS+=p.price*q;else rubS+=p.price*q;if(p.e===1)eN+=q;else if(p.e===2)sN+=q;else pN+=q}
  return{rubS,usdS,count,eN,pN,sN};
}
const totalText=t=>rub(t.rubS)+(t.usdS?' + '+usd(t.usdS):'');
let toastT;
function addToCart(id){
  cart[id]=(cart[id]||0)+1;saveCart();updateCart();renderShop();
  $('toastName').textContent=P(id).n;$('toast').classList.add('on');
  clearTimeout(toastT);toastT=setTimeout(()=>$('toast').classList.remove('on'),4500);
}
function updateCart(){
  for(const id in cart)if(!P(id)||cart[id]<1)delete cart[id];
  const t=cartTotals();
  $('cartCount').textContent=t.count;$('cartCount').classList.toggle('on',t.count>0);
  const ids=Object.keys(cart);
  $('cartList').innerHTML=ids.length?ids.map(id=>{const p=P(id),q=cart[id];return`<div class="citem">
      <h5>${esc(p.n)}<br><small style="font-weight:300;color:#6a8397">Артикул: ${esc(p.code)} · ${KIND(p).toLowerCase()}</small></h5>
      <button class="del" data-del="${esc(id)}" aria-label="Удалить">×</button>
      <div class="qty"><button class="cut" data-dec="${esc(id)}">−</button><input value="${q}" data-q="${esc(id)}" inputmode="numeric" aria-label="Количество"><button class="cut" data-inc="${esc(id)}">+</button></div>
      <div class="sum">${money(p,q)}</div></div>`}).join('')
    :'<div class="empty">Корзина пуста.<br><a href="${ROOT}magazin-1c/" style="color:var(--slate);border-bottom:1px solid">Перейти в магазин</a></div>';
  $('cartTotal').textContent=totalText(t);
  $('cartNote').textContent=t.usdS?'Оборудование в долларах оплачивается в рублях по курсу ЦБ на день оплаты':'';
  $('toCheckout').disabled=!ids.length;$('toCheckout').style.opacity=ids.length?1:.45;
  $('cartList').querySelectorAll('[data-del]').forEach(b=>b.onclick=()=>{delete cart[b.dataset.del];saveCart();updateCart();renderShop()});
  $('cartList').querySelectorAll('[data-inc]').forEach(b=>b.onclick=()=>{cart[b.dataset.inc]++;saveCart();updateCart();renderShop()});
  $('cartList').querySelectorAll('[data-dec]').forEach(b=>b.onclick=()=>{cart[b.dataset.dec]--;saveCart();updateCart();renderShop()});
  $('cartList').querySelectorAll('[data-q]').forEach(i=>i.onchange=()=>{cart[i.dataset.q]=Math.max(0,parseInt(i.value)||0);saveCart();updateCart();renderShop()});
  $('cartList').querySelectorAll('[data-close]').forEach(a=>a.onclick=closeAll);
}
function openCart(){$('toast').classList.remove('on');$('drawer').classList.add('on');$('ovl').classList.add('on')}
function closeAll(){$('drawer').classList.remove('on');$('ovl').classList.remove('on');$('checkout').classList.remove('on');document.body.style.overflow=''}
$('cartBtn').onclick=openCart;$('ovl').onclick=closeAll;
document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',closeAll));
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAll()});
$('toastGo').onclick=()=>{$('toast').classList.remove('on');openCheckout()};
$('toCheckout').onclick=openCheckout;

/* оформление по шагам */
let step=1,doneSteps=new Set();
function openCheckout(){
  if(!Object.keys(cart).length){openCart();return}
  $('toast').classList.remove('on');$('drawer').classList.remove('on');$('ovl').classList.add('on');
  $('coForm').style.display='';$('coDone').style.display='none';
  step=1;doneSteps=new Set();renderCheckout();
  $('checkout').classList.add('on');document.body.style.overflow='hidden';$('checkout').scrollTop=0;
}
const val=n=>document.querySelector(`input[name=${n}]:checked`).value;
const pos=n=>n+' '+(n%10===1&&n%100!==11?'позиция':(n%10>=2&&n%10<=4&&(n%100<10||n%100>=20))?'позиции':'позиций');
function dlvText(){
  const t=cartTotals(),parts=[];
  if(t.eN)parts.push('электронная поставка на e-mail');
  if(t.sN)parts.push('услуги ИНФОТЕХ — подключение после оплаты');
  if(t.pN)parts.push(val('dlv').toLowerCase()+(val('dlv').startsWith('Курьер')&&$('city').value?' ('+$('city').value+')':''));
  if($('needInst').checked)parts.push('установка специалистом');
  return parts.join(' + ');
}
function renderCheckout(){
  const t=cartTotals();
  $('otab').innerHTML=Object.entries(cart).map(([id,q])=>{const p=P(id);return`<tr><td>${esc(p.n)}<br><small style="color:#6a8397">${KIND(p)}</small></td><td>${esc(p.code)}</td><td>${money(p)}</td><td>${q} шт</td><td><b>${money(p,q)}</b></td></tr>`}).join('');
  document.querySelectorAll('.stp').forEach(el=>{const n=+el.dataset.step;el.classList.toggle('cur',n===step);el.classList.toggle('done',doneSteps.has(n))});
  $('dlE').style.display=t.eN?'flex':'none';$('dlEn').textContent=pos(t.eN);
  $('dlS').style.display=t.sN?'flex':'none';$('dlSn').textContent=pos(t.sN);
  $('dlPhys').style.display=t.pN?'block':'none';$('dlPn').textContent=pos(t.pN);
  $('cityWrap').style.display=t.pN&&val('dlv').startsWith('Курьер')?'block':'none';
  $('s1sum').textContent=`${t.count} шт · ${totalText(t)}`;
  $('s2sum').textContent=dlvText();
  $('s3sum').textContent=val('pay');
  $('s4sum').textContent=[$('bFio').value,$('bMail').value,$('bTel').value,$('bOrg').value].filter(Boolean).join(' · ');
  $('smGoods').textContent=totalText(t);
  $('smDlv').textContent=!t.pN?(t.eN?'e-mail, бесплатно':'не требуется'):val('dlv').startsWith('Самовывоз')?'бесплатно':'по тарифу';
  $('smTotal').textContent=totalText(t);
  $('smNote').textContent=t.usdS?'Сумма в $ — оплата в рублях по курсу ЦБ':'';
  $('placeOrder').disabled=step!==4;
}
function validStep(n){
  const t=cartTotals();
  if(n===2&&t.pN&&val('dlv').startsWith('Курьер')&&!$('city').value.trim()){$('city').classList.add('err');$('city').focus();return false}
  if(n===4){let ok=true;
    [['bFio',v=>v.trim().length>2],['bMail',v=>/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)],['bTel',v=>phoneOk(v)]].forEach(([id,f])=>{const bad=!f($(id).value);$(id).classList.toggle('err',bad);if(bad&&ok){$(id).focus();ok=false}});
    const inn=$('bInn').value.replace(/\D/g,'');if(inn&&!(inn.length===10||inn.length===12)){$('bInn').classList.add('err');if(ok)$('bInn').focus();ok=false}else $('bInn').classList.remove('err');
    return ok}
  return true;
}
document.querySelectorAll('[data-next]').forEach(b=>b.onclick=()=>{if(!validStep(step))return;doneSteps.add(step);step=Math.min(4,step+1);renderCheckout()});
document.querySelectorAll('[data-prev]').forEach(b=>b.onclick=()=>{step=Math.max(1,step-1);renderCheckout()});
document.querySelectorAll('[data-edit]').forEach(a=>a.onclick=()=>{step=+a.dataset.edit;renderCheckout()});
document.querySelectorAll('input[name=dlv],input[name=pay],#needInst').forEach(i=>i.onchange=renderCheckout);
['city','bFio','bMail','bTel','bInn'].forEach(id=>$(id).addEventListener('input',()=>$(id).classList.remove('err')));
/* отправка на сервер: /api/submit.php. Если сервера нет (просмотр макета) — демо-режим. */
const API='/api/submit.php';
async function postJSON(data){
  const r=await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
  let j=null;try{j=await r.json()}catch(e){}
  if(!j) throw {demo:true};
  if(!r.ok||!j.ok) throw {msg:(j&&j.error)||'Не удалось отправить. Попробуйте ещё раз или позвоните нам.'};
  return j;
}
$('placeOrder').onclick=async()=>{
  if(!validStep(4))return;doneSteps.add(4);
  const t=cartTotals();
  const lines=Object.entries(cart).map(([id,q])=>{const p=P(id);return`• ${p.n} (арт. ${p.code}) — ${q} шт × ${money(p)}${p.e===1?' [e-mail]':p.e===2?' [услуга]':''}`});
  const txtBody=`${lines.join('\n')}\n\nТоваров на: ${totalText(t)}${t.usdS?' (сумма в $ — по курсу ЦБ)':''}\nПоставка: ${dlvText()}\nОплата: ${val('pay')}\n\nПокупатель: ${$('bFio').value}\nE-mail для электронной поставки: ${$('bMail').value}\nТелефон: ${$('bTel').value}${$('bInn').value?'\nИНН: '+$('bInn').value:''}${$('bOrg').value?'\nОрганизация: '+$('bOrg').value:''}${$('bCom').value?'\nКомментарий: '+$('bCom').value:''}`;
  const btn=$('placeOrder');btn.disabled=true;btn.textContent='Отправляем…';$('smNote').textContent='';
  let no,demo=false;$('smNote').style.color='';
  try{
    const j=await postJSON({type:'order',website:$('hpOrder').value,
      items:Object.entries(cart).map(([code,qty])=>({code,qty})),
      delivery:t.pN?val('dlv'):'',city:$('city').value,need_install:$('needInst').checked,payment:val('pay'),
      buyer:{name:$('bFio').value,email:$('bMail').value,phone:$('bTel').value,inn:$('bInn').value,company:$('bOrg').value},comment:$('bCom').value});
    no=j.number;
  }catch(e){
    if(!e.demo){btn.disabled=false;btn.textContent='Оформить заказ';$('smNote').textContent=e.msg||'Ошибка соединения. Попробуйте ещё раз.';$('smNote').style.color='#b5452f';return}
    demo=true;no='ИТ-'+Date.now().toString().slice(-6);
  }
  btn.textContent='Оформить заказ';
  $('doneTitle').textContent=demo?`Заказ ${no} сформирован`:`Заказ ${no} принят`;
  $('doneLead').textContent=demo
    ?'Это демонстрационный просмотр: сервер не подключён, заказ никуда не отправлен.'
    :`Спасибо! Подтверждение отправлено на ${$('bMail').value}. Менеджер свяжется с вами, уточнит детали и пришлёт счёт.`+(t.eN?' Электронные лицензии придут на этот e-mail после оплаты.':'');
  $('doneText').textContent=`Заказ ${no}\n\n`+txtBody;
  $('coForm').style.display='none';$('coDone').style.display='block';$('checkout').scrollTop=0;
  cart={};saveCart();updateCart();renderShop();
};
$('copyOrder').onclick=async()=>{try{await navigator.clipboard.writeText($('doneText').textContent);$('copyOrder').textContent='Скопировано'}catch(e){$('copyOrder').textContent='Выделите текст и скопируйте'}};
if(SHOP){
  $('pdate').textContent=CAT.date;
  const U=new URLSearchParams(location.search);
  if(U.get('e')==='1'){st.e=true;$('eOnly').checked=true;$('eBtn').classList.add('on')}
  if(U.get('cat')!==null){const c=CAT.cats.findIndex(x=>x.k===U.get('cat')||x.n===U.get('cat'));if(c>=0){st.c=c;const sb=U.get('sub');if(sb){const i=CAT.cats[c].s.indexOf(sb);if(i>=0)st.s=i}}}
  if(U.get('q')){st.q=U.get('q');$('psearch').value=st.q}
  renderTree();renderShop(U.get('code')||undefined);
}
updateCart();
/* ================= Тарифы ИНФОТЕХ: аренда, сопровождение, сравнение ИТС ================= */
(function(){
  if($('rent')){
  let line='Стандарт',per=1;
  const D=OWN.discounts;
  $('rentPeriod').innerHTML=Object.entries(D).map(([m,d])=>`<button class="chip cut${m==1?' on':''}" data-m="${m}">${MONTHS(+m)}${d?' · −'+Math.round(d*100)+'%':''}</button>`).join('');
  $('rentPeriod').querySelectorAll('.chip').forEach(c=>c.onclick=()=>{per=+c.dataset.m;$('rentPeriod').querySelectorAll('.chip').forEach(x=>x.classList.toggle('on',x===c));draw()});
  const sw=$('lineSw'),knob=sw.querySelector('.knob');
  function moveKnob(){const on=sw.querySelector('button.on');knob.style.left=on.offsetLeft+'px';knob.style.width=on.offsetWidth+'px'}
  sw.querySelectorAll('button').forEach(b=>b.onclick=()=>{line=b.dataset.l;sw.querySelectorAll('button').forEach(x=>x.classList.toggle('on',x===b));moveKnob();draw()});
  addEventListener('resize',moveKnob);
  const ALLX=['Рекомендации по оптимизации 1С (по запросу)','Помощь при обновлениях платформы 1С','Консультация по оптимальной архитектуре базы','Контроль производительности 1С'];
  function draw(){
    const L=OWN.server_lines[line]||{};
    $('lineSpec').innerHTML=`<span>Память: <b>${L.memory||''}</b></span><span>Процессор: <b>${L.cpu||''}</b></span><span>Диски: <b>${L.disks||''}</b></span>`;
    const d=D[per]||0;
    $('tgrid').innerHTML=OWN.server_tariffs.filter(t=>t.line===line).map((t,i)=>{
      const m=t.price*(1-d),code=`INF-SRV-${t.id}-${String(per).padStart(2,'0')}`,hit=(line==='Стандарт'?i===1:i===0);
      return `<div class="tcard outline cut${hit?' hit':''}"><div class="inner cut">
        <div class="tn"><h4>${t.name}</h4>${hit?'<span class="badge cut">Выбирают чаще</span>':''}</div>
        <div class="pur">${t.purpose}</div>
        <div class="tp">${fmt(m)} <small>₽/мес</small>${d?`<span class="old">${fmt(t.price)}</span>`:''}</div>
        <div class="tmig${t.price>=10000?'':' paid'}">${t.price>=10000?'✓ Перенос базы — бесплатно':'Перенос базы — платно'}</div>
        <div class="tot">${per>1?`${fmt(m*per)} ₽ за ${MONTHS(per)}, экономия ${fmt(t.price*per*d)} ₽`:'оплата помесячно'}</div>
        <div class="tsp"><div class="cut"><b>${t.cpu}</b><span>vCPU</span></div><div class="cut"><b>${t.ram}</b><span>ГБ RAM</span></div><div class="cut"><b>${t.disk}</b><span>ГБ диск</span></div></div>
        <div class="tdesc">${t.desc}</div>
        <ul><li>Пользователей: ${t.users}, активных баз 1С: ${t.bases}</li><li>Резервное копирование: ${t.backup.toLowerCase()}, хранится ${t.copies}</li><li>Настройка RDP-доступа: ${t.rdp}</li>
        ${ALLX.map(x=>`<li class="${t.extra.includes(x)?'':'dim'}">${x}</li>`).join('')}</ul>
        <button class="btn btn-primary cut" data-add="${code}">Заказать «${t.name}»</button></div></div>`}).join('');
    $('tgrid').querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>addToCart(b.dataset.add));
  }
  $('baseList').innerHTML=(OWN.server_base||[]).map(x=>`<li>${x}</li>`).join('');
  draw();setTimeout(moveKnob,50);

  }
  if($('support')){
  // сопровождение
  $('svcChips').innerHTML=(OWN.support_services||[]).map(x=>`<span class="cut">${x}</span>`).join('');
  $('supTable').innerHTML='<tr><th>Тариф</th><th>Часов в месяц, до</th><th>Стоимость часа</th><th>Абонентская плата</th><th>Час сверх пакета</th><th></th></tr>'+
    (OWN.support_tariffs||[]).map(t=>`<tr><td>Тариф ${t.n}</td><td class="num">${t.hours} ч</td><td class="num">${fmt(t.rate)} ₽</td><td class="num fee">${fmt(t.fee)} ₽/мес</td><td class="num">${fmt(t.over)} ₽</td><td><button class="btn btn-primary cut" data-add="INF-SUP-${t.n}">Подключить</button></td></tr>`).join('');
  $('supTable').querySelectorAll('[data-add]').forEach(b=>b.onclick=()=>addToCart(b.dataset.add));

  }
  if($('its')){
  // цены 1С:КП из прайса 1С
  const pr=c=>{const p=P(c);return p?fmt(p.price)+' ₽':'—'};
  $('itsPrices').innerHTML=`<div class="card cut"><b>${pr('КППроф12')}</b><span>1С:КП ПРОФ на 12 месяцев · при продлении без перерыва ${pr('КППроф12ЛЦ')}</span></div>
    <div class="card cut"><b>${pr('КППроф1')}</b><span>1С:КП ПРОФ на 1 месяц · при продлении ${pr('КППроф1ЛЦ')}</span></div>
    <div class="card cut"><b>${pr('КПБаз12')}</b><span>1С:КП Базовый на 12 месяцев — для базовых версий программ</span></div>`;
  $('itsPrices').insertAdjacentHTML('afterend','<p style="font-size:13px;color:var(--muted);margin:-8px 0 18px">Цены — рекомендованные фирмой «1С». <a href="#shop" id="itsShop" style="color:var(--slate);border-bottom:1px solid">Все тарифы 1С:КП в магазине →</a> Условия ИТС Техно уточняйте у менеджера.</p>');
  $('itsShop').href=ROOT+'magazin-1c/?q='+encodeURIComponent('1С:КП');

  const Y='<span class="yes">',N='<span class="no">—</span>';
  const G=t=>`<tr class="g"><td colspan="3">${t}</td></tr>`;
  const R=(a,b,c)=>`<tr><td>${a}</td><td>${b?Y+b+'</span>':N}</td><td>${c?Y+c+'</span>':N}</td></tr>`;
  const rows=[
    G('Доступ к официальной поддержке'),
    R('Обновления программных продуктов 1С','Неограниченно','Неограниченно'),
    R('Линия консультаций фирмы «1С»','Неограниченно','1 раз в месяц'),
    G('Услуги партнёра'),
    R('Обновление платформы и типовых конфигураций, диагностика информационной базы, архивная копия, проверка подключённых сервисов','Ежемесячно',''),
    R('Настройка доступа к интернет-версии ИС 1С:ИТС или установка офлайн-версии, обучение','Однократно при начале сопровождения',''),
    R('Подборка новых материалов ИС 1С:ИТС и консультационно-методических материалов по запросу','Ежемесячно',''),
    R('Проверка настройки интернет-поддержки в программе 1С','Однократно при начале сопровождения',''),
    R('Знакомство с возможностями сервисов тарифа: 1С-Отчетность, 1С-ЭДО, 1С:Контрагент и др.','Ежемесячно',''),
    R('Линия консультаций партнёра по телефону или e-mail','На усмотрение партнёра','На усмотрение партнёра'),
    R('Продажа и установка программ и лицензий, настройка, доработка, обучение, обновление нетиповых конфигураций и др.','По тарифам партнёра','По тарифам партнёра'),
    R('Для «1С:Предприятие 8 КОРП»: обновление платформы КОРП и корпоративная техподдержка по SLA','В период действия РКЛ',''),
    G('Дополнительно'),
    R('Журнал «БУХ.1С» (электронный)','Ежемесячно','Ежемесячно'),
    R('Электронные книги, аудио- и видеозаписи','Ежемесячно',''),
    R('Подарочные акции «Зима подарков» и «Лето подарков 1С:ИТС»','В период акций',''),
    G('Сервисы 1С:ИТС'),
    R('<b>1С:Контрагент</b><small>Автозаполнение реквизитов и проверка контрагентов</small>','7 200 автозаполнений, 360 «Досье контрагента»',''),
    R('<b>1С-Отчетность</b><small>Подготовка и отправка регламентированной отчётности из 1С</small>','1 ИП или юрлицо + обособленные подразделения для ФНС',''),
    R('<b>1С-ЭДО / 1С-Такском</b><small>Обмен юридически значимыми документами</small>','Отправка 100 комплектов в месяц, получение без ограничений','Отправка 50 комплектов в месяц, получение без ограничений'),
    R('<b>Информационная система 1С:ИТС</b><small>Справочники, методики, руководства, консультации</small>','Полный доступ','Ограниченный доступ'),
    R('<b>1С:Лекторий</b><small>Семинары по законодательству и программам 1С</small>','До 10 человек от организации, записи без ограничений',''),
    R('<b>Отвечает аудитор</b><small>Письменные консультации экспертов 1С</small>','Неограниченно',''),
    R('<b>1С-Коннект</b><small>Чат и оперативная поддержка пользователей</small>','2 пользователя — любые линии партнёра и вендоров; все пользователи — линии вендоров и одна линия партнёра','Все пользователи — линии вендоров и одна линия партнёра'),
    R('<b>1С:Облачный архив</b><small>Резервное копирование баз в облако</small>','20 ГБ (20–30 копий баз)',''),
    R('<b>1С:Линк</b><small>Доступ через интернет к 1С на вашем компьютере</small>','2 информационные базы, пользователи — по числу лицензий',''),
    R('<b>1С:Сверка</b><small>Сверка счетов-фактур с контрагентами</small>','Неограниченно','Неограниченно'),
    R('<b>1С:ДиректБанк</b><small>Платежи и выписки прямо из 1С</small>','Неограниченно','Неограниченно'),
    R('<b>1С:Торговая площадка</b><small>Для участников «Бизнес-Сети»</small>','Неограниченно','Неограниченно'),
    R('<b>ЭДО без электронной подписи</b><small>Для участников «Бизнес-Сети»</small>','Неограниченно','Неограниченно'),
    R('<b>«1С:Предприятие» через Интернет</b><small>Облачный сервис 1С</small>','Приложения тарифа «Проф», 5 сеансов, 10 баз',''),
    R('<b>1С:Подпись</b><small>Квалифицированный сертификат электронной подписи</small>','1 лицензия',''),
    R('<b>1С:Номенклатура</b><small>Загрузка карточек товаров из каталога</small>','10 000 карточек','5 000 карточек'),
    G('Доступ к Информационной системе 1С:ИТС'),
    R('Методические материалы по настройке и использованию программ 1С','Да','Да'),
    R('Рекомендации по разработке и администрированию программ 1С','Да','Да'),
    R('Справочники по бухгалтерскому и налоговому учёту в программах 1С','Да',''),
    R('Справочники по налогам и взносам','Да',''),
    R('Справочники по бухгалтерской, налоговой отчётности и отчётности по взносам','Да',''),
    R('Справочники по ЭДО, УПД и УКД в программах 1С','Да',''),
    R('Справочники по кадрам, кадровому учёту и расчётам с персоналом','Да',''),
    R('Помощники расчёта отпускных, командировочных и пособий','Да','Да'),
    R('Правовой справочник руководителя','Да','Да'),
    R('Справочник по договорам и налоговым последствиям','Да',''),
    R('Справочник по контрольно-кассовой технике','Да','Да'),
    R('Ответы аудиторов и экспертов по учёту, налогам и кадрам','Да',''),
    R('Ответы специалистов 1С на вопросы по программе','Да','Да'),
    R('Нормативно-правовая база «1С:Гарант»','Да',''),
    R('Комментарии экспертов 1С к законам, письмам и решениям судов','Да','Да'),
    R('Бухгалтерская периодика и книги издательства «1С-Паблишинг»','Да','')
  ];
  $('itsCmp').innerHTML='<thead><tr><th>Что входит</th><th>1С:КП ПРОФ</th><th>ИТС Техно</th></tr></thead><tbody>'+rows.map((r,i)=>i>16?r.replace('<tr','<tr data-x="1"'):r).join('')+'</tbody>';
  const hide=on=>$('itsCmp').querySelectorAll('[data-x]').forEach(r=>r.classList.toggle('hid',on));
  let open=false;hide(true);
  $('itsMore').onclick=()=>{open=!open;hide(!open);$('itsMore').textContent=open?'Свернуть сравнение':'Показать полное сравнение'};
  }
})();

/* быстрый поиск в шапке: подсказки с первой буквы */
(function(){
  const inp=$('hq'),box=$('hsugg');let list=[],act=-1,t;
  function draw(q){
    const r=match(q,false);list=r.slice(0,8);act=-1;
    if(!q){box.classList.remove('on');return}
    const groups={};list.forEach(p=>{(groups[p.c]=groups[p.c]||[]).push(p)});
    box.innerHTML=(list.length?Object.keys(groups).map(c=>`<div class="g">${CAT.cats[c].n}</div>`+groups[c].map(p=>`<button data-code="${esc(p.code)}"><span class="pp">${money(p)}</span>${hl(p.n,q)}<span>Арт. ${esc(p.code)}${p.e===1?' · электронная поставка':p.e===2?' · услуга ИНФОТЕХ':''}</span></button>`).join('')).join(''):'')+
      `<button class="all" data-all>${r.length?'Все результаты в каталоге: '+r.length.toLocaleString('ru-RU')+' →':'По запросу «'+esc(q)+'» ничего не найдено'}</button>`;
    box.classList.add('on');
    box.querySelectorAll('[data-code]').forEach(b=>b.onclick=()=>pick(q,b.dataset.code));
    box.querySelector('[data-all]').onclick=()=>pick(q);
  }
  function pick(q,code){box.classList.remove('on');if(!SHOP){showSearch(q,code);return}st.e=false;$('eOnly').checked=false;$('eBtn').classList.remove('on');$('psearch').value=q;showSearch(q,code)}
  inp.addEventListener('input',()=>{clearTimeout(t);t=setTimeout(()=>draw(inp.value.trim()),90)});
  inp.addEventListener('focus',()=>{if(inp.value.trim())draw(inp.value.trim())});
  inp.addEventListener('keydown',e=>{
    const bs=[...box.querySelectorAll('button')];
    if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();act=(act+(e.key==='ArrowDown'?1:-1)+bs.length)%bs.length;bs.forEach((b,i)=>b.classList.toggle('act',i===act));bs[act]&&bs[act].scrollIntoView({block:'nearest'})}
    else if(e.key==='Enter'){e.preventDefault();if(act>=0&&bs[act])bs[act].click();else if(inp.value.trim())pick(inp.value.trim())}
    else if(e.key==='Escape'){box.classList.remove('on');inp.blur()}
  });
  document.addEventListener('click',e=>{if(!e.target.closest('#hsearch'))box.classList.remove('on')});
})();


/* форма */
if($('reqForm'))$('reqForm').addEventListener('submit',async e=>{e.preventDefault();const f=e.target,b=f.querySelector('button[type=submit]');if(!phoneOk(f.phone.value)){f.phone.classList.add('err');f.phone.setCustomValidity('Введите номер полностью: +7 (XXX) XXX-XX-XX');f.phone.reportValidity();return}b.disabled=true;
  let msg;try{const j=await postJSON({type:'lead',website:f.website.value,name:f.name.value,phone:f.phone.value,company:f.company.value,comment:(f.dataset.service?'Услуга: '+f.dataset.service+'\n':'')+f.msg.value});
    msg=`Спасибо! Заявка ${j.number} принята — мы перезвоним в рабочее время.`;f.reset()}
  catch(x){msg=x.demo?'Демонстрационный просмотр: сервер не подключён, заявка не отправлена.':x.msg}
  b.disabled=false;$('ok').textContent=msg;$('ok').style.display='block'});


/* ================= Маска телефона +7 ================= */
function fmtPhone(v){
  const d=v.replace(/\D/g,'');
  let n=v.trim().startsWith('+7')?d.slice(1):((d.length>=11&&(d[0]==='7'||d[0]==='8'))?d.slice(1):d);
  if(n.length>10&&(n[0]==='8'||n[0]==='7'))n=n.slice(1);   // набрали 8 после +7
  n=n.slice(0,10);
  let r='+7 ('+n.slice(0,3);
  if(n.length>3)r+=') '+n.slice(3,6);
  if(n.length>6)r+='-'+n.slice(6,8);
  if(n.length>8)r+='-'+n.slice(8,10);
  return n.length?r:'+7 (';
}
const phoneOk=v=>{const d=v.replace(/\D/g,'');return d.length===11&&(d[0]==='7'||d[0]==='8')};
document.querySelectorAll('input[type=tel],input[name=phone]').forEach(el=>{
  el.type='tel';el.required=true;el.autocomplete='tel';el.inputMode='tel';
  el.placeholder='+7 (___) ___-__-__';el.maxLength=19;
  el.addEventListener('focus',()=>{if(!el.value)el.value='+7 ('});
  el.addEventListener('blur',()=>{if(el.value.replace(/\D/g,'').length<=1)el.value=''});
  el.addEventListener('input',()=>{const pos=el.selectionStart===el.value.length;el.value=fmtPhone(el.value);if(pos)el.setSelectionRange(el.value.length,el.value.length);el.classList.remove('err');el.setCustomValidity('')});
  el.addEventListener('paste',e=>{e.preventDefault();el.value=fmtPhone((e.clipboardData||window.clipboardData).getData('text'))});
});

/* появление при прокрутке */
const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}}),{threshold:.12});
document.querySelectorAll('.rv').forEach((el,i)=>{el.style.transitionDelay=(i%4)*70+'ms';io.observe(el)});
document.querySelectorAll('#menu a').forEach(a=>a.addEventListener('click',()=>$('menu').classList.remove('open')));
