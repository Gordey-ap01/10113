(() => {
  'use strict';
  const form = document.getElementById('search-form');
  if (!form) return;
  const names = {greenspark:'GreenSpark',taggsm:'TAGGSM',moba:'MOBA',dvsota:'DVSOTA'};
  const sourceState = Object.fromEntries(Object.keys(names).map(k=>[k,{status:'idle',items:[]}])) ;
  const tech = document.getElementById('filter-tech'), source = document.getElementById('filter-source');
  const origin = document.getElementById('filter-origin'), condition = document.getElementById('filter-condition');
  const maxPrice = document.getElementById('filter-price'), stock = document.getElementById('filter-stock');
  const local = document.getElementById('filter-local');
  const sort = document.getElementById('sort'), results = document.getElementById('results');
  const query = document.getElementById('query'), suggestions = document.getElementById('search-suggestions');
  const historyStatus = document.getElementById('history-status');
  const parts = {all:'Все детали',display:'Экран',battery:'Аккумулятор',charging:'Нижняя плата / шлейф',back:'Задняя крышка'};
  let part = 'all', run = 0, searchContext = null, history = [], historyRequest = 0;
  const money = v => new Intl.NumberFormat('ru-RU',{maximumFractionDigits:0}).format(v)+' ₽';
  const element = (tag, text, cls) => { const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e; };
  const labels = {original_claimed:'Оригинал по описанию',copy:'Копия / аналог',unknown:'Не указано',new:'Новое по описанию',pulled:'Снятое',restored:'Восстановленное'};
  function selectPart(value) {
    part=value;
    document.querySelectorAll('[data-part]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.part===value)));
  }
  function renderSuggestions() {
    const text=query.value.trim().toLocaleLowerCase('ru-RU');
    suggestions.replaceChildren();
    history.filter(item=>item.q.toLocaleLowerCase('ru-RU').includes(text)).slice(0,6).forEach(item=>{
      const button=element('button',undefined,'suggestion');button.type='button';
      button.append(element('span',item.q));
      if(item.part!=='all')button.append(element('small',parts[item.part]));
      button.title=`Повторить поиск: ${item.q} · ${parts[item.part]}`;
      button.addEventListener('click',()=>{query.value=item.q;selectPart(item.part);renderSuggestions();form.requestSubmit();});
      suggestions.append(button);
    });
  }
  async function updateHistory(context=null) {
    const id=++historyRequest;
    try {
      const response=await fetch('/api/history',context?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(context)}:{credentials:'same-origin'});
      if(!response.ok)throw new Error('History unavailable');
      const data=await response.json();if(id!==historyRequest)return;
      history=data.items;
      historyStatus.textContent=history.length?'Нажмите на подсказку, чтобы повторить поиск с актуальными ценами.':'Ваши запросы появятся здесь после поиска. Часто повторяемые будут выше.';
      renderSuggestions();
    } catch {
      if(id===historyRequest)historyStatus.textContent='Не удалось обновить подсказки. Поиск у поставщиков доступен.';
    }
  }
  function render() {
    const sources = document.getElementById('sources');sources.replaceChildren();
    Object.entries(names).forEach(([key,name])=>{
      const data=sourceState[key], box=element('div',undefined,'source');box.dataset.status=data.status;box.append(element('b',name));
      let text='Ещё не проверен';
      if(data.status==='loading')text='Проверяем сайт…';
      else if(data.status==='error')text='Не удалось обновить';
      else if(data.status==='ok')text=`${data.items.length} предложений · ${new Date(data.checked_at).toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit',timeZone:'Asia/Vladivostok'})}${data.cached?' · кеш':''}`;
      box.append(element('span',text));
      if(key==='greenspark')box.append(element('small','Комсомольск-на-Амуре · Ленина, 4'));
      if(data.next_page){const more=element('button',data.loadingMore?'Загружаем…':'Ещё предложения','quiet');more.type='button';more.disabled=!!data.loadingMore;more.addEventListener('click',()=>loadMore(key));box.append(more);}
      if(data.pageError)box.append(element('span','Следующую страницу получить не удалось'));
      sources.append(box);
    });
    let items=Object.values(sourceState).flatMap(s=>s.status==='ok'?s.items:[]).filter(r=>(source.value==='all'||source.value===r.supplier)&&(tech.value==='all'||(tech.value==='unknown'?r.technology===null:tech.value===r.technology))&&(origin.value==='all'||origin.value===r.origin)&&(condition.value==='all'||condition.value===r.condition)&&(maxPrice.value===''||r.price!==null&&r.price<=Number(maxPrice.value))&&(!stock.checked||r.stock==='in')&&(!local.checked||r.is_local&&r.stock==='in'));
    const priority=r=>r.is_local&&r.stock==='in'?0:r.stock==='in'?1:r.stock==='out'?3:2;
    items.sort((a,b)=>(sort.value==='supplier'?names[a.supplier].localeCompare(names[b.supplier]):sort.value==='local'?priority(a)-priority(b):0)||Number(a.price===null)-Number(b.price===null)||((a.price??0)-(b.price??0)));
    const busy=Object.values(sourceState).some(s=>s.status==='loading');
    document.getElementById('result-count').textContent=`Предложений: ${items.length}${busy?' · поиск продолжается':''}`;
    results.replaceChildren();
    if(!items.length) results.append(element('div',busy?'Получаем данные с сайтов поставщиков…':local.checked?'В полученных предложениях нет подтверждённого наличия в Комсомольске-на-Амуре. Снимите этот фильтр, чтобы посмотреть другие склады.':run?'По выбранным условиям предложения не найдены. Попробуйте изменить запрос или фильтры.':'Результаты появятся здесь после поиска.','empty'));
    items.forEach(r=>{
      const card=element('article',undefined,'offer');const supplier=element('div',undefined,'supplier');supplier.append(element('b',names[r.supplier]),element('span',r.sku));card.append(supplier,element('h3',r.name));
      if(r.is_local&&r.stock==='in'){card.classList.add('local-offer');card.append(element('div','В наличии в Комсомольске','local-badge'));}
      const tags=element('div',undefined,'tags');[r.technology,labels[r.origin],labels[r.condition]].filter(Boolean).forEach(t=>tags.append(element('span',t,'tag')));card.append(tags);
      card.append(element('div',({in:'В наличии',out:'Нет в наличии',order:'Под заказ / ожидается',unknown:'Наличие уточняется'})[r.stock]??'Уточнить наличие','stock '+r.stock),element('div',r.warehouse??'Склад не указан','warehouse'));
      const p=element('div',undefined,'money');p.append(element('b',r.price===null?'Цена уточняется':money(r.price)),element('span','цена детали'));card.append(p);
      const link=element('a','Открыть у поставщика ↗');link.href=r.url;link.target='_blank';link.rel='noopener noreferrer';card.append(link);
      const details=element('details');details.append(element('summary','Цена, наличие и условия'));
      details.append(element('p','Тип цены: '+r.price_type));
      if(r.other_prices?.length)details.append(element('p','Другие цены на сайте: '+r.other_prices.map(p=>typeof p==='object'?`${p.name}: ${p.price===null?'уточнить':money(p.price)}`:money(p)).join('; ')+'. Применимость тарифа уточните у поставщика.'));
      if(r.stock_details)details.append(element('p',r.stock_details));
      details.append(element('p',sourceState[r.supplier].context));card.append(details);results.append(card);
    });
  }
  async function loadMore(supplier) {
    const id=run,context=searchContext,previous=sourceState[supplier];
    if(!context||!previous.next_page||previous.loadingMore)return;
    sourceState[supplier]={...previous,loadingMore:true,pageError:false};render();
    try {
      const response=await fetch('/api/search?'+new URLSearchParams({...context,supplier,page:previous.next_page}),{credentials:'same-origin'});
      if(response.status===401){location.assign('/');return;}
      const data=await response.json();if(!response.ok)throw new Error('Source unavailable');
      if(id!==run)return;
      const merged=new Map([...previous.items,...data.items].map(item=>[item.id,item]));
      sourceState[supplier]={...data,items:[...merged.values()]};
    } catch {if(id===run)sourceState[supplier]={...previous,pageError:true};}
    if(id===run)render();
  }
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(!form.reportValidity())return;
    const id=++run,q=query.value.trim();
    searchContext={q,part};
    updateHistory(searchContext);
    const button=document.getElementById('search-button');button.disabled=true;button.textContent='Ищем…';
    Object.keys(names).forEach(k=>sourceState[k]={status:'loading',items:[]});render();
    await Promise.all(Object.keys(names).map(async supplier=>{
      try { const response=await fetch('/api/search?'+new URLSearchParams({q,supplier,part}),{credentials:'same-origin'}); const data=await response.json();if(run!==id)return;if(response.status===401){location.assign('/');return;}sourceState[supplier]=response.ok?data:{status:'error',items:[],error:data.error}; }
      catch { if(run===id)sourceState[supplier]={status:'error',items:[]}; }
      if(run===id)render();
    }));
    if(run===id){button.disabled=false;button.textContent='Найти у поставщиков';}
  });
  document.querySelectorAll('[data-part]').forEach(b=>b.addEventListener('click',()=>selectPart(b.dataset.part)));
  query.addEventListener('input',renderSuggestions);
  [source,tech,origin,condition,maxPrice,stock,local,sort].forEach(e=>e.addEventListener('input',render));
  const dialog=document.getElementById('account-dialog');document.getElementById('account-open').addEventListener('click',()=>dialog.showModal());document.getElementById('account-close').addEventListener('click',()=>dialog.close());
  document.getElementById('account-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget,m=document.getElementById('account-message');try{const response=await fetch('/api/account',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(Object.fromEntries(new FormData(f)))});const data=await response.json();m.textContent=data.ok?'Пароль изменён':data.error;if(data.ok)f.reset();}catch{m.textContent='Не удалось сохранить. Повторите позже.'}});
  render();
  updateHistory();
})();
