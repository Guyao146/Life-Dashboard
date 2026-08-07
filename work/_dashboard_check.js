const $=s=>document.querySelector(s), c=$('#clock'), modal=$('#connect-modal'), toast=$('#toast');
    let OIDC=null;
    const redirectUri=location.origin+location.pathname;
    let HA=null;
    async function loadRuntimeConfig(){const config=window.LIFE_HUB_CONFIG;if(!config?.oidc?.clientId||!config.oidc?.authorize||!config.oidc?.token||!config?.homeAssistant?.url||!config.homeAssistant?.token)throw new Error('未找到完整配置：请复制 config.example.js 为 config.js 并填写配置');OIDC=config.oidc;HA=config.homeAssistant}
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
    async function authenticate(){
      const params=new URLSearchParams(location.search),stored=sessionStorage.getItem('life-hub-oidc');
      if(sessionStorage.getItem('life-hub-dashboard-access')==='true'||stored&&JSON.parse(stored).expiresAt>Date.now()){
        enterDashboard();
        return true
      }
      if(!params.get('code')){
        $('#app-loader').classList.add('hidden');
        return false
      }
      const pending=JSON.parse(sessionStorage.getItem('life-hub-pkce')||'null');if(!pending||pending.state!==params.get('state'))throw new Error('登录状态校验失败，请重新登录');const form=new URLSearchParams({grant_type:'authorization_code',client_id:OIDC.clientId,code:params.get('code'),redirect_uri:redirectUri,code_verifier:pending.verifier});const response=await fetch(OIDC.token,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:form});if(!response.ok)throw new Error('Authentik 未接受登录请求，请检查回调地址和客户端类型');const tokens=await response.json();sessionStorage.setItem('life-hub-oidc',JSON.stringify({...tokens,expiresAt:Date.now()+(tokens.expires_in||3600)*1000}));sessionStorage.removeItem('life-hub-pkce');history.replaceState({},document.title,redirectUri);enterDashboard();return true
    }
    $('#auth-login').onclick=()=>{
      loginError('正在跳转到 验证服务…');
      signIn().catch(e=>loginError(e.message))
    };
    $('#auth-continue')?.addEventListener('click',enterDashboard);
    $('#auth-logout').onclick=()=>{
      sessionStorage.removeItem('life-hub-oidc');
      sessionStorage.removeItem('life-hub-pkce');
      sessionStorage.removeItem('life-hub-dashboard-access');
      loginError('已清除本机登录信息')
    };
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
      if($('#agenda-source').textContent==='本地示例'){
        const examples=[['产品周会','会议室 A / 腾讯会议','work','工作',60],['健身 · 上肢训练','燃动健身 · 已预约','life','生活',210],['给妈妈打电话','提醒我，不限时长','','个人',330]];
        $('#agenda-list').innerHTML=examples.map(([title,meta,kind,tag,offset])=>{const time=new Date(d.getTime()+offset*60000).toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit',hour12:false});return `<div class="agenda-row"><span class="agenda-time">${time}</span><div><div class="agenda-title">${title}</div><div class="agenda-meta">${meta}</div></div><span class="tag ${kind}">${tag}</span></div>`}).join('');
      }
    }
    function tick(){const d=new Date();c.textContent=d.toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit',hour12:false});const dateKey=d.toDateString();if(dateKey!==renderedDate){renderedDate=dateKey;renderLocalDate(d)}} tick();setInterval(tick,1000);
    function notice(message){toast.textContent=message;toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),3200)}
    function showModal(){modal.classList.add('show')} function hideModal(){modal.classList.remove('show')}
    $('#connect-button').onclick=showModal; $('#mobile-connect').onclick=showModal; $('#cancel-connect').onclick=hideModal;
    modal.addEventListener('click',e=>{if(e.target===modal)hideModal()});
    $('#quick-picker').onclick=openPicker;$('#cancel-picker').onclick=()=>$('#picker-modal').classList.remove('show');$('#picker-modal').onclick=e=>{if(e.target===$('#picker-modal'))$('#picker-modal').classList.remove('show')};$('#save-picker').onclick=()=>{const ids=[...$('#picker-list input:checked')].map(input=>input.value).slice(0,6);localStorage.setItem('life-hub-quick',JSON.stringify(ids));renderQuick(latestStates);$('#picker-modal').classList.remove('show');notice('快捷控制已保存')};
    $('#close-detail').onclick=()=>$('#device-modal').classList.remove('show');$('#device-modal').onclick=e=>{if(e.target===$('#device-modal'))$('#device-modal').classList.remove('show')};$('#detail-control').onclick=()=>{if(selectedDetail)toggle(selectedDetail.entity_id)};$('#device-search').oninput=()=>renderDevices(latestStates);
    document.querySelectorAll('[data-view]').forEach(button=>button.onclick=()=>{const view=button.dataset.view;document.querySelectorAll('[data-view]').forEach(item=>item.classList.toggle('active',item.dataset.view===view));document.querySelectorAll('[data-views]').forEach(card=>card.classList.toggle('is-hidden',view!=='overview'&&!card.dataset.views.split(' ').includes(view)));window.scrollTo({top:0,behavior:'smooth'})});
    $('#refresh-home').onclick=()=>sync({}).then(()=>notice('已刷新 Home Assistant 数据')).catch(err=>notice(err.message));
    document.querySelectorAll('.more:not(#refresh-home):not(#quick-picker):not(#close-detail)').forEach(button=>button.onclick=()=>notice('该数据源尚未接入，可继续扩展'));
    let syncTimer=null, syncing=false, latestStates=[], selectedDetail=null;
    const controllableDomains=['light','switch','climate','vacuum','fan','media_player','cover'];
    const canControl=s=>controllableDomains.includes(s.entity_id.split('.')[0]);
    function entity(states, domain, words=[]){return states.find(s=>s.entity_id.startsWith(domain+'.') && (words.length===0||words.some(w=>(s.attributes.friendly_name||s.entity_id).toLowerCase().includes(w))))}
    function value(s){return s?.state && !['unknown','unavailable'].includes(s.state) ? s.state : null}
    function label(s){return s.attributes.friendly_name||s.entity_id.split('.').pop().replaceAll('_',' ')}
    function renderQuick(states){const eligible=states.filter(canControl);let ids=JSON.parse(localStorage.getItem('life-hub-quick')||'[]');if(!ids.length)ids=eligible.slice(0,4).map(s=>s.entity_id);const chosen=ids.map(id=>states.find(s=>s.entity_id===id)).filter(Boolean);const grid=$('#quick-grid');grid.innerHTML='';(chosen.length?chosen:eligible.slice(0,4)).forEach(s=>grid.append(makeQuick(s)))}
    function renderDevices(states){const query=$('#device-search').value.trim().toLowerCase(), items=states.filter(s=>{const hay=(label(s)+' '+s.entity_id).toLowerCase();return !query||hay.includes(query)});$('#device-count').textContent=items.length+' 个实体';const list=$('#device-list');list.innerHTML='';items.forEach(s=>{const b=document.createElement('button');b.className='device-item';b.innerHTML=`<strong>${escapeHtml(label(s))}</strong><small class="${['on','home','open','playing'].includes(s.state)?'state-on':''}">${escapeHtml(s.state)} · ${escapeHtml(s.entity_id)}</small>`;b.onclick=()=>openDevice(s);list.append(b)})}
    function openDevice(s){selectedDetail=s;$('#detail-name').textContent=label(s);$('#detail-state').textContent=s.state;const attrs=Object.entries(s.attributes||{}).filter(([k])=>k!=='friendly_name').slice(0,20);$('#detail-attrs').innerHTML=attrs.map(([k,v])=>`<div><span>${escapeHtml(k)}</span><b>${escapeHtml(typeof v==='object'?JSON.stringify(v):v)}</b></div>`).join('')||'<div><span>没有额外属性</span></div>';$('#detail-control').style.display=canControl(s)?'inline-block':'none';$('#device-modal').classList.add('show')}
    const deviceGroups={light:['灯光','☼'],fan:['风扇','◉'],climate:['空调与温控','♨'],vacuum:['清洁设备','◔'],cover:['窗帘与遮阳','▤'],switch:['开关','⏻'],media_player:['媒体设备','♬']};
    function updatePickerCount(){const inputs=[...$('#picker-list input:checked')];$('#picker-count').textContent=`${inputs.length} / 6`;return inputs.length}
    function openPicker(){const selected=new Set(JSON.parse(localStorage.getItem('life-hub-quick')||'[]')),list=$('#picker-list'),groups=new Map();latestStates.filter(canControl).forEach(s=>{const domain=s.entity_id.split('.')[0];if(!groups.has(domain))groups.set(domain,[]);groups.get(domain).push(s)});list.innerHTML='';if(!groups.size){list.innerHTML='<div class="empty-picker">暂无可添加的设备，请先连接 Home Assistant。</div>'}groups.forEach((states,domain)=>{const [name,icon]=deviceGroups[domain]||['其他设备','◉'],details=document.createElement('details');details.className='device-group';details.open=states.some(s=>selected.has(s.entity_id));details.innerHTML=`<summary><span class="device-group-icon">${icon}</span><span>${name}</span><small>${states.length} 个设备</small></summary><div class="picker-options">${states.map(s=>`<label class="picker-option"><input type="checkbox" value="${escapeHtml(s.entity_id)}" ${selected.has(s.entity_id)?'checked':''}><span title="${escapeHtml(s.entity_id)}">${escapeHtml(label(s))}</span></label>`).join('')}</div>`;list.append(details)});list.querySelectorAll('input').forEach(input=>input.onchange=()=>{if(updatePickerCount()>6){input.checked=false;updatePickerCount();notice('快捷控制最多选择 6 个设备')}});updatePickerCount();$('#picker-modal').classList.add('show')}
    function makeQuick(s){const active=['on','cleaning','playing'].includes(s.state), icon=s.entity_id.startsWith('light.')?'☼':s.entity_id.startsWith('climate.')?'♨':s.entity_id.startsWith('vacuum.')?'◔':'◉';const b=document.createElement('button');b.className=active?'on':'';b.innerHTML=`<span class="qicon">${icon}</span><span class="qname">${escapeHtml(label(s))}</span><small>${active?'已开启':'已关闭'}</small>`;b.onclick=()=>toggle(s.entity_id);return b}
    async function toggle(id){try{const cfg={};const domain=id.split('.')[0], state=(await api(`/api/states/${id}`,cfg)).state;await api(`/api/services/${domain}/${['on','cleaning','playing'].includes(state)?'turn_off':'turn_on'}`,cfg,{method:'POST',body:JSON.stringify({entity_id:id})});await sync(cfg);notice('设备状态已更新')}catch(err){notice(err.message)}}
    async function api(path,_cfg,options={}){if(!HA?.url||!HA?.token)throw new Error('Home Assistant 配置缺失，请检查 config.js');const r=await fetch(HA.url.replace(/\/$/,'')+path,{...options,headers:{Authorization:'Bearer '+HA.token,'Content-Type':'application/json',...(options.headers||{})}});if(!r.ok)throw new Error(r.status===401?'Home Assistant 令牌无效或已失效':'无法连接 Home Assistant ('+r.status+')；请同时检查 Home Assistant CORS 设置');return r.json()}
    const escapeHtml=s=>String(s||'').replace(/[&<>'\"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
    const TODO_ENTITY='todo.login_ren_wu';
    function renderTodos(todos,listName){document.querySelectorAll('[data-todo-row]').forEach(row=>row.remove());if(!todos.length)return;if($('#agenda-source').textContent==='本地示例')$('#agenda-list').innerHTML='';$('#agenda-source').textContent=$('#agenda-source').textContent==='Home Assistant 日历'?'日历 · MS365 To Do':'MS365 To Do';const rows=todos.slice(0,3).map(todo=>{const due=todo.due?.dateTime||todo.due||todo.due_date;let dueText='未设置截止日期';if(due){const d=new Date(due);dueText=Number.isNaN(d.getTime())?String(due):'截止 '+d.toLocaleDateString('zh-CN',{month:'numeric',day:'numeric'})}return `<div class="agenda-row" data-todo-row><span class="agenda-time">待办</span><div><div class="agenda-title">${escapeHtml(todo.subject||todo.summary||todo.title||'未命名任务')}</div><div class="agenda-meta">${escapeHtml(dueText)}</div></div><span class="tag life">${escapeHtml(listName)}</span></div>`}).join('');$('#agenda-list').insertAdjacentHTML('beforeend',rows)}
    async function syncTodo(states,cfg){const preferred=states.find(s=>s.entity_id===TODO_ENTITY);const listName=preferred?label(preferred):'MS365 To Do';const fromState=preferred?.attributes.all_todos||preferred?.attributes.items;if(Array.isArray(fromState)){renderTodos(fromState,listName);return}try{const result=await api('/api/services/todo/get_items?return_response',cfg,{method:'POST',body:JSON.stringify({entity_id:TODO_ENTITY,status:'needs_action'})});const data=result.service_response?.[TODO_ENTITY]||result[TODO_ENTITY]||{};renderTodos(data.items||[],listName)}catch(err){$('#agenda-source').textContent='MS365 To Do 未返回任务'}}
    async function syncCalendar(cfg){const calendars=await api('/api/calendars',cfg);if(!calendars.length)return;const start=new Date(),end=new Date();start.setHours(0,0,0,0);end.setHours(23,59,59,999);const lists=await Promise.all(calendars.slice(0,4).map(async cal=>({name:cal.name,events:await api('/api/calendars/'+encodeURIComponent(cal.entity_id)+'?start='+encodeURIComponent(start.toISOString())+'&end='+encodeURIComponent(end.toISOString()),cfg)})));const events=lists.flatMap(x=>x.events.map(e=>({...e,calendar:x.name}))).sort((a,b)=>new Date(a.start.dateTime||a.start.date)-new Date(b.start.dateTime||b.start.date)).slice(0,4);if(!events.length){$('#agenda-source').textContent='Home Assistant 日历';$('#agenda-list').innerHTML='<div class="agenda-row"><span class="agenda-time">今天</span><div><div class="agenda-title">今日暂无安排</div><div class="agenda-meta">来自 Home Assistant 日历</div></div><span class="tag">日历</span></div>';return}$('#agenda-source').textContent='Home Assistant 日历';$('#agenda-list').innerHTML=events.map(e=>{const allDay=!!e.start.date,time=allDay?'全天':new Date(e.start.dateTime).toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit',hour12:false});return `<div class="agenda-row"><span class="agenda-time">${time}</span><div><div class="agenda-title">${escapeHtml(e.summary)}</div><div class="agenda-meta">${escapeHtml(e.location||e.calendar)}</div></div><span class="tag">${escapeHtml(e.calendar)}</span></div>`}).join('')}
    async function sync(cfg){if(syncing)return;syncing=true;try{const states=await api('/api/states',cfg);latestStates=states;const temp=entity(states,'sensor',['temperature','温度']), humidity=entity(states,'sensor',['humidity','湿度']);if(value(temp))$('#temperature').textContent=value(temp);$('#environment').textContent=[humidity&&value(humidity)?'湿度 '+value(humidity)+(humidity.attributes.unit_of_measurement||'%'):'已连接 Home Assistant','刚刚同步'].filter(Boolean).join(' · ');$('#ha-status').textContent='实时同步';$('#connection-label').textContent='已连接 · 刚刚同步';$('#service-count').textContent=states.length+' 个实体已同步';const rooms=states.filter(s=>s.entity_id.startsWith('climate.')).slice(0,2);if(rooms.length)$('#room-list').innerHTML=rooms.map(s=>`<span>${label(s)} ${s.attributes.current_temperature??s.state}°</span>`).join('');renderQuick(states);renderDevices(states);await syncCalendar(cfg).catch(()=>{});await syncTodo(states,cfg) }finally{syncing=false;$('#app-loader').classList.add('hidden')}}
    function beginAutoSync(cfg){if(syncTimer)clearInterval(syncTimer);syncTimer=setInterval(()=>sync(cfg).catch(err=>{$('#ha-status').textContent='同步失败';$('#connection-label').textContent='连接需要检查'}),30000)}
    $('#connect-form').onsubmit=async e=>{e.preventDefault();const cfg={};try{notice('正在连接…');await sync(cfg);beginAutoSync(cfg);hideModal();notice('Home Assistant 已连接，并每 30 秒同步')}catch(err){notice(err.message)}};
    loadRuntimeConfig().then(authenticate).then(ok=>{if(!ok)return;const cfg={};sync(cfg).then(()=>beginAutoSync(cfg)).catch(()=>notice('Home Assistant 连接失败，请检查 config.js 与 CORS 设置'))}).catch(e=>{loginError(e.message);$('#app-loader').classList.add('hidden')});
    document.querySelectorAll('.quick-grid button').forEach(b=>b.addEventListener('click',()=>{if(sessionStorage.getItem('life-hub-oidc'))return;b.classList.toggle('on');b.querySelector('small').textContent=b.classList.contains('on')?'已开启':'已关闭'}));