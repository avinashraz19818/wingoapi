// Exact native Wallet + native updateBalance closure. Provider replies are simulated; no real wallet requests.
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(__dirname,'../.w13l'),f=path.resolve(__dirname,'fixtures/ui-v39');
const svg=fs.readFileSync(f+'/dhani-refresh.svg','utf8'),oldSVG=fs.readFileSync(f+'/native-dark-refresh.svg','utf8'),js=fs.readFileSync(root+'/js/13l-hist25.js','utf8');
assert.equal(JSON.parse(js.match(/var refreshSVG39=(.*?),refreshNodes39=/)[1]),svg,'exact Dhani asset bytes');
const css=fs.readFileSync(path.resolve(__dirname,'fixtures/header-timer-v32/native-wallet.css'),'utf8')+fs.readFileSync(root+'/css/13l-patch25.css','utf8');
(async()=>{const browser=await chromium.launch(),results=[];
for(const width of [320,360,390,393,411,430]){
 const p=await browser.newPage({viewport:{width,height:720}}),errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.route('**/*',r=>r.fulfill(r.request().isNavigationRequest()?{contentType:'text/html',body:`<html><head><meta charset="utf-8"><style>${css}html{font-size:min(10vw,40px);--text_color_L1:#fff}body{margin:0;background:#282828;color:#fff;font:14px Arial}.ar_icon{display:inline-flex;width:1em;height:1em;vertical-align:middle;align-items:center}.ar_icon svg{width:100%;height:100%;color:currentColor}.caption{padding:20px;font-size:14px}</style></head><body><div id="app"></div></body></html>`}:{contentType:'application/json',body:'{}'}));
 await p.goto('https://fixture.invalid/WinGo/WinGo_1M');await p.addScriptTag({path:require.resolve('vue/dist/vue.global.prod.js')});
 await p.evaluate(old=>{
  Object.assign(window,{B:Vue.defineComponent,o:Vue.openBlock,_:Vue.createElementBlock,e:Vue.createElementVNode,n:Vue.toDisplayString,d:Vue.unref,C:Vue.createVNode});
  window.N=(component,props)=>{for(const [key,value] of props)component[key]=value;return component;};
  window.state=Vue.reactive({gameCode:'WinGo_1M',balance:1000});window.busy39=Vue.ref(false);window.calls39=0;window.routes39=[];
  window.mockBalance39=()=>{calls39++;return new Promise((resolve,reject)=>{window.resolve39=resolve;window.reject39=reject;});};
  window.V=value=>new Intl.NumberFormat('en-IN',{style:'currency',currency:'INR'}).format(value);
  window.L=()=>({push:dest=>routes39.push(dest.name)});
  window.F=Vue.defineComponent({inheritAttrs:false,props:['name','iconClass'],setup(props,{attrs}){const ready=Vue.ref(false);setTimeout(()=>ready.value=true,100);return()=>ready.value?Vue.h('span',{class:['ar_icon',props.iconClass],...attrs,innerHTML:props.name==='icon_refresh'?old:'<svg viewBox="0 0 24 24"><path fill="#ff6470" d="M1 5h22v15H1z"/></svg>','aria-hidden':'true'}):null;}});
 },oldSVG);
 await p.addScriptTag({content:'(function(){const w=state,r=busy39,L=v=>w.balance=v,we=mockBalance39;'+fs.readFileSync(f+'/native-updateBalance.js','utf8')+'})();'});
 await p.evaluate(()=>window.U=()=>({balance:Vue.computed(()=>state.balance),updateBalance:updateBalance39}));
 await p.addScriptTag({path:f+'/native-wallet.js'});
 await p.evaluate(()=>{window.app39=Vue.createApp({setup(){return()=>Vue.h('main',[Vue.h('div',{class:'caption'},'Native wallet fixture'),Vue.h(Wallet39)]);}});app39.config.globalProperties.$t=key=>({'common.walletBalance':'Wallet balance','common.withdraw':'Withdraw','common.recharge':'Recharge'}[key]||key);app39.mount('#app');});
 await p.addScriptTag({path:root+'/js/13l-hist25.js'});
 const button=p.getByRole('button',{name:'Refresh wallet balance'});await button.waitFor();await p.evaluate(()=>document.fonts.ready);
 assert.equal(await button.count(),1);assert.equal(await p.locator('.Wallet__C-balance-l3 > :nth-child(2)').innerText(),'Deposit');assert.equal(await p.evaluate(()=>calls39),0,'decoration does not initiate a wallet refresh');
 const paths=await button.locator('path').evaluateAll(es=>es.map(e=>({d:e.getAttribute('d'),stroke:e.getAttribute('stroke'),paint:getComputedStyle(e).stroke})));assert(paths.every(x=>x.stroke==='var(--text_color_L1)'&&x.paint==='rgb(255, 255, 255)'));
 await button.evaluate(el=>window.originalHost39=el);
 await button.click();await button.click();await button.press('Enter');assert.equal(await p.evaluate(()=>calls39),1,'native in-flight guard suppresses repeated taps');assert.equal(await p.evaluate(()=>busy39.value),true);assert.equal(await p.locator('.Wallet__C-balance-l1 > span:first-child').innerText(),'₹1,000.00');
 await p.evaluate(()=>resolve39({result:true,data:{balance:1250.5},serviceTime:123}));await p.waitForFunction(()=>!busy39.value);assert.equal(await p.locator('.Wallet__C-balance-l1 > span:first-child').innerText(),'₹1,250.50');assert(await button.evaluate(el=>el===originalHost39),'native event host unchanged');
 await button.press('Space');assert.equal(await p.evaluate(()=>calls39),2);await p.evaluate(()=>resolve39({result:false,data:{balance:999999},serviceTime:0}));await p.waitForFunction(()=>!busy39.value);assert.equal(await p.evaluate(()=>state.balance),1250.5,'unsuccessful response cannot overwrite balance');
 await button.click();await p.evaluate(()=>reject39(new Error('simulated provider failure')));await p.waitForFunction(()=>!busy39.value);assert.equal(await p.evaluate(()=>state.balance),1250.5,'failed request preserves prior balance and releases guard');
 await button.click();await p.evaluate(()=>resolve39({result:true,data:{balance:0},serviceTime:124}));await p.waitForFunction(()=>!busy39.value);assert.equal(await p.locator('.Wallet__C-balance-l1 > span:first-child').innerText(),'₹0.00','valid zero is not a fake loading value');
 // Later native SVG DOM replacement is repaired without replacing its click host.
 await button.evaluate((el,old)=>el.innerHTML=old,oldSVG);await p.waitForFunction(()=>document.querySelector('.refresh path').getAttribute('stroke')==='var(--text_color_L1)');assert.equal(await button.count(),1);
 await button.click();await p.evaluate(()=>resolve39({result:true,data:{balance:1234.5},serviceTime:125}));await p.waitForFunction(()=>!busy39.value);assert.equal(await p.evaluate(()=>calls39),5);
 const bounds=await p.locator('.Wallet__C-balance-l1 > span').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return{left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width};}));assert(bounds[0].right<bounds[1].left);assert(bounds.every(r=>r.left>=0&&r.right<=width));assert(Math.abs(bounds[1].width-Math.min(width*.1,40)*.48)<1,'native/Dhani1em icon sizing retained');
 await p.locator('.Wallet__C-balance-l3 > :first-child').click();await p.locator('.Wallet__C-balance-l3 > :nth-child(2)').click();assert.deepEqual(await p.evaluate(()=>routes39),['withdraw','recharge']);
 await p.screenshot({path:'/home/user/inspection/ui39/wallet-'+width+'.png'});
 await p.evaluate(()=>history.pushState({},'','/K3/K3_1M'));assert.equal(await p.locator('[data-13l-refresh39]').count(),0);assert.equal(await p.locator('.refresh').getAttribute('aria-hidden'),'true');assert.equal(await p.locator('.refresh path').first().getAttribute('stroke'),'#383A4C');assert.equal(await p.locator('.Wallet__C-balance-l3 > :nth-child(2)').innerText(),'Recharge');
 await p.evaluate(()=>history.pushState({},'','/WinGo/WinGo_1M'));await button.waitFor();await button.click();assert.equal(await p.evaluate(()=>calls39),6,'re-entry retains exactly one native refresh call');await p.evaluate(()=>resolve39({result:true,data:{balance:1234.5},serviceTime:126}));await p.waitForFunction(()=>!busy39.value);assert.deepEqual(errors,[]);
 results.push({width,exactDhaniSVG:true,visibleThemeStroke:true,nativeHostPreserved:true,nativeBalanceLogic:true,concurrentCallsGuarded:true,failedRefreshPreservesBalance:true,validZero:true,keyboardWorks:true,withdrawDepositUnchanged:true,noOverlap:true,cleanupAndReentry:true,errors});await p.close();
}
await browser.close();fs.writeFileSync(f+'/results.json',JSON.stringify(results,null,2)+'\n');console.log(JSON.stringify(results,null,2));})().catch(e=>{console.error(e);process.exit(1)});
