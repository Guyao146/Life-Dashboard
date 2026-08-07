const $=s=>document.querySelector(s), c=$('#clock'), modal=$('#connect-modal'), toast=$('#toast');
    let OIDC=null;
    const redirectUri=location.origin+location.pathname;
    let HA=null;
    async function loadRuntimeConfig(){
      const config=window.LIFE_HUB_CONFIG;
      const missing=[];
      if(!config?.oidc?.clientId)missing.push('oidc.clientId');
      if(!config?.oidc?.authorize)missing.push('oidc.authorize');
      if(!config?.oidc?.token)missing.push('oidc.token');
      if(missing.length)throw new Error(`登录配置缺失：${missing.join('、')}。请将 config.example.js 复制为 config.js 并填写 OAuth 配置`);
      OIDC={...config.oidc};
      HA=config.homeAssistant?.url?{...config.homeAssistant,url:String(config.homeAssistant.url).replace(/\/$/,'')}:null;
    }
    const b64url=b=>btoa(String.fromCharCode(...new Uint8Array(b))).replaceAll('+','-').replaceAll('/','_').replaceAll('=','');
    async function pkce(){const bytes=crypto.getRandomValues(new Uint8Array(48)),verifier=b64url(bytes),digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(verifier));return {verifier,challenge:b64url(digest)}}
    function loginError(m){$('#auth-error').textContent=m}
    async function signIn(){
      if(!OIDC)throw new Error('登录配置尚未加载完成');
      if(!window.isSecureContext)throw new Error('Authentik 登录需要 HTTPS 部署（localhost 除外）');
      const {verifier,challenge}=await pkce(),state=b64url(crypto.getRandomValues(new Uint8Array(20)));
      sessionStorage.setItem('life-hub-pkce',JSON.stringify({verifier,state}));
      const p=new URLSearchParams({client_id:OIDC.clientId,response_type:'code',redirect_uri:redirectUri,scope:'openid profile email',state,code_challenge:challenge,code_challenge_method:'S256'});
      location.assign(OIDC.authorize+'?'+p)
    }
    function enterDashboard(){
      sessionStorage.setItem('life-hub-dashboard-access','true');
      $('#auth-gate').classList.add('hidden');
      $('#app-loader').classList.add('hidden')
    }
    function readSession(key){try{return JSON.parse(sessionStorage.getItem(key)||'null')}catch(err){sessionStorage.removeItem(key);return null}}
    async function authenticate(){
      const params=new URLSearchParams(location.search),stored=readSession('life-hub-oidc');
      if(params.get('error')){
        sessionStorage.removeItem('life-hub-pkce');
        throw new Error(`验证服务拒绝登录：${params.get('error_description')||params.get('error')}`);
      }
      if(sessionStorage.getItem('life-hub-dashboard-access')==='true'||stored?.expiresAt>Date.now()){
        enterDashboard();
        return true
      }
      if(!params.get('code')){
        $('#app-loader').classList.add('hidden');
        return false
      }
      const pending=readSession('life-hub-pkce');
      if(!pending||pending.state!==params.get('state'))throw new Error('登录状态校验失败，请重新登录');
      const form=new URLSearchParams({grant_type:'authorization_code',client_id:OIDC.clientId,code:params.get('code'),redirect_uri:redirectUri,code_verifier:pending.verifier});
      const response=await fetch(OIDC.token,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:form});
      if(!response.ok){sessionStorage.removeItem('life-hub-pkce');throw new Error('验证服务未接受登录请求，请检查回调地址、客户端类型和 CORS 设置')}
      const tokens=await response.json();
      if(!tokens?.access_token)throw new Error('验证服务返回的登录结果缺少 access_token');
      sessionStorage.setItem('life-hub-oidc',JSON.stringify({...tokens,expiresAt:Date.now()+(tokens.expires_in||3600)*1000}));
      sessionStorage.removeItem('life-hub-pkce');
      history.replaceState({},document.title,redirectUri);enterDashboard();return true
    }
    $('#auth-login').onclick=()=>{
      loginError('正在跳转到 验证服务…');
      signIn().catch(e=>loginError(e.message))
    };
    $('#auth-continue')?.addEventListener('click',enterDashboard);
    function logout(){
      sessionStorage.removeItem('life-hub-oidc');
      sessionStorage.removeItem('life-hub-pkce');
      sessionStorage.removeItem('life-hub-dashboard-access');
      location.assign(redirectUri)
    }
    $('#auth-logout').onclick=logout;
    let renderedDate='';
    function renderLocalDate(d){
      const weekdays=['星期日','星期一','星期二','星期三','星期四','星期五','星期六'], shortDays=['日','一','二','三','四','五','六'];
      $('#today').textContent=`${d.getMonth()+1} 月 ${d.getDate()} 日，${weekdays[d.getDay()]}`;
      $('#weekday-label').textContent=`${weekdays[d.getDay()].replace('星期','')} / YOUR SPACE`;
      $('#greeting').textContent=`${d.getHours()<12?'早上好':d.getHours()<18?'下午好':'晚上好'}，Gu`;
      const eta=new Date(d.getTime()+40*60*1000);$('#delivery-eta').textContent=eta.toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit',hour12:false});
      const monday=new Date(d);monday.setHours(0,0,0,0);monday.setDate(d.getDate()-((d.getDay()+6)%7));
      $('#habit-grid').innerHTML=Array.from({length:7},(_,i)=>{const day=new Date(monday);day.setDate(monday.getDate()+i);return `<div class="day ${i<((d.getDay()+6)%7)?'done':''}">${shortDays[day.getDay()]}<b>${day.getDate()}</b><i class="check"></i></div>`}).join('');
      [...$('#energy-bars').querySelectorAll('em')].forEach((label,i)=>{const day=new Date(d);day.setDate(d.getDate()-(3-i));label.textContent=i===3?'今天':`周${shortDays[day.getDay()]}`});
    }
    function tick(){const d=new Date();c.textContent=d.toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit',hour12:false});const dateKey=d.toDateString();if(dateKey!==renderedDate){renderedDate=dateKey;renderLocalDate(d)}} tick();setInterval(tick,1000);
    function notice(message){toast.textContent=message;toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),3200)}
    function showModal(){modal.classList.add('show')} function hideModal(){modal.classList.remove('show')}
    $('#settings-connect').onclick=showModal; $('#cancel-connect').onclick=hideModal;
    modal.addEventListener('click',e=>{if(e.target===modal)hideModal()});
    $('#quick-picker').onclick=()=>openPicker();$('#cancel-picker').onclick=()=>$('#picker-modal').classList.remove('show');$('#picker-modal').onclick=e=>{if(e.target===$('#picker-modal'))$('#picker-modal').classList.remove('show')};$('#save-picker').onclick=()=>{const ids=[...$('#picker-list').querySelectorAll('input:checked')].map(input=>input.value).slice(0,6);saveQuickIds(ids);renderQuick(latestStates);$('#picker-modal').classList.remove('show');notice('快捷控制已保存')};
    $('#close-detail').onclick=()=>$('#device-modal').classList.remove('show');$('#device-modal').onclick=e=>{if(e.target===$('#device-modal'))$('#device-modal').classList.remove('show')};$('#detail-control').onclick=()=>{if(selectedDetail)toggle(selectedDetail.entity_id)};$('#device-search').oninput=()=>renderDevices(latestStates);
    const widgetKey='life-hub-widgets',layoutKey='life-hub-layout',widgetNames={home:'家庭环境',delivery:'外卖配送',agenda:'今日日程',quick:'快捷控制',devices:'HA 设备列表',habit:'本周习惯',energy:'能量使用'};
    let activeView='overview';
    const defaultOrder=Object.keys(widgetNames),defaultSizes={home:'medium',delivery:'small',agenda:'medium',quick:'small',devices:'large',habit:'medium',energy:'small'},sizeNames={small:'小',medium:'中',large:'大'};
    function hiddenWidgets(){try{const value=JSON.parse(localStorage.getItem(widgetKey)||'[]');return new Set(Array.isArray(value)?value:[])}catch(err){localStorage.removeItem(widgetKey);return new Set()}}
    function layout(){try{const saved=JSON.parse(localStorage.getItem(layoutKey)||'{}'),order=Array.isArray(saved.order)?saved.order.filter(id=>defaultOrder.includes(id)):[],sizes=saved.sizes&&typeof saved.sizes==='object'?saved.sizes:{};return {order:[...order,...defaultOrder.filter(id=>!order.includes(id))],sizes}}catch(err){localStorage.removeItem(layoutKey);return {order:defaultOrder.slice(),sizes:{}}}}
    function saveLayout(next){try{localStorage.setItem(layoutKey,JSON.stringify(next))}catch(err){notice('无法保存组件布局')}}
    function currentWidgetSize(id){const saved=layout().sizes[id];return sizeNames[saved]?saved:(defaultSizes[id]||'medium')}
    function updateCardDraggable(card){if(!card)return;card.draggable=false;const handle=card.querySelector('.widget-drag-handle');if(handle)handle.draggable=window.innerWidth>900&&!card.querySelector('.widget-menu.is-open')}
    function widgetPopover(menu){return document.getElementById(menu.dataset.popoverId)}
    function closeWidgetMenus(except=null){document.querySelectorAll('.widget-menu.is-open').forEach(menu=>{if(menu===except)return;menu.classList.remove('is-open');widgetPopover(menu)?.classList.remove('is-open');menu.querySelector('.widget-menu-trigger').setAttribute('aria-expanded','false');updateCardDraggable(menu.closest('[data-widget-id]'))})}
    function refreshWidgetMenus(){document.querySelectorAll('.widget-menu').forEach(menu=>{const current=currentWidgetSize(menu.closest('[data-widget-id]').dataset.widgetId);widgetPopover(menu)?.querySelectorAll('[data-widget-size]').forEach(button=>{const active=button.dataset.widgetSize===current;button.classList.toggle('active',active);button.setAttribute('aria-checked',String(active))})})}
    function positionWidgetMenu(menu){const trigger=menu.querySelector('.widget-menu-trigger'),popover=widgetPopover(menu);if(!popover)return;const rect=trigger.getBoundingClientRect(),width=window.innerWidth<=580?180:164,height=popover.offsetHeight||132;popover.style.left=Math.max(10,Math.min(window.innerWidth-width-10,rect.right-width))+'px';popover.style.top=(rect.bottom+8+height<=window.innerHeight?rect.bottom+8:Math.max(10,rect.top-height-8))+'px'}
    function setupWidgetMenus(){document.querySelectorAll('.grid>[data-widget-id]').forEach(card=>{if(card.querySelector('.widget-menu'))return;const id=card.dataset.widgetId,popoverId=`widget-size-menu-${id}`,handle=document.createElement('span');handle.className='widget-drag-handle';handle.setAttribute('role','img');handle.setAttribute('aria-label',`拖动${widgetNames[id]}组件`);handle.title='按住拖动组件';handle.innerHTML='<i></i><i></i><i></i><i></i><i></i><i></i>';const menu=document.createElement('div');menu.className='widget-menu';menu.dataset.popoverId=popoverId;menu.innerHTML=`<button class="widget-menu-trigger" type="button" title="调整组件尺寸" aria-label="调整${widgetNames[id]}尺寸" aria-haspopup="menu" aria-controls="${popoverId}" aria-expanded="false">⋮</button>`;const popover=document.createElement('div');popover.id=popoverId;popover.className='widget-menu-popover';popover.setAttribute('role','menu');popover.setAttribute('aria-label',`${widgetNames[id]}组件尺寸`);popover.innerHTML=Object.entries(sizeNames).map(([value,name])=>`<button type="button" role="menuitemradio" data-widget-size="${value}"><span class="size-preview size-${value}"></span><span>${name}卡</span><small>${value==='small'?'1 列':value==='medium'?'2 列':'3 列'}</small></button>`).join('');document.body.append(popover);menu.querySelector('.widget-menu-trigger').onclick=e=>{e.stopPropagation();const open=!menu.classList.contains('is-open');closeWidgetMenus(menu);menu.classList.toggle('is-open',open);popover.classList.toggle('is-open',open);e.currentTarget.setAttribute('aria-expanded',String(open));updateCardDraggable(card);if(open){refreshWidgetMenus();requestAnimationFrame(()=>positionWidgetMenu(menu))}};popover.querySelectorAll('[data-widget-size]').forEach(button=>button.onclick=e=>{e.stopPropagation();const next=layout();next.sizes[id]=button.dataset.widgetSize;saveLayout(next);applyLayout();closeWidgetMenus();if(activeView==='settings')renderWidgetSettings();notice(`${widgetNames[id]}已调整为${sizeNames[button.dataset.widgetSize]}卡`)});card.querySelector('.card-head').append(handle,menu);updateCardDraggable(card)});refreshWidgetMenus()}
    function applyLayout(){const grid=$('.grid'),current=layout();current.order.forEach(id=>{const card=grid.querySelector(`[data-widget-id="${id}"]`);if(card)grid.append(card)});grid.querySelectorAll('[data-widget-id]').forEach(card=>{const size=current.sizes[card.dataset.widgetId]||defaultSizes[card.dataset.widgetId]||'medium';card.classList.remove('size-small','size-medium','size-large');card.classList.add('size-'+(sizeNames[size]?size:'medium'));updateCardDraggable(card)});refreshWidgetMenus()}
    function applyView(){const hidden=hiddenWidgets();applyLayout();document.querySelectorAll('.grid>[data-widget-id]').forEach(card=>{const inView=activeView==='overview'||card.dataset.views.split(' ').includes(activeView);card.classList.toggle('is-hidden',!inView||hidden.has(card.dataset.widgetId))});$('.grid').classList.toggle('is-hidden',activeView==='settings');$('.settings-page').classList.toggle('is-active',activeView==='settings')}
    function renderWidgetSettings(){const hidden=hiddenWidgets(),current=layout(),root=$('#widget-settings');root.innerHTML=current.order.map(id=>`<div class="widget-setting-row"><label class="widget-toggle"><span><b>${widgetNames[id]}</b><small>${hidden.has(id)?'已隐藏':'显示在看板中'}</small></span><input type="checkbox" value="${id}" ${hidden.has(id)?'':'checked'}><i></i></label><select class="widget-size" data-size-id="${id}" aria-label="${widgetNames[id]}尺寸">${Object.entries(sizeNames).map(([value,name])=>`<option value="${value}" ${(current.sizes[id]||defaultSizes[id]||'medium')===value?'selected':''}>${name}卡</option>`).join('')}</select></div>`).join('');root.querySelectorAll('input').forEach(input=>input.onchange=()=>{const next=hiddenWidgets();input.checked?next.delete(input.value):next.add(input.value);localStorage.setItem(widgetKey,JSON.stringify([...next]));renderWidgetSettings();applyView()});root.querySelectorAll('.widget-size').forEach(select=>select.onchange=()=>{const next=layout();next.sizes[select.dataset.sizeId]=select.value;saveLayout(next);applyView();notice(`${widgetNames[select.dataset.sizeId]}已调整为${sizeNames[select.value]}卡`)})}
    function setView(view){activeView=view;document.querySelectorAll('[data-view]').forEach(item=>item.classList.toggle('active',item.dataset.view===view));applyView();if(view==='settings')renderWidgetSettings();window.scrollTo({top:0,behavior:'smooth'})}
    document.querySelectorAll('[data-view]').forEach(button=>button.onclick=()=>setView(button.dataset.view));
    $('#settings-reset').onclick=()=>{localStorage.removeItem(widgetKey);localStorage.removeItem(layoutKey);localStorage.removeItem('life-hub-quick');renderWidgetSettings();setView('overview');notice('布局和小组件已恢复默认')};
    const grid=$('.grid');let draggedCard=null;const reorderAnimations=new WeakMap();
    setupWidgetMenus();
    document.addEventListener('click',()=>closeWidgetMenus());document.addEventListener('keydown',e=>{if(e.key==='Escape')closeWidgetMenus()});window.addEventListener('resize',()=>{applyLayout();closeWidgetMenus()});
    function animateCardReorder(target,before){const cards=[...grid.querySelectorAll('[data-widget-id]')],first=new Map(cards.map(card=>[card,card.getBoundingClientRect()])),reference=before?target:target.nextSibling;if(reference===draggedCard||draggedCard.nextSibling===reference)return;target.parentNode.insertBefore(draggedCard,reference);cards.forEach(card=>{if(card===draggedCard)return;const start=first.get(card),end=card.getBoundingClientRect(),dx=start.left-end.left,dy=start.top-end.top;if(Math.abs(dx)<1&&Math.abs(dy)<1)return;reorderAnimations.get(card)?.cancel();if(typeof card.animate!=='function')return;const animation=card.animate([{transform:`translate(${dx}px,${dy}px)`},{transform:'translate(0, 0)'}],{duration:190,easing:'cubic-bezier(.2,.8,.2,1)'});reorderAnimations.set(card,animation);animation.onfinish=()=>{if(reorderAnimations.get(card)===animation)reorderAnimations.delete(card)}})}
    function dragGhost(card){const ghost=document.createElement('div');ghost.className='widget-drag-ghost';ghost.textContent=card.querySelector('h2')?.textContent||widgetNames[card.dataset.widgetId];document.body.append(ghost);return ghost}
    grid.addEventListener('dragstart',e=>{const handle=e.target.closest('.widget-drag-handle'),card=handle?.closest('[data-widget-id]');if(!handle||!card||window.innerWidth<=900){e.preventDefault();return}closeWidgetMenus();draggedCard=card;const ghost=dragGhost(card);e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',card.dataset.widgetId);e.dataTransfer.setDragImage(ghost,18,18);requestAnimationFrame(()=>{card.classList.add('is-dragging');ghost.remove()})});
    grid.addEventListener('dragover',e=>{const target=e.target.closest('[data-widget-id]');if(!draggedCard||!target||target===draggedCard)return;e.preventDefault();e.dataTransfer.dropEffect='move';const rect=target.getBoundingClientRect(),before=Math.abs(e.clientY-(rect.top+rect.height/2))>rect.height*.25?e.clientY<rect.top+rect.height/2:e.clientX<rect.left+rect.width/2;animateCardReorder(target,before)});
    grid.addEventListener('dragend',()=>{if(!draggedCard)return;draggedCard.classList.remove('is-dragging');saveLayout({...layout(),order:[...grid.querySelectorAll('[data-widget-id]')].map(card=>card.dataset.widgetId)});draggedCard=null;notice('组件位置已保存')});
    $('#settings-logout').onclick=logout;
    applyView();
    $('#refresh-home').onclick=()=>sync({}).then(()=>notice('已刷新 Home Assistant 数据')).catch(err=>notice(err.message));
    document.querySelectorAll('.more:not(#refresh-home):not(#quick-picker):not(#close-detail)').forEach(button=>button.onclick=()=>notice('该数据源尚未接入，可继续扩展'));
    let syncTimer=null, syncing=false, latestStates=[], selectedDetail=null;
    const controllableDomains=['light','switch','climate','vacuum','fan','media_player','cover'];
    const canControl=s=>controllableDomains.includes(s.entity_id.split('.')[0]);
    function entity(states, domain, words=[]){return states.find(s=>s.entity_id.startsWith(domain+'.') && (words.length===0||words.some(w=>(s.attributes.friendly_name||s.entity_id).toLowerCase().includes(w))))}
    function value(s){return s?.state && !['unknown','unavailable'].includes(s.state) ? s.state : null}
    function label(s){return s.attributes.friendly_name||s.entity_id.split('.').pop().replaceAll('_',' ')}
    function quickIds(){try{const ids=JSON.parse(localStorage.getItem('life-hub-quick')||'[]');return Array.isArray(ids)?ids:[]}catch(err){localStorage.removeItem('life-hub-quick');return []}}
    function saveQuickIds(ids){try{localStorage.setItem('life-hub-quick',JSON.stringify(ids))}catch(err){notice('无法保存快捷设备，但本次选择仍可使用')}}
    function renderQuick(states){const safeStates=Array.isArray(states)?states:[],eligible=safeStates.filter(canControl);let ids=quickIds();if(!ids.length)ids=eligible.slice(0,4).map(s=>s.entity_id);const chosen=ids.map(id=>safeStates.find(s=>s.entity_id===id)).filter(Boolean);const grid=$('#quick-grid');grid.innerHTML='';(chosen.length?chosen:eligible.slice(0,4)).forEach(s=>grid.append(makeQuick(s)))}
    function renderDevices(states){const query=$('#device-search').value.trim().toLowerCase(),items=states.filter(s=>{const hay=(label(s)+' '+s.entity_id).toLowerCase();return !query||hay.includes(query)}),groups=new Map(),list=$('#device-list');items.forEach(s=>{const domain=s.entity_id.split('.')[0];if(!groups.has(domain))groups.set(domain,[]);groups.get(domain).push(s)});$('#device-count').textContent=items.length+' 个实体';list.innerHTML='';if(!items.length){list.innerHTML='<div class="empty-picker">没有匹配的 Home Assistant 实体。</div>';return}groups.forEach((group,domain)=>{const [name,icon]=deviceGroups[domain]||[domain.replaceAll('_',' '),'◉'],details=document.createElement('details');details.className='device-group ha-device-group';details.open=Boolean(query);details.innerHTML=`<summary><span class="device-group-icon">${icon}</span><span>${escapeHtml(name)}</span><small>${group.length} 个实体</small></summary><div class="device-items">${group.map(s=>`<button class="device-item" data-entity-id="${escapeHtml(s.entity_id)}"><strong>${escapeHtml(label(s))}</strong><small class="${['on','home','open','playing'].includes(s.state)?'state-on':''}">${escapeHtml(s.state)} · ${escapeHtml(s.entity_id)}</small></button>`).join('')}</div>`;details.querySelectorAll('.device-item').forEach(button=>button.onclick=()=>openDevice(group.find(s=>s.entity_id===button.dataset.entityId)));list.append(details)})}
    function openDevice(s){selectedDetail=s;$('#detail-name').textContent=label(s);$('#detail-state').textContent=s.state;const attrs=Object.entries(s.attributes||{}).filter(([k])=>k!=='friendly_name').slice(0,20);$('#detail-attrs').innerHTML=attrs.map(([k,v])=>`<div><span>${escapeHtml(k)}</span><b>${escapeHtml(typeof v==='object'?JSON.stringify(v):v)}</b></div>`).join('')||'<div><span>没有额外属性</span></div>';$('#detail-control').style.display=canControl(s)?'inline-block':'none';$('#device-modal').classList.add('show')}
    const deviceGroups={light:['灯光','☼'],fan:['风扇','◉'],climate:['空调与温控','♨'],vacuum:['清洁设备','◔'],cover:['窗帘与遮阳','▤'],switch:['开关','⏻'],media_player:['媒体设备','♬']};
    function updatePickerCount(){const inputs=[...$('#picker-list').querySelectorAll('input:checked')];$('#picker-count').textContent=`${inputs.length} / 6`;return inputs.length}
    function openPicker(){const pickerModal=$('#picker-modal'),list=$('#picker-list');pickerModal.classList.add('show');try{const selected=new Set(quickIds()),groups=new Map(),safeStates=Array.isArray(latestStates)?latestStates:[];safeStates.filter(canControl).forEach(s=>{const domain=s.entity_id.split('.')[0];if(!groups.has(domain))groups.set(domain,[]);groups.get(domain).push(s)});list.innerHTML='';if(!groups.size){list.innerHTML='<div class="empty-picker">暂无可添加的设备，请先连接 Home Assistant。</div>'}groups.forEach((states,domain)=>{const [name,icon]=deviceGroups[domain]||['其他设备','◉'],details=document.createElement('details');details.className='device-group';details.open=states.some(s=>selected.has(s.entity_id));details.innerHTML=`<summary><span class="device-group-icon">${icon}</span><span>${name}</span><small>${states.length} 个设备</small></summary><div class="picker-options">${states.map(s=>`<label class="picker-option"><input type="checkbox" value="${escapeHtml(s.entity_id)}" ${selected.has(s.entity_id)?'checked':''}><span title="${escapeHtml(s.entity_id)}">${escapeHtml(label(s))}</span></label>`).join('')}</div>`;list.append(details)});list.querySelectorAll('input').forEach(input=>input.onchange=()=>{if(updatePickerCount()>6){input.checked=false;updatePickerCount();notice('快捷控制最多选择 6 个设备')}});updatePickerCount()}catch(err){list.innerHTML='<div class="empty-picker">读取设备列表时出现问题，请刷新后重试。</div>';$('#picker-count').textContent='0 / 6';console.error('无法打开快捷设备选择器：',err)}}
    function makeQuick(s){const active=['on','cleaning','playing'].includes(s.state), icon=s.entity_id.startsWith('light.')?'☼':s.entity_id.startsWith('climate.')?'♨':s.entity_id.startsWith('vacuum.')?'◔':'◉';const b=document.createElement('button');b.className=active?'on':'';b.innerHTML=`<span class="qicon">${icon}</span><span class="qname">${escapeHtml(label(s))}</span><small>${active?'已开启':'已关闭'}</small>`;b.onclick=()=>toggle(s.entity_id);return b}
    async function toggle(id){try{const cfg={};const domain=id.split('.')[0], state=(await api(`/api/states/${id}`,cfg)).state;await api(`/api/services/${domain}/${['on','cleaning','playing'].includes(state)?'turn_off':'turn_on'}`,cfg,{method:'POST',body:JSON.stringify({entity_id:id})});await sync(cfg);notice('设备状态已更新')}catch(err){notice(err.message)}}
    async function api(path,_cfg,options={}){if(!HA?.url||!HA?.token)throw new Error('Home Assistant 配置缺失，请检查 config.js');const headers={Authorization:'Bearer '+HA.token,...(options.headers||{})};if(options.body)headers['Content-Type']='application/json';const r=await fetch(HA.url.replace(/\/$/,'')+path,{...options,headers});if(!r.ok){const body=(await r.text()).replace(/\s+/g,' ').slice(0,500);throw new Error(`${options.method||'GET'} ${path} 返回 HTTP ${r.status}${body?`：${body}`:''}`)}try{return await r.json()}catch{throw new Error(`${options.method||'GET'} ${path} 返回了无法解析的 JSON 响应`)}}
    const escapeHtml=s=>String(s||'').replace(/[&<>'\"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
    const TODO_ENTITY='todo.login_ren_wu';
    function agendaError(title,detail){$('#agenda-source').textContent='MS365 To Do 同步失败';$('#agenda-list').innerHTML=`<div class="agenda-row"><span class="agenda-time">!</span><div><div class="agenda-title">${escapeHtml(title)}</div><div class="agenda-meta">${escapeHtml(detail)}</div></div><span class="tag life">错误</span></div>`}
    function renderTodos(todos,listName){$('#agenda-list').innerHTML='';$('#agenda-source').textContent='MS365 To Do';if(!todos.length){$('#agenda-list').innerHTML='<div class="agenda-row"><span class="agenda-time">✓</span><div><div class="agenda-title">没有未完成任务</div><div class="agenda-meta">todo.login_ren_wu 已成功同步</div></div><span class="tag life">MS365 To Do</span></div>';return}$('#agenda-list').innerHTML=todos.slice(0,3).map(todo=>{const due=todo.due?.dateTime||todo.due||todo.due_date;let dueText='未设置截止日期';if(due){const d=new Date(due);dueText=Number.isNaN(d.getTime())?String(due):'截止 '+d.toLocaleDateString('zh-CN',{month:'numeric',day:'numeric'})}return `<div class="agenda-row"><span class="agenda-time">待办</span><div><div class="agenda-title">${escapeHtml(todo.subject||todo.summary||todo.title||'未命名任务')}</div><div class="agenda-meta">${escapeHtml(dueText)}</div></div><span class="tag life">${escapeHtml(listName)}</span></div>`}).join('')}
    async function syncTodo(states,cfg){const preferred=states.find(s=>s.entity_id===TODO_ENTITY);if(!preferred){const ids=states.filter(s=>s.entity_id.startsWith('todo.')).map(s=>s.entity_id).join(', ')||'未发现任何 todo.* 实体';agendaError(`找不到实体 ${TODO_ENTITY}`,`Home Assistant 当前待办实体：${ids}`);return}const listName=label(preferred),fromState=preferred.attributes.all_todos||preferred.attributes.items;if(Array.isArray(fromState)){renderTodos(fromState,listName);return}try{const result=await api('/api/services/todo/get_items?return_response',cfg,{method:'POST',body:JSON.stringify({entity_id:TODO_ENTITY,status:'needs_action'})});const data=result.service_response?.[TODO_ENTITY]||result[TODO_ENTITY]||{};if(!Array.isArray(data.items))throw new Error(`服务响应中未找到 ${TODO_ENTITY}.items：${JSON.stringify(result).slice(0,500)}`);renderTodos(data.items,listName)}catch(err){console.error('MS365 To Do 同步失败：',err);agendaError('无法读取 MS365 To Do 任务',err.message)}}
    function calendarFailure(error){
      const message=error?.message||String(error);
      const cors=/failed to fetch|cors|networkerror/i.test(message);
      $('#agenda-source').textContent=cors?'日历跨域未授权':'日历同步失败';
      $('#agenda-list').innerHTML=`<div class="agenda-row"><span class="agenda-time">!</span><div><div class="agenda-title">${cors?'无法读取 Home Assistant 日历':'日历暂时无法同步'}</div><div class="agenda-meta">${cors?'home.mcylyr.cn 的 OPTIONS 响应缺少 Access-Control-Allow-Origin；需在反向代理放行 life.mcylyr.cn':'请稍后刷新重试'}</div></div><span class="tag">日历</span></div>`;
      console.error('Home Assistant 日历同步失败：',error);
    }
    async function syncCalendar(states,cfg){try{const calendars=states.filter(s=>s.entity_id.startsWith('calendar.'));if(!calendars.length)return;const start=new Date(),end=new Date();start.setHours(0,0,0,0);end.setHours(23,59,59,999);const result=await api('/api/services/calendar/get_events?return_response',cfg,{method:'POST',body:JSON.stringify({entity_id:calendars.map(s=>s.entity_id),start_date_time:start.toISOString(),end_date_time:end.toISOString()})});const response=result.service_response||result;const events=calendars.flatMap(cal=>(response[cal.entity_id]?.events||[]).map(e=>({...e,calendar:label(cal)}))).sort((a,b)=>new Date(a.start.dateTime||a.start.date)-new Date(b.start.dateTime||b.start.date)).slice(0,4);if(!events.length)return;$('#agenda-source').textContent='Home Assistant 日历';$('#agenda-list').innerHTML=events.map(e=>{const allDay=!!e.start.date,time=allDay?'全天':new Date(e.start.dateTime).toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit',hour12:false});return `<div class="agenda-row"><span class="agenda-time">${time}</span><div><div class="agenda-title">${escapeHtml(e.summary)}</div><div class="agenda-meta">${escapeHtml(e.location||e.calendar)}</div></div><span class="tag">${escapeHtml(e.calendar)}</span></div>`}).join('')}catch(error){calendarFailure(error)}}
    async function sync(cfg){if(syncing)return;syncing=true;try{const states=await api('/api/states',cfg);latestStates=states;const temp=entity(states,'sensor',['temperature','温度']), humidity=entity(states,'sensor',['humidity','湿度']);if(value(temp))$('#temperature').textContent=value(temp);$('#environment').textContent=[humidity&&value(humidity)?'湿度 '+value(humidity)+(humidity.attributes.unit_of_measurement||'%'):'已连接 Home Assistant','刚刚同步'].filter(Boolean).join(' · ');$('#ha-status').textContent='实时同步';const connectionLabel=$('#connection-label');if(connectionLabel)connectionLabel.textContent='已连接 · 刚刚同步';const serviceCount=$('#service-count');if(serviceCount)serviceCount.textContent=states.length+' 个实体已同步';const rooms=states.filter(s=>s.entity_id.startsWith('climate.')).slice(0,2);if(rooms.length)$('#room-list').innerHTML=rooms.map(s=>`<span>${label(s)} ${s.attributes.current_temperature??s.state}°</span>`).join('');renderQuick(states);renderDevices(states);await syncCalendar(states,cfg);await syncTodo(states,cfg) }finally{syncing=false;$('#app-loader').classList.add('hidden')}}
    function beginAutoSync(cfg){if(syncTimer)clearInterval(syncTimer);syncTimer=setInterval(()=>sync(cfg).catch(err=>{$('#ha-status').textContent='同步失败';const connectionLabel=$('#connection-label');if(connectionLabel)connectionLabel.textContent='连接需要检查'}),30000)}
    $('#connect-form').onsubmit=async e=>{
      e.preventDefault();
      if(!HA?.url||!HA?.token){notice('请先在 config.js 中填写 Home Assistant 地址和长期访问令牌');return}
      try{
        notice('正在连接…');
        await sync(HA);
        beginAutoSync(HA);
        hideModal();
        notice('Home Assistant 已连接，并每 30 秒同步')
      }catch(err){notice(err.message)}
    };
    loadRuntimeConfig().then(authenticate).then(ok=>{
      if(!ok)return;
      if(!HA?.url||!HA?.token){
        $('#ha-status').textContent='待连接';
        $('#environment').textContent='登录成功 · 请配置 Home Assistant';
        notice('登录成功，请先配置 Home Assistant');
        return;
      }
      sync(HA).then(()=>beginAutoSync(HA)).catch(()=>notice('Home Assistant 连接失败，请检查 config.js 与 CORS 设置'))
    }).catch(e=>{loginError(e.message);$('#app-loader').classList.add('hidden')});
    document.querySelectorAll('.quick-grid button').forEach(b=>b.addEventListener('click',()=>{if(sessionStorage.getItem('life-hub-oidc'))return;b.classList.toggle('on');b.querySelector('small').textContent=b.classList.contains('on')?'已开启':'已关闭'}));
