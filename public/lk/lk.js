/* Личный кабинет: маска телефона, меню, заполнение реквизитов по ИНН */
(function(){
  function fmt(v){var d=v.replace(/\D/g,'');var n=v.trim().indexOf('+7')===0?d.slice(1):((d.length>=11&&(d[0]==='7'||d[0]==='8'))?d.slice(1):d);
    if(n.length>10&&(n[0]==='8'||n[0]==='7'))n=n.slice(1);n=n.slice(0,10);var r='+7 ('+n.slice(0,3);if(n.length>3)r+=') '+n.slice(3,6);if(n.length>6)r+='-'+n.slice(6,8);if(n.length>8)r+='-'+n.slice(8,10);return n.length?r:'+7 ('}
  document.querySelectorAll('input[type=tel]').forEach(function(i){i.maxLength=19;
    i.addEventListener('focus',function(){if(!i.value)i.value='+7 ('});
    i.addEventListener('blur',function(){if(i.value.replace(/\D/g,'').length<=1)i.value=''});
    i.addEventListener('input',function(){i.value=fmt(i.value)});});
  var b=document.getElementById('innFill');
  if(b)b.onclick=function(){var inn=document.getElementById('cInn').value.replace(/\D/g,'');if(inn.length!==10&&inn.length!==12){alert('Введите ИНН: 10 или 12 цифр');return}
    b.disabled=true;fetch('/lk/api.php?a=inn&inn='+inn,{headers:{'X-Requested-With':'pbk'}}).then(function(r){return r.json()}).then(function(d){b.disabled=false;
      if(!d.ok){alert('Организация не найдена — заполните вручную');return}
      document.getElementById('cName').value=d.name;document.getElementById('cKpp').value=d.kpp||'';document.getElementById('cOgrn').value=d.ogrn||'';document.getElementById('cAddr').value=d.address||''}).catch(function(){b.disabled=false})};
})();
