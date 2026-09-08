// v33 regression: full native lottery-info wrapper reproduces red backdrop left by v32.
const {chromium}=require('playwright');const fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(__dirname,'../.w13l'),fixtures=path.resolve(__dirname,'fixtures/header-timer-v32');
const native=fs.readFileSync(fixtures+'/native-wallet.css','utf8')+'\n'+fs.readFileSync(fixtures+'/native-timer.css','utf8');
const patch=fs.readFileSync(root+'/css/13l-patch25.css','utf8');
const oldAssets=JSON.parse(require('child_process').execFileSync('python3',['-c',"import subprocess,zipfile,io,json; z=zipfile.ZipFile(io.BytesIO(subprocess.check_output(['git','show','a679871:13l-update/13l-update.zip']))); print(json.dumps({k:z.read(v).decode() for k,v in {'css':'css/13l-patch25.css','js':'js/13l-hist25.js'}.items()}))"],{cwd:path.resolve(__dirname,'..'),encoding:'utf8'}));
const html=`<html><head><meta charset="utf-8"><style>html{font-size:min(10vw,40px)}body{margin:0;background:#282828;color:#fff;font-family:serif}*{box-sizing:border-box}:root{--light-main_gradient-color:linear-gradient(red,#ed2344);--bg_color_L2:#282828;--bg_color_L3:#444;--norm_green-color:#12b965;--norm_red-color:#ff5360;--text_color_L1:#fff;--text_color_L4:#fff}.game-header{height:1.44rem;display:flex;align-items:center;justify-content:space-between;padding:0 .4rem;background:red}.game-header img{width:80px;height:30px}.game-header button{color:white;background:transparent;border:0}.timer-cards{margin:0 .34667rem;background:#3b3b3b;border-radius:14px;height:80px;display:flex;align-items:center;justify-content:space-around;font:14px Arial}.timer-cards .active{background:#ed233a;border-radius:14px;padding:14px}.TimeLeft__C-num>div{background:rgba(255,255,255,.3);border-radius:50%;text-align:center;font:12px Arial}</style><style>${native}</style><style>${patch}</style></head><body><div id="app"><main class="winGo3"><div class="lottery-info" data-v-015dc728><div class="bg" data-v-015dc728></div><header class="game-header"><button id="back">←</button><img alt="13L" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='30'%3E%3Ctext x='8' y='22' fill='gold' font-size='22'%3E13L%3C/text%3E%3C/svg%3E"><button id="sound">♪</button></header><div class="Wallet__C"><div class="Wallet__C-balance"><div class="Wallet__C-balance-l1">₹1,938.14 <span class="refresh">↻</span></div><div class="Wallet__C-balance-l2">Wallet balance</div><div class="Wallet__C-balance-l3"><div id="withdraw">Withdraw</div><div id="deposit">Deposit</div></div></div></div><div class="timer-cards"><div>WinGo<br>30sec</div><div class="active">WinGo<br>1 Min</div><div>WinGo<br>3 Min</div><div>WinGo<br>5 Min</div></div></div><div class="TimeLeft__C"><div class="TimeLeft__C-rule" id="rules">▣ How To Play</div><div class="TimeLeft__C-name">WinGo 1 Min</div><div class="TimeLeft__C-num"><div>2</div><div>4</div><div>8</div><div>6</div><div>4</div></div><div class="TimeLeft__C-id">20260907100010789</div><div class="TimeLeft__C-text">Time remaining</div><div class="TimeLeft__C-time"><div>0</div><div>0</div><div>:</div><div>5</div><div>6</div></div></div></main></div></body></html>`;
(async()=>{const b=await chromium.launch();const results=[];
for(const width of [320,360,390,411,430]){
 const p=await b.newPage({viewport:{width,height:820}});const errors=[],requests=[];p.on('pageerror',e=>errors.push(e.message));
 await p.route('**/*',r=>{if(r.request().isNavigationRequest())return r.fulfill({contentType:'text/html',body:html});requests.push(r.request().url());return r.fulfill({contentType:'application/json',body:'{"data":true}'});});
 await p.goto('https://fixture.invalid/WinGo/WinGo_1M');
 await p.evaluate(()=>{
  document.querySelectorAll('.Wallet__C,.Wallet__C *').forEach(e=>e.setAttribute('data-v-920597dd',''));
  document.querySelectorAll('.timer-cards').forEach(e=>e.setAttribute('data-v-7341bb60',''));
  document.querySelectorAll('.TimeLeft__C,.TimeLeft__C *').forEach(e=>e.setAttribute('data-v-5f093580',''));
  window.actions={};['back','sound','withdraw','deposit','rules'].forEach(id=>document.getElementById(id).onclick=()=>actions[id]=(actions[id]||0)+1);
 });
 assert((await p.locator('.lottery-info > .bg').evaluate(e=>getComputedStyle(e).backgroundImage)).includes('gradient'),'full native wrapper must reproduce red gradient');
 const oldCSS=oldAssets.css,oldJS=oldAssets.js;
 await p.evaluate(()=>document.querySelector('head>style:last-child').remove());await p.addStyleTag({content:oldCSS});await p.addScriptTag({content:oldJS});
 assert.equal(await p.locator('.Wallet__C').evaluate(e=>getComputedStyle(e,'::before').display),'none');assert.notEqual(await p.locator('.lottery-info > .bg').evaluate(e=>getComputedStyle(e).display),'none','v32 leaves real red layer visible');
 if(width===411)await p.screenshot({path:'/home/user/inspection/red33/reproduced-v32.png'});
 await p.addStyleTag({content:patch});
 await p.addScriptTag({path:root+'/js/13l-hist25.js'});await p.evaluate(()=>document.fonts.ready);
 const data=await p.evaluate(()=>{
  const wallet=document.querySelector('.Wallet__C'),ticket=document.querySelector('.TimeLeft__C'),cs=getComputedStyle(document.querySelector('.TimeLeft__C-time>div'));
  return {infoBG:getComputedStyle(document.querySelector('.lottery-info > .bg')).display,surface:getComputedStyle(document.querySelector('.lottery-info')).backgroundColor,redLayer:getComputedStyle(wallet,'::before').display,wallet:getComputedStyle(document.querySelector('.Wallet__C-balance')).backgroundColor,header:getComputedStyle(document.querySelector('.game-header')).backgroundColor,ticketImage:getComputedStyle(ticket).backgroundImage,cell:cs.backgroundColor,digit:cs.color,font:cs.fontFamily,weight:cs.fontWeight,label:getComputedStyle(document.querySelector('.TimeLeft__C-text')).color,period:getComputedStyle(document.querySelector('.TimeLeft__C-id')).color,bounds:[...document.querySelectorAll('.TimeLeft__C-rule,.TimeLeft__C-id,.TimeLeft__C-text,.TimeLeft__C-time')].map(e=>{const r=e.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom};}),ticket:(()=>{const r=ticket.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom};})()};
 });
 const client=await p.context().newCDPSession(p);await client.send('DOM.enable');await client.send('CSS.enable');const doc=await client.send('DOM.getDocument');const node=await client.send('DOM.querySelector',{nodeId:doc.root.nodeId,selector:'.TimeLeft__C-time>div'});const actualFonts=(await client.send('CSS.getPlatformFontsForNode',{nodeId:node.nodeId})).fonts;assert(actualFonts.some(f=>f.isCustomFont&&/Poppins-Bold/.test(f.postScriptName)),'real Poppins Bold must render');
 assert.equal(data.infoBG,'none');assert.equal(data.surface,'rgb(40, 40, 40)');assert.equal(data.redLayer,'none');assert.equal(data.header,'rgb(40, 40, 40)');assert.equal(data.wallet,'rgb(59, 59, 59)');assert.equal(data.cell,'rgb(255, 255, 255)');assert.equal(data.digit,'rgb(181, 27, 50)');assert.equal(data.weight,'700');assert.equal(data.label,'rgb(255, 255, 255)');assert.equal(data.period,'rgb(255, 255, 255)');assert(data.ticketImage.includes('data:image/svg+xml'));
 for(const r of data.bounds){assert(r.left>=data.ticket.left-1 && r.right<=data.ticket.right+1 && r.top>=data.ticket.top && r.bottom<=data.ticket.bottom,'ticket child clipped');}
 for(const id of ['back','sound','withdraw','deposit','rules'])await p.locator('#'+id).click();
 assert.deepEqual(await p.evaluate(()=>actions),{back:1,sound:1,withdraw:1,deposit:1,rules:1});
 // Native countdown and issue updates must remain untouched by this CSS patch.
 for(const count of ['00:55','00:05','00:00','00:59']){
  await p.evaluate(value=>document.querySelectorAll('.TimeLeft__C-time>div').forEach((e,i)=>e.textContent=value[i]),count);
  await p.waitForTimeout(40);assert.equal(await p.locator('.TimeLeft__C-time').innerText().then(s=>s.replace(/\s/g,'')),count);
 }
 await p.waitForTimeout(950);assert.equal(await p.locator('.TimeLeft__C-time').innerText().then(s=>s.replace(/\s/g,'')),'00:59');
 assert.equal(await p.locator('.TimeLeft__C-id').innerText(),'20260907100010789');
 assert(!requests.some(u=>/GetGameIssue|GetHistory|Bet/.test(u)),'no game data calls added');assert.deepEqual(errors,[]);
 await p.screenshot({path:path.resolve('/home/user/inspection/red33',`fixture-${width}.png`),fullPage:true});
 // Both home and unrelated games retain their original backgrounds.
 for(const route of ['/','/K3/K3_1M']){await p.evaluate(route=>history.pushState({},'',route),route);assert.equal(await p.locator('html').getAttribute('data-13l-wingo-ui'),'0');assert.notEqual(await p.locator('.lottery-info > .bg').evaluate(e=>getComputedStyle(e).display),'none');}
 results.push({width,infoBG:data.infoBG,surface:data.surface,redLayer:data.redLayer,wallet:data.wallet,header:data.header,digit:data.digit,cell:data.cell,font:data.font,weight:data.weight,actualFonts,clipping:false,actionsPreserved:true,countdownPreserved:true,errors});await p.close();
}
await b.close();fs.writeFileSync(path.resolve(__dirname,'fixtures/header-timer-v32/results-v33.json'),JSON.stringify(results,null,2));console.log(JSON.stringify(results,null,2));})().catch(e=>{console.error(e);process.exit(1)});
