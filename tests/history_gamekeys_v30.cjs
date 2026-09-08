// Native Vue KeepAlive/Suspense regression. Fixture data, not live account data.
const {chromium}=require('playwright');const fs=require('fs'),path=require('path'),assert=require('assert');
(async()=>{
 const browser=await chromium.launch();const page=await browser.newPage({viewport:{width:411,height:850}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',r=>r.fulfill(r.request().isNavigationRequest()?{contentType:'text/html',body:'<html><head></head><body><div id="app"></div></body></html>'}:{contentType:'application/json',body:'{"data":true}'}));
 await page.goto('https://fixture.invalid/WinGo/WinGo_30S');
 await page.addScriptTag({path:require.resolve('vue/dist/vue.global.prod.js')});
 await page.evaluate(compiled=>{
  const {createApp,h,ref,reactive,KeepAlive,Suspense,onMounted,onUnmounted}=Vue;
  window.state=reactive({gameCode:'WinGo_30S'});window.mounts=[];window.unmounts=[];window.betMounts=0;window.apiCalls=[];
  const prefix={WinGo_30S:'10005',WinGo_1M:'10001',WinGo_3M:'10002',WinGo_5M:'10003'};
  function historyType(kind){return {name:kind,setup(){
   const game=state.gameCode;mounts.push(kind+':'+game);const rows=ref([]),page=ref(1);
   function load(){const requested=page.value;apiCalls.push({kind,game,page:requested});setTimeout(()=>{rows.value=game==='WinGo_3M'?[]:Array.from({length:10},(_,i)=>'20260908'+prefix[game]+String(requested*100+i).padStart(4,'0'));},game==='WinGo_30S'?200:20);}
   onMounted(load);onUnmounted(()=>unmounts.push(kind+':'+game));
   return()=>h('section',{'data-kind':kind,'data-game':game},[h('span',{class:'page'},String(page.value)),h('button',{class:'next',onClick:()=>{page.value++;load();}},'Next'),h('div',{class:'rows'},rows.value.map(issue=>h('p',{class:'period'},issue)))]);
  }}}
  const Record=historyType('MyRecord'),Chart=historyType('Chart'),Bet={setup(){betMounts++;return()=>h('div',{id:'bet'},'Betting untouched')}};
  const view=ref('MyRecord');window.switchView=()=>{view.value=view.value==='MyRecord'?'Chart':'MyRecord';};
  if(compiled){
   createApp({name:'wingo3',components:{Bet},setup(){return {current:Vue.computed(()=>view.value==='MyRecord'?Record:Chart),switchView};},
    template:'<main><Bet/><div class="history"><button id="view" @click="switchView">Switch view</button><div class="nav-box"><KeepAlive><Suspense><component :is="current"/><template #fallback><p>Loading</p></template></Suspense></KeepAlive></div></div></main>'
   }).mount('#app');
  }else{
  createApp({name:'wingo3',setup(){return()=>h('main',[
    h(Bet),
    h('div',{class:'history'},[
      h('button',{id:'view',onClick:switchView},'Switch view'),
      h('div',{class:'nav-box'},[
        h(KeepAlive,null,[h(Suspense,null,{default:()=>h(view.value==='MyRecord'?Record:Chart),fallback:()=>h('p','Loading')})])
      ])
    ])
  ]);}}).mount('#app');
  }

 },process.env.COMPILED==='1');
 await page.addScriptTag({path:path.resolve(__dirname,'../.w13l/js/13l-hist25.js')});
 await page.waitForTimeout(250);
 await page.locator('.next').click();await page.waitForTimeout(250);assert.equal(await page.locator('.page').innerText(),'2');
 // Pending old 30S response must not overwrite the new game's native component.
 await page.locator('.next').click();
 await page.evaluate(()=>state.gameCode='WinGo_1M');await page.waitForTimeout(300);
 assert.equal(await page.locator('[data-kind]').getAttribute('data-game'),'WinGo_1M');assert.equal(await page.locator('.page').innerText(),'1');
 assert((await page.locator('.period').allTextContents()).every(x=>x.slice(8,13)==='10001'));
 await page.locator('#view').click();await page.waitForTimeout(60);await page.locator('.next').click();await page.waitForTimeout(60);
 await page.evaluate(()=>state.gameCode='WinGo_5M');await page.waitForTimeout(100);
 assert.equal(await page.locator('.page').innerText(),'1');assert((await page.locator('.period').allTextContents()).every(x=>x.slice(8,13)==='10003'));
 await page.evaluate(()=>state.gameCode='WinGo_3M');await page.waitForTimeout(100);assert.equal(await page.locator('.period').count(),0,'empty game must not inherit another game rows');
 await page.locator('#view').click();await page.waitForTimeout(80);assert.equal(await page.locator('.period').count(),0);
 await page.evaluate(()=>state.gameCode='WinGo_30S');await page.waitForTimeout(250);assert.equal(await page.locator('.page').innerText(),'1');
 assert((await page.locator('.period').allTextContents()).every(x=>x.slice(8,13)==='10005'));
 const result=await page.evaluate(()=>({mounts,unmounts,apiCalls,betMounts}));assert.equal(result.betMounts,1,'betting component must not remount');assert.deepEqual(errors,[]);
 console.log(JSON.stringify({pass:true,...result,errors},null,2));await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
