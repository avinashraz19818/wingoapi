
(function(){
  'use strict';
  var settingsCache=null, lastSettingsAt=0;
  function safe(s){return String(s||'').replace(/[<>&]/g,function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]});}
  function toast(msg){
    var t=document.createElement('div'); t.textContent=msg; t.style.cssText='position:fixed;left:50%;top:18px;transform:translateX(-50%);z-index:2147483647;background:#111827;color:#fff;border:1px solid #334155;border-radius:14px;padding:10px 16px;font:600 13px Arial;box-shadow:0 12px 40px #0008'; document.body.appendChild(t); setTimeout(function(){t.remove()},2200);
  }
  function lock(id,title,msg){
    if(document.getElementById(id)) return;
    var d=document.createElement('div'); d.id=id; d.innerHTML='<div class="v26-card"><div class="v26-logo">13L</div><h2>'+safe(title)+'</h2><p>'+safe(msg)+'</p><button onclick="location.reload()">Refresh</button></div>';
    var st=document.createElement('style'); st.textContent='#'+id+'{position:fixed;inset:0;z-index:2147483647;background:radial-gradient(circle at 20% 0,#ef444455,transparent 28%),rgba(2,6,23,.97);display:grid;place-items:center;color:#fff;font-family:Inter,Arial}.v26-card{width:min(390px,92vw);background:linear-gradient(180deg,#111827,#060913);border:1px solid #334155;border-radius:24px;padding:24px;text-align:center;box-shadow:0 35px 100px #000}.v26-logo{width:58px;height:58px;margin:0 auto 12px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,#ef4444,#f97316);font-weight:900}.v26-card h2{margin:8px 0;font-size:24px}.v26-card p{color:#cbd5e1;line-height:1.55}.v26-card button{width:100%;border:0;border-radius:14px;background:linear-gradient(135deg,#ef4444,#f97316);color:#fff;font-weight:900;padding:13px}';
    document.head.appendChild(st); document.body.appendChild(d);
  }
  function fetchSettings(){
    if(settingsCache && Date.now()-lastSettingsAt<7000) return Promise.resolve(settingsCache);
    return fetch('/api/Site/GetSettings?ts='+Date.now(),{cache:'no-store',credentials:'include'}).then(function(r){return r.json()}).then(function(j){settingsCache=(j&&j.data)||{}; lastSettingsAt=Date.now(); return settingsCache;}).catch(function(){return settingsCache||{};});
  }
  function enforceSettings(){
    fetchSettings().then(function(d){
      var p=location.pathname.toLowerCase(); var f=d.features||{};
      if(d.maintenanceEnabled && !/login|register|license|admin/i.test(p)) lock('v26-maint-lock','Maintenance',d.maintenanceText||'Site is under maintenance');
      var rules=[['recharge_enabled',/wallet\/recharge|recharge|arupi/i,'Recharge is closed by admin'],['withdraw_enabled',/withdraw/i,'Withdraw is closed by admin'],['workorder_enabled',/workorder|service/i,'Support is closed by admin'],['gift_enabled',/gift|coupon/i,'Gift is closed by admin'],['vip_enabled',/vip/i,'VIP is closed by admin'],['agent_enabled',/earn|agent|invite/i,'Invite/agent is closed by admin'],['lottery_enabled',/wingo|k3|5d|moto|trx/i,'Lottery is closed by admin']];
      rules.forEach(function(r){ if(f && f[r[0]]===false && r[1].test(p)) lock('v26-feature-lock', 'Feature disabled', r[2]); });
    });
  }
  function limitHistory(){
    if(!/wingo|k3|5d|moto|trx/i.test(location.pathname)) return;
    var holders=[].slice.call(document.querySelectorAll('table,[class*=record],[class*=history]'));
    holders.forEach(function(h){
      var rows=[].slice.call(h.querySelectorAll('tr,li,[class*=row],[class*=item]')).filter(function(el){var tx=(el.textContent||'').trim(); return /20\d{8,}/.test(tx) || /\b(Big|Small)\b/i.test(tx);});
      if(rows.length>10) rows.forEach(function(r,i){ if(i>=10) r.style.display='none'; });
    });
  }
  function fixCopy(){
    document.addEventListener('click',function(e){ var el=e.target&&e.target.closest?e.target.closest('[class*=copy],button,span,div'):null; if(!el) return; var text=(el.getAttribute('data-clipboard-text')||el.dataset&&el.dataset.clipboardText||'').trim(); if(!text && /copy/i.test(el.textContent||'')){ var prev=el.previousElementSibling; if(prev) text=(prev.textContent||'').trim(); } if(text && navigator.clipboard){navigator.clipboard.writeText(text).then(function(){toast('copy successful')}).catch(function(){});} },true);
  }
  function patchBrokenCustomImages(){
    if(!/rechargeturntable/i.test(location.pathname)) return;
    document.querySelectorAll('img').forEach(function(img){
      var src=img.getAttribute('src')||'';
      if(/treasure_chest-|\/images\/treasure_chest/i.test(src)){ img.removeAttribute('src'); img.style.display='none'; }
    });
  }
  window.addEventListener('error', function(ev){ if(ev.target && ev.target.tagName==='IMG'){ var s=ev.target.getAttribute('src')||''; if(/treasure_chest|gold_icon|silver_icon|diamond_icon|special_icon/i.test(s)){ ev.target.style.display='none'; } } }, true);
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){enforceSettings();limitHistory();fixCopy();patchBrokenCustomImages();}); else {enforceSettings();limitHistory();fixCopy();patchBrokenCustomImages();}
  setInterval(enforceSettings,10000); setInterval(limitHistory,1000); setInterval(patchBrokenCustomImages,1000);
})();


