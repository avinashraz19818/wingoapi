// Uses the extracted native Winning component; no bets or settlement requests.
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(__dirname,'../.w13l'),f=path.resolve(__dirname,'fixtures/ui-v38');
const art=JSON.parse(fs.readFileSync(root+'/tools/13l-art38.json','utf8')),bytes=Buffer.from(art.base64,'base64');
const css=fs.readFileSync(f+'/native-winning.css','utf8')+'\n'+fs.readFileSync(root+'/css/13l-patch25.css','utf8');
(async()=>{const browser=await chromium.launch(),results=[];
for(const width of [320,360,390,393,411,430]){
 const p=await browser.newPage({viewport:{width,height:720}}),errors=[],requests=[];p.on('pageerror',e=>errors.push(e.message));
 await p.route('**/*',r=>{const u=new URL(r.request().url());requests.push(u.pathname+u.search);
  if(r.request().isNavigationRequest())return r.fulfill({contentType:'text/html',body:`<html><head><meta charset="utf-8"><style>html{font-size:min(10vw,40px);--text_color_L2:#777;--winTips:transparent}body{margin:0;background:#282828;color:#fff}*{box-sizing:border-box}p{margin:0}.underlying{padding:30px;min-height:1300px}.result38{display:flex;gap:7px;align-items:center;justify-content:center;flex-wrap:wrap}.result38 b{padding:5px 8px;background:#20ac75;border-radius:5px} ${css}</style></head><body><div id="app"></div></body></html>`});
  if(u.pathname==='/images/missningBg-CVBJxzJu.webp')return r.fulfill({contentType:'image/webp',body:bytes});
  if(['/images/missningLBg-CJY3-D31.webp','/images/close-BQWAMfYu.webp'].includes(u.pathname))return r.fulfill({contentType:'image/webp',body:fs.readFileSync(f+'/'+path.basename(u.pathname))});
  return r.fulfill({contentType:'application/json',body:'{}'});
 });
 await p.goto('https://fixture.invalid/WinGo/WinGo_30S');await p.addScriptTag({path:require.resolve('vue/dist/vue.global.prod.js')});
 await p.evaluate(()=>{
  Object.assign(window,{B:Vue.defineComponent,h:Vue.ref,se:Vue.reactive,o:Vue.openBlock,T:Vue.createBlock,ae:Vue.Transition,J:Vue.withCtx,j:Vue.withDirectives,e:Vue.createElementVNode,W:Vue.normalizeClass,n:Vue.toDisplayString,_:Vue.createElementBlock,te:Vue.Fragment,q:Vue.renderSlot,O:Vue.createCommentVNode,d:Vue.unref,D:Vue.createTextVNode,S:Vue.withModifiers,E:Vue.vShow});
  window.N=(component,props)=>{for(const [key,value] of props)component[key]=value;return component;};
  window.V=value=>new Intl.NumberFormat('en-IN',{style:'currency',currency:'INR'}).format(value);
  window.le=()=>({currentGame:Vue.ref({gameName:'WinGo 30sec'})});window.ce=()=>({soundEffects:Vue.ref(false)});
  window.G={Howl:class{load(){}play(){}}};window.ke='fixture-win.wav';window.$e='fixture-lose.wav';window.be='fixture-confetti';window.xe='fixture-effects';
  window.__vite__mapDeps=()=>[];window.ne=fn=>fn;window.oe=()=>Promise.resolve({loadAnimation:()=>({play(){},stop(){}})});
 });
 await p.addScriptTag({path:f+'/native-winning-component.js'});
 await p.evaluate(()=>{
  const app=Vue.createApp({setup(){window.dialog38=Vue.ref();return()=>Vue.h('main',[Vue.h('div',{class:'underlying'},'Native game underneath — fixture only'),Vue.h(Winning38,{ref:dialog38},{default:({data})=>Vue.h('div',{class:'result38'},[Vue.h('span','Lottery results'),Vue.h('b',data.color),Vue.h('b',String(data.number)),Vue.h('b',data.size)])})]);}});
  app.config.globalProperties.$t=key=>({'common.winTips':'Congratulations','common.loseTips':'Better luck next time','common.bonus':'Bonus','common.issue':'Period','common.autoClose':'3 seconds auto close'}[key]||key);app.mount('#app');
  window.payload38={isWin:true,issueNumber:'20260908100051606',amount:1960,result:{color:'Green',number:3,size:'Small'}};
 });
 await p.addScriptTag({path:root+'/js/13l-hist25.js'});
 // Warm same-origin image before popup opens; verify actual browser decode dimensions.
 const loaded=await p.evaluate(()=>new Promise((resolve,reject)=>{const img=new Image();img.onload=()=>resolve([img.naturalWidth,img.naturalHeight]);img.onerror=reject;img.src='/images/missningBg-CVBJxzJu.webp?art=38';}));assert.deepEqual(loaded,[1186,1624]);
 assert(requests.includes('/images/missningBg-CVBJxzJu.webp?art=38'));assert.equal(await p.locator('.winning').isVisible(),false);
 await p.clock.install();await p.clock.pauseAt(new Date());await p.evaluate(()=>dialog38.value.open(payload38));await p.clock.runFor(600);
 assert.equal(await p.locator('.winning').isVisible(),true);const bg=await p.locator('.winning-body').evaluate(e=>getComputedStyle(e).backgroundImage);assert(bg.includes('missningBg-CVBJxzJu.webp?art=38'));
 assert.equal(await p.locator('.bonus').innerText(),'₹1,960.00');assert.equal(await p.locator('.gameDetail p').innerText(),'20260908100051606');assert((await p.locator('.result38').innerText()).includes('Green'));
 const bounds=await p.locator('.winning-body,.closeBtn').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return{left:r.left,right:r.right,top:r.top,bottom:r.bottom};}));assert(bounds.every(r=>r.left>=0&&r.right<=width&&r.top>=0&&r.bottom<=720));
 await p.screenshot({path:'/home/user/inspection/ui38/winning-'+width+'.png'});
 // Existing exposed component timer/close controls remain native.
 await p.clock.runFor(2300);assert.equal(await p.locator('.winning').isVisible(),true);await p.clock.runFor(200);assert.equal(await p.locator('.winning').isVisible(),false);
 await p.evaluate(()=>dialog38.value.open(payload38));await p.clock.runFor(100);await p.locator('.acitveBtn').click();await p.clock.runFor(3500);assert.equal(await p.locator('.winning').isVisible(),true);await p.locator('.closeBtn').click();await p.clock.runFor(100);assert.equal(await p.locator('.winning').isVisible(),false);
 await p.evaluate(()=>dialog38.value.open({...payload38,isWin:false,amount:0}));await p.clock.runFor(100);const lose=await p.locator('.winning-body').evaluate(e=>getComputedStyle(e).backgroundImage);assert(lose.includes('missningLBg-CJY3-D31.webp'));assert(!lose.includes('art=38'));
 await p.locator('.closeBtn').click();await p.evaluate(()=>history.pushState({},'','/K3/K3_1M'));await p.evaluate(()=>dialog38.value.open(payload38));await p.clock.runFor(100);assert(!(await p.locator('.winning-body').evaluate(e=>getComputedStyle(e).backgroundImage)).includes('art=38'));
 assert.deepEqual(await p.evaluate(()=>payload38),{isWin:true,issueNumber:'20260908100051606',amount:1960,result:{color:'Green',number:3,size:'Small'}});assert.deepEqual(errors,[]);
 results.push({width,imageDecoded:loaded,nativeAmountAndPeriodUnchanged:true,autoClose3000ms:true,autoCloseToggle:true,manualClose:true,loseUnchanged:true,routeScoped:true,noClipping:true,errors});await p.close();
}
await browser.close();fs.writeFileSync(f+'/results.json',JSON.stringify(results,null,2)+'\n');console.log(JSON.stringify(results,null,2));})().catch(e=>{console.error(e);process.exit(1)});
