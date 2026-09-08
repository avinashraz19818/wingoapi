const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('assert');
const root=path.resolve(__dirname,'../.w13l');
const old=JSON.parse(require('child_process').execFileSync('python3',['-c',"import subprocess,zipfile,io,json;z=zipfile.ZipFile(io.BytesIO(subprocess.check_output(['git','show','28dc40a:13l-update/13l-update.zip'])));print(json.dumps(z.read('js/13l-hist25.js').decode()))"],{cwd:path.resolve(__dirname,'..'),encoding:'utf8'}));
const oneOldFix=old.slice(old.indexOf('  function headFix(){'),old.indexOf('\n  // v30:'))+';headFix();';
const css=fs.readFileSync(path.resolve(__dirname,'fixtures/header-timer-v32/native-wallet.css'),'utf8');
const clock='data:image/svg+xml,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><circle cx="32" cy="32" r="29" fill="#aaa" stroke="white" stroke-width="4"/><path d="M32 10V32L19 23" stroke="white" stroke-width="3"/></svg>');
(async()=>{const b=await chromium.launch(),results=[];for(const width of [320,360,390,393,411,430]){
const p=await b.newPage({viewport:{width,height:700}});const errors=[];p.on('pageerror',e=>errors.push(e.message));
await p.route('**/*',r=>r.fulfill(r.request().isNavigationRequest()?{contentType:'text/html',body:`<html><head><meta charset="utf-8"><style>${css}html{font-size:min(10vw,40px)}body{margin:0;background:#282828;color:#fff;font-size:14px}.game-header{height:58px;position:relative}.logo{width:80px;height:28px}.wallet{height:280px}.timer-card{color:white}.clock-icon{display:flex;align-items:center;justify-content:center}.clock-icon img{width:1.33333rem;height:1.33333rem}.filler{height:1800px}</style></head><body><div id="app"><header class="game-header"><img class="logo" alt="13L" src="${clock}"></header><div class="wallet"><button id="deposit">Deposit</button></div><div class="timer-cards" data-v-7341bb60>${['30 sec','1 Min','3 Min','5 Min'].map((n,i)=>`<div class="timer-card ${i===1?'active':''}" data-v-7341bb60><div class="clock-icon" data-v-7341bb60><img class="timeIcon" data-v-7341bb60 src="${clock}"></div><div class="card-title" data-v-7341bb60>WinGo ${n}</div></div>`).join('')}</div><div class="filler"></div></div></body></html>`}:{contentType:'application/json',body:'{}'}));
await p.goto('https://fixture.invalid/WinGo/WinGo_1M');await p.evaluate(()=>scrollTo(0,310));
const before=await p.locator('.timer-cards img').evaluateAll(els=>els.map(e=>({x:e.getBoundingClientRect().x,y:e.getBoundingClientRect().y})));
await p.addScriptTag({content:oneOldFix});const corrupt=await p.locator('.timer-cards img').evaluateAll(els=>els.filter(e=>(e.getAttribute('style')||'').includes('13lc')).length);
if(width<400)assert.equal(corrupt,1,'must reproduce reported clock displacement on scrolled phone');
await p.addScriptTag({path:root+'/js/13l-hist25.js'});await p.waitForTimeout(1100);
for(const offset of [310,330,280,0,310]){
 await p.evaluate(y=>scrollTo(0,y),offset);await p.waitForTimeout(1000);
 const checks=await p.locator('.timer-cards img').evaluateAll(els=>els.map(e=>{const a=e.getBoundingClientRect(),c=e.closest('.timer-card').getBoundingClientRect();return {a:{x:a.x,y:a.y,w:a.width,h:a.height},c:{x:c.x,y:c.y,w:c.width,h:c.height},inside:a.left>=c.left-1&&a.right<=c.right+1&&a.top>=c.top-1&&a.bottom<=c.bottom+1,position:getComputedStyle(e).position};}));
 assert(checks.every(c=>c.inside&&c.position!=='absolute'),'every clock stays in its own native card after scroll');
}
assert.notEqual(await p.locator('#deposit').evaluate(e=>getComputedStyle(e).display),'none','wallet Deposit is not a header Deposit');assert.deepEqual(errors,[]);
await p.screenshot({path:'/home/user/inspection/refresh34/clock-'+width+'.png'});results.push({width,oldDisplaced:corrupt,afterScrollAllInside:true,walletDepositVisible:true,errors});await p.close();}
await b.close();fs.writeFileSync(path.resolve(__dirname,'fixtures/native-v34/clock-results.json'),JSON.stringify(results,null,2)+'\n');console.log(JSON.stringify(results,null,2));})().catch(e=>{console.error(e);process.exit(1)});