// V27: stronger client-side polish for support forms and history page only.
(function(){
  function hideBadInjectedImages(){
    if(!/rechargeturntable|workorder|selfservice/i.test(location.pathname)) return;
    document.querySelectorAll('img').forEach(function(img){
      var s=(img.getAttribute('src')||'').toLowerCase();
      if(/treasure_chest|silver-379a48c|gold_icon-da43809|silver_icon-9b2d2695|diamond_icon-47ffce1e|special_icon/i.test(s)){
        img.removeAttribute('src'); img.style.visibility='hidden'; img.style.maxHeight='0';
      }
    });
  }
  function forceGameHistoryPage10(){
    if(!/wingo|k3|5d|moto|trx/i.test(location.pathname)) return;
    var rows=[].slice.call(document.querySelectorAll('tbody tr, .record-body .record-row, [class*=record] [class*=item], [class*=history] [class*=item]'))
      .filter(function(el){var t=(el.textContent||'').trim(); return /20\d{8,}/.test(t) && !/Game history|Chart|My history/i.test(t);});
    rows.forEach(function(el,i){ if(i>=10) el.style.display='none'; });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){hideBadInjectedImages();forceGameHistoryPage10();}); else {hideBadInjectedImages();forceGameHistoryPage10();}
  setInterval(hideBadInjectedImages,1000); setInterval(forceGameHistoryPage10,1200);
})();

/* ===== 13L-JSINJECT-v16 ===== */
(function(){
  try{
    if(sessionStorage.getItem('13l_jrcleared')) return;
    var doomed=[], i, k, v;
    for(i=0;i<localStorage.length;i++){
      k=localStorage.key(i); if(!k) continue;
      var lk=k.toLowerCase();
      if(lk.indexOf('token')>-1||lk.indexOf('login')>-1||lk.indexOf('auth')>-1||lk.indexOf('user')>-1) continue;
      v=''; try{ v=localStorage.getItem(k)||''; }catch(e){}
      if(/allgames|gamehistory|myhistory|gamerecord|betlist|betrecord|recordlist|gamehistorylist/.test(lk)
         || (v.length>1 && (v.indexOf('allGames')>-1 || v.indexOf('gameList')>-1 || v.indexOf('mayrecord')>-1))) doomed.push(k);
    }
    for(i=0;i<doomed.length;i++){ try{ localStorage.removeItem(doomed[i]); }catch(e){} }
    // vuex-persist style single store blob se sirf nested cache keys hatao
    for(i=0;i<localStorage.length;i++){
      k=localStorage.key(i); if(!k) continue;
      v=''; try{ v=localStorage.getItem(k)||''; }catch(e){}
      if(v && v.charAt(0)==='{'){
        try{
          var o=JSON.parse(v), hit=false, walk=function(obj,depth){
            if(!obj||typeof obj!=='object'||depth>4) return;
            ['allGames','gameList','mayrecord','myHistoryList','historyList'].forEach(function(key){
              if(Object.prototype.hasOwnProperty.call(obj,key)){ delete obj[key]; hit=true; }
            });
            for(var p in obj){ if(obj[p]&&typeof obj[p]==='object') walk(obj[p],depth+1); }
          };
          walk(o,0);
          if(hit) localStorage.setItem(k, JSON.stringify(o));
        }catch(e){}
      }
    }
    sessionStorage.setItem('13l_jrcleared','1');
    location.reload();
  }catch(err){ try{console.warn('13l-jrclear',err);}catch(e){} }
})();
/* ===== /13L-JSINJECT-v16 ===== */
/* ===== 13L-UNCLIP-v17: history list un-clip watchdog ===== */
(function(){
  function walkFix(){
    try{
      if(document.hidden) return;
      var lists=document.querySelectorAll('[class*="my_r-body"],[class*="MyGameRecord__C-body"]');
      for(var i=0;i<lists.length;i++){
        var el=lists[i];
        if(!el || !el.children.length) continue;
        var need=el.scrollHeight-el.clientHeight;
        if(need>4){ // list itself clips (fixed height) -> open it
          el.style.setProperty('height','auto','important');
          el.style.setProperty('max-height','none','important');
          el.style.setProperty('overflow','visible','important');
        }
        var p=el.parentElement,n=0;
        while(p && p!==document.body && n<7){
          var cs=window.getComputedStyle(p);
          var oy=cs.overflowY, ov=cs.overflow;
          var clips=(oy==='hidden'||oy==='auto'||ov==='hidden');
          if(clips && p.clientHeight < el.scrollHeight-8 && p.getAttribute('data-13l-un')!=='1'){
            p.setAttribute('data-13l-un','1');
            p.style.setProperty('overflow','visible','important');
            p.style.setProperty('overflow-y','visible','important');
            p.style.setProperty('height','auto','important');
            p.style.setProperty('max-height','none','important');
          } else if(p.getAttribute('data-13l-un')==='1' && p.clientHeight < el.scrollHeight-8){
            // skin ne doobaar height thoonsi — dobara kholo
            p.style.setProperty('overflow','visible','important');
            p.style.setProperty('overflow-y','visible','important');
            p.style.setProperty('height','auto','important');
            p.style.setProperty('max-height','none','important');
          }
          p=p.parentElement; n++;
        }
      }
    }catch(e){}
  }
  setInterval(walkFix,400);
  document.addEventListener('click',function(){setTimeout(walkFix,80);setTimeout(walkFix,500);},true);
  window.addEventListener('resize',function(){setTimeout(walkFix,120);},true);
  walkFix();
})();
/* ===== /13L-UNCLIP-v17 ===== */