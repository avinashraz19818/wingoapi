// UI fixture with native-shaped header/wallet and production Vue provided sound ref.
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(__dirname,'../.w13l'),nativeCSS=fs.readFileSync(path.resolve(__dirname,'fixtures/header-timer-v32/native-wallet.css'),'utf8');
const patch=fs.readFileSync(root+'/css/13l-patch25.css','utf8');
(async()=>{const b=await chromium.launch(),results=[];
for(const width of [320,360,390,393,411,430]){
 const p=await b.newPage({viewport:{width,height:720}}),errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.route('**/*',r=>r.fulfill(r.request().isNavigationRequest()?{contentType:'text/html',body:`<html><head><meta charset="utf-8"><style>${nativeCSS}${patch}html{font-size:min(10vw,40px)}body{margin:0;background:#282828;color:#fff;font:14px Arial}.game-header{height:1.44rem;display:flex;align-items:center;justify-content:space-between;padding:0 .4rem}.head-left{position:relative;display:flex;align-items:center}.logo{height:.66667rem}.head-right{background:#333;display:flex}.history{min-height:800px}</style></head><body><div id="app"></div></body></html>`}:{contentType:'application/json',body:'{}'}));
 await p.goto('https://fixture.invalid/WinGo/WinGo_1M');await p.addScriptTag({path:require.resolve('vue/dist/vue.global.prod.js')});
 await p.evaluate(()=>{
  const {h,ref,computed,provide,createApp,reactive}=Vue;window.state=reactive({gameCode:'WinGo_1M'});window.actions=[];window.audio=ref(localStorage.getItem('fixture-native-sound')==='1');window.balance=ref('90,356.00');
  const ctx={soundEffects:computed({get:()=>audio.value,set:v=>{audio.value=v;localStorage.setItem('fixture-native-sound',v?'1':'0');actions.push('sound:'+v);}}),gameCode:computed(()=>state.gameCode),historyIssues:ref([])};
  const logo='data:image/svg+xml,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="240" height="90"><text x="10" y="66" fill="#ffbd18" stroke="#f33" stroke-width="1.5" font-size="66" font-family="Arial" font-weight="900">13L</text><text x="133" y="60" fill="white" font-family="Arial" font-size="23" font-weight="700">GAME</text></svg>');
  const app=createApp({setup(){provide(Symbol('AR_LOTTERY'),ctx);return()=>h('main',[
   h('div',{class:'game-header'},[h('div',{class:'head-left'},[h('button',{id:'back',onClick:()=>actions.push('back'),style:'color:white;background:none;border:0;font-size:24px'},'‹'),h('img',{class:'logo',src:logo})]),h('div',{class:'head-right',onClick:()=>actions.push('header recharge')},[h('span',{class:'coin'},'₹'),h('span',{class:'deposit'},'Recharge')])]),
   h('div',{class:'lottery-info','data-v-015dc728':''},[h('div',{class:'bg','data-v-015dc728':''}),h('div',{class:'Wallet__C','data-v-920597dd':''},[h('div',{class:'Wallet__C-balance','data-v-920597dd':''},[h('div',{class:'Wallet__C-balance-l1','data-v-920597dd':''},'₹'+balance.value),h('div',{class:'Wallet__C-balance-l2','data-v-920597dd':''},'Wallet balance'),h('div',{class:'Wallet__C-balance-l3','data-v-920597dd':''},[h('div',{'data-v-920597dd':'',onClick:()=>actions.push('withdraw')},'Withdraw'),h('div',{'data-v-920597dd':'',onClick:()=>actions.push('recharge')},'Recharge')])])])]),
   h('div',{class:'history'},[h('div',{class:'TimeLeft__C'},'Native timer unchanged')])
  ]);}});app.config.globalProperties.$router={hasRoute:n=>n==='workOrder',push:async dest=>{actions.push('route:'+dest.name);}};app.mount('#app');window.app=app;
 });
 await p.addScriptTag({path:root+'/js/13l-hist25.js'});await p.waitForTimeout(1100);
 const deposit=p.locator('.Wallet__C-balance-l3 > :nth-child(2)'),voice=p.locator('.header-voice35');assert.equal(await deposit.innerText(),'Deposit');assert.equal(await p.locator('[data-13l-controls35]').count(),1);assert.equal(await voice.isEnabled(),true);
 assert.equal(await voice.getAttribute('aria-pressed'),'false');await voice.click();assert.equal(await voice.getAttribute('aria-pressed'),'true');assert.equal(await p.evaluate(()=>audio.value),true);assert.equal(await p.evaluate(()=>localStorage.getItem('fixture-native-sound')),'1');
 await voice.click();assert.equal(await p.evaluate(()=>audio.value),false);assert.equal(await voice.getAttribute('aria-label'),'Turn sound on');
 await p.locator('.header-service35').click();await deposit.click();await p.locator('#back').click();
 assert.deepEqual(await p.evaluate(()=>actions),['sound:true','sound:false','route:workOrder','recharge','back']);
 await p.evaluate(()=>balance.value='90,357.00');await p.waitForTimeout(1000);assert.equal(await deposit.innerText(),'Deposit','label survives native balance re-render');assert.equal(await p.locator('[data-13l-controls35]').count(),1,'no duplicate controls after re-render');
 const geometry=await p.evaluate(()=>{const logo=document.querySelector('.logo').getBoundingClientRect(),buttons=[...document.querySelectorAll('.header-controls35 button')].map(e=>e.getBoundingClientRect());return{logo:{left:logo.left,right:logo.right,width:logo.width,height:logo.height},buttons:buttons.map(r=>({left:r.left,right:r.right,top:r.top,bottom:r.bottom})),root:parseFloat(getComputedStyle(document.documentElement).fontSize)};});
 assert(Math.abs(geometry.logo.width-geometry.root*2.13333)<1);assert(geometry.logo.right<geometry.buttons[0].left);assert(geometry.buttons[1].right<=width);assert(geometry.buttons[0].right<=geometry.buttons[1].left);assert.equal(await p.locator('.game-header > .head-right').evaluate(e=>getComputedStyle(e).display),'none');
 await p.screenshot({path:'/home/user/inspection/header35/fixture-'+width+'.png'});
 await p.evaluate(()=>history.pushState({},'','/K3/K3_1M'));assert.equal(await p.locator('[data-13l-controls35]').count(),0);assert.equal(await deposit.innerText(),'Recharge');assert.notEqual(await p.locator('.game-header > .head-right').evaluate(e=>getComputedStyle(e).display),'none');
 await p.evaluate(()=>history.pushState({},'','/WinGo/WinGo_1M'));await p.waitForTimeout(100);assert.equal(await p.locator('[data-13l-controls35]').count(),1);assert.equal(await deposit.innerText(),'Deposit');assert.deepEqual(errors,[]);
 results.push({width,logo:geometry.logo,icons:2,soundUsesNativeRef:true,supportRoute:'workOrder',depositKeepsRechargeAction:true,noOverlap:true,routeCleanup:true,errors});await p.close();
}
await b.close();fs.mkdirSync(path.resolve(__dirname,'fixtures/header-v35'),{recursive:true});fs.writeFileSync(path.resolve(__dirname,'fixtures/header-v35/results.json'),JSON.stringify(results,null,2)+'\n');console.log(JSON.stringify(results,null,2));})().catch(e=>{console.error(e);process.exit(1)});
