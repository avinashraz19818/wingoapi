// Real native composable, patched by the shipping manifest; simulated provider data.
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('assert'),crypto=require('crypto');
const root=path.resolve(__dirname,'../.w13l'),fixture=path.resolve(__dirname,'fixtures/native-v34');
let source=fs.readFileSync(fixture+'/dragon-original.js','utf8');const manifest=JSON.parse(fs.readFileSync(root+'/tools/13l-native34.json','utf8'));
const hash=s=>crypto.createHash('sha256').update(s).digest('hex');assert.equal(hash(source),manifest.before_sha256);
for(const pair of manifest.replacements){assert.equal(source.split(pair.old).length,2);source=source.replace(pair.old,pair.new);}assert.equal(hash(source),manifest.after_sha256);
source=source.slice(source.indexOf('const Ye='),source.indexOf('const je='));
(async()=>{const browser=await chromium.launch();const p=await browser.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
await p.route('**/*',r=>r.fulfill(r.request().isNavigationRequest()?{contentType:'text/html',body:'<html><head></head><body><div id="app"></div></body></html>'}:{contentType:'application/json',body:'{"data":true}'}));
await p.goto('https://fixture.invalid/WinGo/'+(process.env.LIVE_GAME||'WinGo_1M'));if(!process.env.LIVE_GAME)await p.clock.install({time:new Date('2026-09-08T10:00:00Z')});
if(process.env.LIVE_GAME){
 const get=async(url)=>{const r=await p.request.get('https://13l.club9.eu.cc/'+url,{timeout:10000});assert(r.ok());const j=await r.json();return {...j,result:j.code===0};};
 await p.exposeFunction('liveIssue34',args=>get('webapi/kv/issue/'+encodeURIComponent(args.gameCode)+'?verify=round34-'+Date.now()));
 await p.exposeFunction('liveHistory34',args=>get('api/_router.php?path=Lottery/GetHistoryIssuePage&gameCode='+encodeURIComponent(args.gameCode)+'&pageNo=1&pageSize=10&verify=round34-'+Date.now()));
}
await p.addScriptTag({path:require.resolve('vue/dist/vue.global.prod.js')});await p.addScriptTag({path:root+'/js/13l-hist25.js'});
await p.evaluate(nativeSource=>{
 const {reactive,ref,computed,watch,onMounted,onUnmounted,createApp,h}=Vue;
 window.state=reactive({gameCode:location.pathname.split('/').pop()});window.vis=ref('visible');Object.defineProperty(document,'hidden',{configurable:true,get:()=>vis.value==='hidden'});
 window.events=[];window.calls=[];window.sim={base:Date.now(),period:3000,publishDelay:1000,failIssue:0,failHistory:0,issueDelay:0,historyDelay:0,staleIssue:false,staticCalls:0};
 const prefix={WinGo_1M:'10001',WinGo_30S:'10005',WinGo_3M:'10002',WinGo_5M:'10003'};
 const issue=(g,i)=>'20260908'+prefix[g]+String(600+i).padStart(4,'0');
 const slot=()=>Math.floor((Date.now()-sim.base)/sim.period);
 const sleep=ms=>new Promise(r=>setTimeout(r,ms));
 const apiIssue=async({gameCode:g})=>{calls.push({type:'issue',game:g,at:Date.now()});const i=slot(),end=sim.base+(i+1)*sim.period,server=Date.now();if(sim.issueDelay)await sleep(sim.issueDelay);if(sim.failIssue>0){sim.failIssue--;throw Error('temporary issue network failure');}return {result:true,serverTime:server,data:{gameCode:g,issueNumber:issue(g,sim.staleIssue?i-1:i),countdown:Math.ceil((end-server)/1000),endTime:end,intervalMinute:3}};};
 const apiHistory=async({gameCode:g})=>{calls.push({type:'history',game:g,at:Date.now()});const i=slot(),latest=Math.floor((Date.now()-sim.base-sim.publishDelay)/sim.period)-1;if(sim.historyDelay)await sleep(sim.historyDelay);if(sim.failHistory>0){sim.failHistory--;throw Error('provider history pending');}return {result:true,data:{list:[{issueNumber:issue(g,i),gameCode:g,number:'9'},...Array.from({length:10},(_,n)=>({issueNumber:issue(g,latest-n),gameCode:g,number:String(((latest-n)%10+10)%10)}))],totalPage:10}};};
 const noop=()=>{},resolved=async()=>{},global={synchronizer:{getCurrentTime:()=>Date.now()},gameCode:computed(()=>state.gameCode),lotteryCode:computed(()=>state.gameCode.split('_')[0]),gameInfo:ref({state:1}),triggerTimer:{on:noop},trigger:{emit:e=>events.push({kind:e,at:Date.now()})},setLotteryCode:c=>state.gameCode=c,getGameInfo:resolved,getGameList:resolved,updateBalance:resolved,getUserInfo:resolved,onBetTrigger:noop,token:ref('fixture')};
 // Native worker intentionally unavailable: deadline watchdog must recover without it.
 const context={Ne:noop,A:()=>({query:{},params:{gameCode:state.gameCode}}),U:()=>({replace:resolved,push:noop}),Pe:()=>vis,ke:()=>({lotteryInline:ref(false)}),xe:reactive,T:ref,Me:onMounted,Re:watch,n:computed,$e:noop,Ge:ms=>({minutes:Math.floor(ms/60000),seconds:Math.floor(ms/1000)%60}),He:()=>({localStore:{get:()=>0,set:noop}}),D:{},Oe:()=>global,pe:{Howl:function(){}},Ae:apiIssue,p:async a=>{sim.staticCalls++;return apiHistory(a)},__history34:apiHistory,Ue:resolved,We:resolved,Fe:resolved,Ve:()=>({pause:noop,resume:noop}),__unmount34:onUnmounted};
 if(window.liveIssue34){context.Ae=async args=>{calls.push({type:'issue',game:args.gameCode,at:Date.now()});return liveIssue34(args);};context.__history34=async args=>{calls.push({type:'history',game:args.gameCode,at:Date.now()});return liveHistory34(args);};}
 const factory=new Function(...Object.keys(context),nativeSource+';return st');const st=factory(...Object.values(context));window.mountNative=()=>{window.app=createApp({setup(){window.native=st({processSound:e=>events.push({kind:'sound',left:e,at:Date.now()})});onMounted(()=>native.getLottery());return()=>h('main',[h('div',{id:'period'},native.issue.value),h('div',{id:'count'},String(native.countdown.value.seconds)),h('div',{id:'rows'},native.historyIssues.value.map(r=>h('p',{class:'row'},r.issueNumber))),h('div',{id:'canBet'},String(native.canBet.value))]);}});app.mount('#app');};mountNative();
},source);
const run=ms=>p.clock.runFor(ms);const snap=()=>p.evaluate(()=>({issue:native.issue.value,seconds:native.countdown.value.seconds,rows:native.historyIssues.value.map(r=>r.issueNumber),calls:JSON.parse(JSON.stringify(calls)),events:JSON.parse(JSON.stringify(events)),staticCalls:sim.staticCalls}));
if(process.env.LIVE_GAME){
 await p.waitForFunction(()=>native.issue.value&&native.historyIssues.value.length,{},{timeout:30000});const initial=await snap();console.log('LIVE initial',initial.issue,initial.seconds,initial.rows[0]);
 await p.waitForFunction(old=>native.issue.value>old,initial.issue,{timeout:95000});
 await p.waitForFunction(old=>native.historyIssues.value.some(r=>r.issueNumber===old),initial.issue,{timeout:45000});const after=await snap();assert(after.rows.every(r=>r<after.issue));assert.deepEqual(errors,[]);
 const result={liveProviderIntegration:true,loggedInPhoneProof:false,game:process.env.LIVE_GAME,initialIssue:initial.issue,initialLatest:initial.rows[0],nextIssue:after.issue,updatedLatest:after.rows[0],closedPeriodPresent:after.rows.includes(initial.issue),requestCount:after.calls.length,errors};fs.writeFileSync(path.resolve('/home/user/inspection/refresh34','live-boundary-'+process.env.LIVE_GAME+'.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));await browser.close();return;
}
await run(500);let first=await snap();assert(first.issue.endsWith('0600'));assert(first.rows.every(r=>r<first.issue),'never display current/future results');
await run(3000);let zero=await snap();assert(zero.issue.endsWith('0601'),'period advances at zero without navigation');assert(!zero.rows.some(r=>r.endsWith('0600')),'late provider result not invented');
await run(1200);let late=await snap();assert(late.rows[0].endsWith('0600'),'published result refreshes without back/reopen');assert(late.events.some(e=>e.kind==='bets'),'native own-bet history refresh event');
// A failing issue request must not leave the native period frozen. No betting at zero.
await p.evaluate(()=>{sim.failIssue=2;sim.failHistory=1;});await run(1900);assert.equal(await p.locator('#canBet').innerText(),'false');await run(7000);let recovered=await snap();assert(recovered.issue>late.issue,'issue retry recovery');assert(recovered.rows.every(r=>r<recovered.issue));
// Reject a delayed old-game response when 1M -> 30S changes during flight.
await p.evaluate(()=>{sim.issueDelay=2200;sim.historyDelay=2200;native.getIssue(true);native.getHistoryIssues();});await run(100);
await p.evaluate(()=>{state.gameCode='WinGo_30S';history.replaceState({},'','/WinGo/WinGo_30S');sim.issueDelay=0;sim.historyDelay=0;native.getIssue(true);native.getHistoryIssues();});await run(3500);let switched=await snap();assert.equal(switched.issue.slice(8,13),'10005');assert(switched.rows.every(r=>r.slice(8,13)==='10005'));
// Background time must not reveal a current/future result or require route reopening.
await p.evaluate(()=>{vis.value='hidden';document.dispatchEvent(new Event('visibilitychange'));});await run(10000);const hiddenCount=(await snap()).calls.length;
await run(3000);assert.equal((await snap()).calls.length,hiddenCount,'no hidden watchdog polling');
await p.evaluate(()=>{vis.value='visible';document.dispatchEvent(new Event('visibilitychange'));});await run(1800);let visible=await snap();assert(visible.issue>switched.issue);assert(visible.rows.every(r=>r<visible.issue));
await p.evaluate(()=>{sim.staleIssue=true;native.getIssue(true);});await run(50);assert.equal((await snap()).issue,visible.issue,'stale issue response cannot roll period backward');await p.evaluate(()=>sim.staleIssue=false);
assert.equal(visible.staticCalls,0,'WinGo must use filtered provider API, not old JSON route');
await p.evaluate(()=>app.unmount());await run(1500);const stopped=(await snap()).calls.length;await run(8000);assert.equal((await snap()).calls.length,stopped,'unmounted watchdog disposed');await p.evaluate(()=>{state.gameCode='WinGo_5M';history.replaceState({},'','/WinGo/WinGo_5M');sim.period=60000;sim.base=Date.now();sim.failIssue=0;sim.failHistory=0;mountNative();});await run(500);assert.equal(await p.locator('#canBet').innerText(),'true','betting enabled only after matching valid native issue');assert.equal((await snap()).issue.slice(8,13),'10003');await p.evaluate(()=>app.unmount());assert.deepEqual(errors,[]);
const result={pass:true,initial:first.issue,afterZero:zero.issue,afterLateResult:late.rows[0],afterRetry:recovered.issue,afterGameSwitch:switched.issue,afterVisibility:visible.issue,providerOnly:true,noFutureRows:true,noCrossGameRows:true,unmountClean:true,errors};
fs.writeFileSync(fixture+'/results.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result,null,2));await browser.close();})().catch(e=>{console.error(e);process.exit(1)});
