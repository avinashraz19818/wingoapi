// Local native-shaped Vue fixture; verifies pending presentation, NOT fabricated countdown values.
const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(__dirname,'../.w13l'),fixtures=path.resolve(__dirname,'fixtures/ui-v36');
const patch=fs.readFileSync(root+'/css/13l-patch25.css','utf8'),native=fs.readFileSync(path.resolve(__dirname,'fixtures/header-timer-v32/native-timer.css'),'utf8');
const betting=fs.readFileSync(path.resolve(__dirname,'fixtures/ui-v37/native-betting.css'),'utf8');
const js=fs.readFileSync(root+'/js/13l-hist25.js','utf8');
for(const [variable,file] of [['headset35','customer.svg'],['mute36','mute.svg'],['unmute36','unmute.svg']]){const match=js.match(new RegExp('var '+variable+'=(.*);'));assert(match);assert.equal(JSON.parse(match[1]),fs.readFileSync(fixtures+'/'+file,'utf8'),'Dhani SVG must be exact source bytes');}
(async()=>{const browser=await chromium.launch(),results=[];for(const width of [320,360,390,393,411,430]){
 const p=await browser.newPage({viewport:{width,height:650}}),errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.route('**/*',r=>r.fulfill(r.request().isNavigationRequest()?{contentType:'text/html',body:`<html><head><meta charset="utf-8"><style>html{font-size:min(10vw,40px);--main-color:#ff6869;--bg_color_L3:#3b3b3b}body{margin:0;background:#282828;color:white;font:14px Arial}*{box-sizing:border-box}.tabs{display:flex;gap:8px;padding:18px}.tabs button{padding:12px;background:#444;color:#fff;border:0}.history{padding:20px} ${native}\n${betting}\n${patch}</style></head><body><div id="app"></div></body></html>`}:{contentType:'application/json',body:'{}'}));
 await p.goto('https://fixture.invalid/WinGo/WinGo_1M');await p.addScriptTag({path:require.resolve('vue/dist/vue.global.prod.js')});
 await p.evaluate(()=>{const {createApp,reactive,h,KeepAlive}=Vue;window.state=reactive({gameCode:'WinGo_1M',seconds:0,issue:''});window.responseLog=[];window.betClicks37=0;state.loadedGame='';
  const Row={setup(){return()=>h('p','Native history remains separate');}};
  window.app=createApp({setup(){return()=>h('main',{class:'winGo3'},[
   h('div',{class:'tabs'},['WinGo_30S','WinGo_1M','WinGo_3M','WinGo_5M'].map(game=>h('button',{onClick:()=>{state.gameCode=game;state.seconds=0;}},game.split('_')[1]))),
   h('div',{class:'TimeLeft__C','data-v-5f093580':''},[
    h('div',{class:'TimeLeft__C-name','data-v-5f093580':''},state.gameCode),h('div',{class:'TimeLeft__C-text','data-v-5f093580':''},'Time remaining'),
    h('div',{class:'TimeLeft__C-time','data-v-5f093580':''},String(Math.floor(state.seconds/60)).padStart(2,'0').concat(':',String(state.seconds%60).padStart(2,'0')).split('').map(t=>h('div',{'data-v-5f093580':''},t))),
    h('div',{class:'TimeLeft__C-id','data-v-5f093580':''},state.issue)]),
   h('div',{class:'Betting__C','data-v-24ae940c':'',style:{height:'230px',margin:'16px',background:'#333'}},[
    h('button',{id:'bet37',onClick:()=>betClicks37++,style:{padding:'18px',margin:'18px',background:'#0c6',color:'#fff'}},'Native bet action'),
    h('div',{class:'Betting__C-mark','data-v-24ae940c':'',style:{display:state.loadedGame===state.gameCode&&state.seconds>5?'none':undefined}},String(state.seconds%60).padStart(2,'0').split('').map(t=>h('div',{'data-v-24ae940c':''},t)))
   ]),
   h('div',{class:'history'},[h(KeepAlive,null,[h(Row)])])]);}}).mount('#app');});
 await p.addScriptTag({path:root+'/js/13l-hist25.js'});
 await p.evaluate(()=>{window.adapter=__13lRound34({game:()=>state.gameCode,seconds:()=>state.seconds,setSeconds:s=>state.seconds=s,rows:()=>{},sound:()=>{},notify:()=>{},issue:async()=>{},history:async()=>{}});
 window.publish36=(game,seconds)=>{const prefix={WinGo_1M:'10001',WinGo_30S:'10005',WinGo_3M:'10002',WinGo_5M:'10003'},data={issueNumber:'20260908'+prefix[game]+'0800',countdown:seconds};const accepted=adapter.acceptIssue(data,game);if(accepted){state.loadedGame=game;state.issue=data.issueNumber;adapter.received(data);}responseLog.push({game,accepted});return accepted;};});
 const cell=p.locator('.TimeLeft__C-time > div').first();
 const pending=async()=>{assert.equal(await p.locator('.Betting__C-mark').getAttribute('aria-busy'),'true');assert.equal(await p.locator('.Betting__C-mark').evaluate(e=>getComputedStyle(e,'::after').content),'"Loading game…"');assert(await p.locator('.Betting__C-mark > div').evaluateAll(es=>es.every(e=>getComputedStyle(e).visibility==='hidden')));assert.equal(await p.locator('html').getAttribute('data-13l-timer-ready'),'0');assert.equal(await p.locator('.TimeLeft__C-time').getAttribute('aria-busy'),'true');assert.equal(await cell.evaluate(e=>getComputedStyle(e).color),'rgba(0, 0, 0, 0)');assert.equal(await cell.evaluate(e=>getComputedStyle(e,'::after').content),'"–"');};
 await pending();const hit=await p.locator('#bet37').boundingBox();await p.mouse.click(hit.x+hit.width/2,hit.y+hit.height/2);assert.equal(await p.evaluate(()=>betClicks37),0,'native overlay must intercept pending taps');assert.equal(await p.locator('.TimeLeft__C-time').innerText().then(t=>t.replace(/\s/g,'')),'00:00','native values are untouched, just pending presentation');
 await p.screenshot({path:'/home/user/inspection/ui37/pending-'+width+'.png'});
 await p.evaluate(()=>publish36('WinGo_1M',47));await p.waitForTimeout(60);assert.equal(await p.locator('html').getAttribute('data-13l-timer-ready'),'1');assert.equal(await cell.evaluate(e=>getComputedStyle(e).color),'rgb(223, 108, 111)');
 assert.equal(await p.locator('.Betting__C-mark').isVisible(),false);await p.locator('#bet37').click();assert.equal(await p.evaluate(()=>betClicks37),1);
 // Valid closing countdown 05..00 remains native, not loading.
 for(const seconds of [5,4,3,2,1,0]){await p.evaluate(v=>state.seconds=v,seconds);await p.waitForTimeout(30);assert.equal(await p.locator('.Betting__C-mark').isVisible(),true);assert(await p.locator('.Betting__C-mark > div').evaluateAll(es=>es.every(e=>getComputedStyle(e).visibility==='visible')));assert.equal(await p.locator('.Betting__C-mark').innerText().then(s=>s.replace(/\s/g,'')),String(seconds).padStart(2,'0'));assert.equal(await p.locator('.Betting__C-mark').evaluate(e=>getComputedStyle(e,'::after').content),'none');}
 await p.mouse.click(hit.x+hit.width/2,hit.y+hit.height/2);assert.equal(await p.evaluate(()=>betClicks37),1,'genuine closing overlay still intercepts taps');
 await p.screenshot({path:'/home/user/inspection/ui37/real-zero-'+width+'.png'});
 // Real round zero must remain visible, never treated as a loading signal.
 await p.evaluate(()=>state.seconds=0);await p.waitForTimeout(60);assert.equal(await p.locator('html').getAttribute('data-13l-timer-ready'),'1');assert.equal(await cell.evaluate(e=>getComputedStyle(e).color),'rgb(223, 108, 111)');
 await p.getByRole('button',{name:'30S',exact:true}).click();await pending();await p.emulateMedia({reducedMotion:'reduce'});assert.equal(await p.locator('.Betting__C-mark').evaluate(e=>getComputedStyle(e,'::before').animationName),'none');await p.waitForTimeout(1200);await pending();
 await p.getByRole('button',{name:'3M',exact:true}).click();await pending();assert.equal(await p.evaluate(()=>publish36('WinGo_30S',21)),false,'late old-tab response cannot release loading gate');await pending();
 assert.equal(await p.evaluate(()=>publish36('WinGo_3M',125)),true);await p.waitForTimeout(80);assert.equal(await p.locator('html').getAttribute('data-13l-timer-ready'),'1');assert.equal(await p.locator('.TimeLeft__C-time').innerText().then(t=>t.replace(/\s/g,'')),'02:05');
 const boxes=await p.locator('.TimeLeft__C-time > div').evaluateAll(els=>els.map(e=>{const r=e.getBoundingClientRect();return {left:r.left,right:r.right};}));assert(boxes.every(r=>r.left>=0&&r.right<=width));
 await p.screenshot({path:'/home/user/inspection/ui37/ready-'+width+'.png'});
 await p.evaluate(()=>history.pushState({},'','/K3/K3_1M'));assert.equal(await p.locator('html').getAttribute('data-13l-wingo-ui'),'0');assert.notEqual(await cell.evaluate(e=>getComputedStyle(e).color),'rgba(0, 0, 0, 0)');assert.deepEqual(errors,[]);
 results.push({width,pendingPlaceholder:true,loadingOverlay:true,pendingTapsBlocked:true,validBetActionPreserved:true,realClosing05to00Visible:true,nativeZeroUntouched:true,realZeroVisible:true,lateResponseRejected:true,loadedCountdown:'02:05',noOverflow:true,errors});await p.close();
}await browser.close();fs.writeFileSync(path.resolve(__dirname,'fixtures/ui-v37/switch-results.json'),JSON.stringify(results,null,2)+'\n');console.log(JSON.stringify(results,null,2));})().catch(e=>{console.error(e);process.exit(1)});
