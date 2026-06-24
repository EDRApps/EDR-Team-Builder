/* EDR Team Builder (generated). Front-end logic adapted from EDR-Team-Builder.html. */
(function(){
const LOGO=(window.EDR_TB&&EDR_TB.logo)||'';
const APP_HTML=`
<div class="header">
  <img src="${LOGO}" alt="Endurotech Racing">
  <div>
    <div class="head" style="letter-spacing:.18em;font-size:11px;color:var(--yellow);text-transform:uppercase">Endurotech Racing</div>
    <h1 style="font-size:26px;margin:2px 0;letter-spacing:.04em;text-transform:uppercase;color:#fff">Endurance Team Builder</h1>
    <div class="meta" style="color:var(--body)">GT3 and LMP2 iRacing endurance, raced long and finished.</div>
    <div class="meta" id="summary" style="display:flex;gap:16px;flex-wrap:wrap;margin-top:4px"></div>
  </div>
</div>
<div class="controls">
  <div class="ctl"><label>PACE weight <b id="lpace" style="color:var(--amber)"></b></label><input type="range" id="pace" min="0" max="100" style="accent-color:var(--amber)"></div>
  <div class="ctl"><label>CLEAN weight <b id="lclean" style="color:var(--steel)"></b></label><input type="range" id="clean" min="0" max="100" style="accent-color:var(--steel)"></div>
  <div class="ctl"><label>PREP weight <b id="lprep" style="color:var(--green)"></b></label><input type="range" id="prep" min="0" max="100" style="accent-color:var(--green)"></div>
  <div class="ctl" style="flex:1 1 160px"><label>PRO = top <b id="lpro" style="color:var(--gold)"></b>% of each class</label><input type="range" id="propct" min="0" max="100" step="5" style="accent-color:var(--gold)"></div>
  <button class="btn btn-amber" id="gen">Generate</button>
  <button class="btn btn-ghost" id="imp">Import JSON</button>
  <button class="btn btn-ghost" id="reset">Reset</button>
  <span class="status" id="status"></span>
</div>
<div class="tabs">
  <button class="tab active" data-tab="setup">Setup</button>
  <button class="tab" data-tab="teams">Teams</button>
  <button class="tab" data-tab="drivers">Drivers</button>
  <button class="tab" data-tab="stints">Stints</button>
</div>
<div class="wrap"><div class="importbox hide" id="importbox">
  <div class="meta" style="margin-bottom:8px">Paste a roster JSON (advanced / manual fallback), then Load.</div>
  <textarea id="importtext" placeholder='[{"name":"...","cars":{"Ferrari 296 GT3":{"laps":168,"medianLap":105.21,"cleanPct":0.91}}}]'></textarea>
  <button class="btn btn-green" id="load" style="margin-top:8px">Load</button>
</div><div id="content"></div></div>
`;
const _app=document.getElementById('edr-tb-app'); if(!_app){return;} _app.innerHTML=APP_HTML;

const KEY = 'edrTeamBuilder_spa_v6'; /* Spa 24h — S3 pace, survey 2307 availability */

// --- Spa 24h event timing (for the Stints tab) ---
// Availability window starts 2026-07-10 22:00 UTC (= 11 Jul 08:00 Brisbane). All offsets in minutes from there.
// 4 candidate start slots (iRacePlan survey 2307 session_times).
let WIN_START_MS = Date.parse('2026-07-10T22:00:00Z');
let START_OFFSETS = {1:0, 2:540, 3:840, 4:1080};
let START_LABELS = {1:'22:00Z', 2:'07:00Z', 3:'12:00Z', 4:'16:00Z'};

// Spa 24h: Garage 61 pace (iRacing 2026 Season 3 only, from 2026-06-16; age=-1) for the 18 drivers in iRacePlan survey 2307.
// Availability merged from iRacePlan survey 2307 timeline (green = available). Empty cars = no Spa data shared.
const SAMPLE = [];

let state = { drivers:[], w:{pace:50,clean:30,prep:20}, proPct:40, tab:'teams', teams:{}, stint:{window:1,len:120,race:1440}, stintAssign:{}, stintWin:{}, stintSig:'' };

function seed(list){
  let id=1;
  state.drivers = list.map(d => ({ id:id++, name:d.name, cars:d.cars||{}, assignedCar:Object.keys(d.cars||{})[0], avail:d.avail||null }));
}

function save(){ try{ localStorage.setItem(KEY, JSON.stringify({drivers:state.drivers,w:state.w,proPct:state.proPct,stint:state.stint,stintAssign:state.stintAssign,stintWin:state.stintWin,stintSig:state.stintSig})); }catch(e){} }
function load(){
  try{
    const s = JSON.parse(localStorage.getItem(KEY));
    if(s && s.drivers && s.drivers.length){ state.drivers=s.drivers; state.w=s.w||state.w; state.proPct=(typeof s.proPct==='number')?s.proPct:state.proPct; state.stint=Object.assign(state.stint, s.stint||{}); state.stintAssign=s.stintAssign||{}; state.stintWin=s.stintWin||{}; state.stintSig=s.stintSig||''; return true; }
  }catch(e){}
  return false;
}

function classOf(name){
  const n=(name||'').toUpperCase();
  if(n.includes('GTP')||n.includes('HYBRID')||n.includes('LMDH'))return 'GTP';
  if(n.includes('LMP2')||n.includes('P217')||n.includes('LMP'))return 'LMP2';
  if(n.includes('GT4'))return 'GT4';
  if(n.includes('GT3'))return 'GT3';
  return 'Other';
}
function fmtLap(s){ if(s==null)return '—'; const m=Math.floor(s/60); const sec=(s%60).toFixed(3); return m+':'+String(sec).padStart(6,'0'); }

function computeModel(){
  const enriched = state.drivers.map(d=>{
    const car = (d.cars && d.cars[d.assignedCar]) ? d.assignedCar : Object.keys(d.cars||{})[0];
    const st = (d.cars && d.cars[car]) || {laps:0,medianLap:null,cleanPct:0};
    return Object.assign({}, d, {_car:car,_class:classOf(car),_laps:st.laps,_median:st.medianLap,_clean:st.cleanPct});
  });
  const groups={};
  enriched.forEach(d=>{(groups[d._class]=groups[d._class]||[]).push(d);});
  const sum=state.w.pace+state.w.clean+state.w.prep||1;
  const wp=state.w.pace/sum, wc=state.w.clean/sum, wr=state.w.prep/sum;
  Object.values(groups).forEach(grp=>{
    const med=grp.map(d=>d._median).filter(x=>x!=null);
    const laps=grp.map(d=>d._laps), cl=grp.map(d=>d._clean);
    const minM=Math.min.apply(null,med), maxM=Math.max.apply(null,med);
    const minL=Math.min.apply(null,laps), maxL=Math.max.apply(null,laps);
    const minC=Math.min.apply(null,cl), maxC=Math.max.apply(null,cl);
    grp.forEach(d=>{
      const pace=d._median==null?0:(maxM===minM?0.5:(maxM-d._median)/(maxM-minM));
      const prep=maxL===minL?0.5:(d._laps-minL)/(maxL-minL);
      const clean=maxC===minC?0.5:(d._clean-minC)/(maxC-minC);
      d._score=wp*pace+wc*clean+wr*prep;
    });
    grp.sort((a,b)=>b._score-a._score);
    const proN=Math.max(0,Math.round((state.proPct/100)*grp.length));
    grp.forEach((d,i)=>{d._tier=i<proN?'pro':'casual'; d._rank=i;});
  });
  return groups;
}

function teamSizes(n){ if(n<=0)return[]; if(n<=3)return[n]; const t=Math.ceil(n/3); const s=Array(t).fill(2); let rem=n-2*t,i=0; while(rem>0){s[i%t]++;rem--;i++;} return s; }
function buildPool(pool){
  if(!pool.length)return[];
  const sizes=teamSizes(pool.length);
  const sorted=pool.slice().sort((a,b)=>b._score-a._score);
  const teams=sizes.map(()=>[]);
  let i=0,round=0;
  while(i<sorted.length){
    let order=teams.map((_,k)=>k); if(round%2===1)order.reverse();
    for(const ti of order){ if(teams[ti].length<sizes[ti]&&i<sorted.length)teams[ti].push(sorted[i++]); }
    round++;
  }
  return teams.map(t=>t.map(d=>d.id));
}
const PRESET_TEAMS = {"GTP":[["Jake Lennox-Bradley","John Nyhouse","Erik van der Bijl"]],"GT3":[["Chris Wilson","Matt Halden","Sam Millar","Tom Williams"],["Thomaz Hernandes","Valentin Ozhiganov","Jarrod Williams"],["Dominic Bou-Samra","Michael Cullen","Sam Mackenzie","Joey Tavora"]]};
function applyPreset(){
  const byName={}; state.drivers.forEach(d=>byName[d.name]=d.id);
  const next={};
  Object.keys(PRESET_TEAMS).forEach(cls=>{ next[cls]={pro:PRESET_TEAMS[cls].map(car=>car.map(n=>byName[n]).filter(x=>x!=null)).filter(c=>c.length),casual:[]}; });
  state.teams=next; state.stintAssign={}; state.stintSig=''; save();
}
function generate(){
  const model=computeModel(); const next={};
  Object.keys(model).forEach(cls=>{
    const grp=model[cls];
    next[cls]={pro:buildPool(grp.filter(d=>d._tier==='pro')), casual:buildPool(grp.filter(d=>d._tier==='casual'))};
  });
  state.teams=next;
}

function esc(s){ return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

function renderSummary(model){
  const classes=Object.keys(model);
  document.getElementById('summary').innerHTML =
    '<span>Spa-Francorchamps (Endurance) · 24h</span><span>'+state.drivers.length+' drivers · '+classes.length+' classes</span>'+
    classes.map(c=>'<span style="color:var(--dim)">'+esc(c)+'</span>').join('');
}

function renderTeams(byId){
  const classes=Object.keys(state.teams);
  if(!classes.length) return '<div class="meta">Hit Generate or load teams.</div>';
  // dropdown lists EVERY car (both tiers, all classes) so any driver can go anywhere
  function moveOpts(curC,curT,curJ){
    let o='';
    classes.forEach(c=>['pro','casual'].forEach(t=>{ (state.teams[c][t]||[]).forEach((_,j)=>{ const v=c+'|'+t+'|'+j; const sel=(c===curC&&t===curT&&j===curJ)?' selected':''; o+='<option value="'+v+'"'+sel+'>'+esc(c)+' '+t[0].toUpperCase()+(j+1)+'</option>'; }); }));
    return o;
  }
  let html='';
  classes.forEach(cls=>{
    html+='<div style="margin-bottom:26px"><h2 style="font-size:16px;letter-spacing:.06em;text-transform:uppercase;margin:0 0 14px;border-bottom:2px solid var(--yellow);padding-bottom:8px;color:#fff">'+esc(cls)+'</h2>';
    [['pro','PRO','var(--gold)'],['casual','CASUAL','var(--steel)']].forEach(([tier,label,col])=>{
      const list=(state.teams[cls]&&state.teams[cls][tier])||[];
      html+='<div style="margin-bottom:16px"><div style="display:flex;align-items:center;gap:10px;margin-bottom:10px"><span class="swatch" style="background:'+col+'"></span><span class="head" style="letter-spacing:2px;font-size:13px;color:'+col+'">'+label+'</span><button class="btn btn-ghost" data-action="addcar" data-class="'+esc(cls)+'" data-tier="'+tier+'" style="font-size:10px;padding:3px 9px">+ car</button></div><div class="grid">';
      list.forEach((team,ti)=>{
        const members=team.map(id=>byId[id]).filter(Boolean);
        const meds=members.map(m=>m._median).filter(x=>x!=null);
        const avg=meds.length?meds.reduce((a,b)=>a+b,0)/meds.length:null;
        html+='<div class="card"><div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px"><span class="head" style="color:'+col+';letter-spacing:1px">'+label[0]+(ti+1)+'</span><span class="meta">avg '+fmtLap(avg)+(members.length?'':' <span data-action="delcar" data-class="'+esc(cls)+'" data-tier="'+tier+'" data-idx="'+ti+'" title="remove empty car" style="color:var(--red);cursor:pointer;font-size:13px">✕</span>')+'</span></div>';
        members.forEach(d=>{
          html+='<div class="mem"><div style="min-width:0"><div style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+esc(d.name)+'</div><div style="font-size:10px;color:var(--dim)">'+fmtLap(d._median)+' · '+d._laps+' laps · '+Math.round(d._clean*100)+'% clean'+(d.avail?' · <span style="color:'+(d.avail.pct>=80?'var(--green)':d.avail.pct>=40?'var(--amber)':'var(--red)')+'">'+d.avail.pct+'% avail</span>':'')+'</div></div><select data-action="move" data-id="'+d.id+'">'+moveOpts(cls,tier,ti)+'</select></div>';
        });
        if(!members.length) html+='<div class="meta">empty</div>';
        html+='</div>';
      });
      html+='</div></div>';
    });
    html+='</div>';
  });
  return html;
}

function renderDrivers(model){
  let html='<div style="display:flex;justify-content:flex-end;margin-bottom:12px"></div>';
  Object.keys(model).forEach(cls=>{
    const grp=model[cls];
    html+='<div style="margin-bottom:18px"><div class="classhdr">'+esc(cls)+' <small>· '+grp.length+' drivers · ranked within class</small></div><div style="display:flex;flex-direction:column;gap:8px">';
    grp.forEach((d,i)=>{
      const cars=Object.keys(d.cars||{});
      let opts=cars.map(c=>'<option value="'+esc(c)+'"'+(c===d.assignedCar?' selected':'')+'>'+esc(c)+'</option>').join('');
      html+='<div class="row '+d._tier+'"><span class="meta" style="width:20px">#'+(i+1)+'</span><span style="font-size:13px;min-width:110px">'+esc(d.name)+'</span><select data-action="assign" data-id="'+d.id+'">'+opts+'</select><span class="meta">'+fmtLap(d._median)+' · '+d._laps+' laps · '+Math.round(d._clean*100)+'% clean'+(d.avail?' · '+d.avail.pct+'% avail':'')+'</span><span class="tier '+d._tier+'">'+d._tier.toUpperCase()+' · '+(d._score*100).toFixed(0)+'</span><button class="x" data-action="remove" data-id="'+d.id+'">✕</button></div>';
    });
    html+='</div></div>';
  });
  return html;
}

// ---- stint planning ----
function fmtClock(absMin){
  const d=new Date(WIN_START_MS + absMin*60000);
  return d.toLocaleString('en-AU',{timeZone:'Australia/Brisbane',weekday:'short',hour:'2-digit',minute:'2-digit',hour12:false});
}
function availBadge(d){ return d.avail ? d.avail.pct+'%' : 'no avail'; }
function driverFree(d,s,e){ return !!(d.avail && d.avail.windows && d.avail.windows.some(w=>w[0]<=s && w[1]>=e)); }
function blockTimes(off,len,race){ const n=Math.ceil(race/len),t=[]; for(let i=0;i<n;i++)t.push({s:off+i*len,e:off+Math.min((i+1)*len,race)}); return t; }
function carBestWindow(memberIds, byId, len, race){   // window with fewest uncovered blocks for this car
  let best=null;
  Object.keys(START_OFFSETS).forEach(w=>{
    const times=blockTimes(START_OFFSETS[w],len,race);
    const gaps=times.filter(t=>!memberIds.some(id=>{const m=byId[id];return m&&driverFree(m,t.s,t.e);})).length;
    if(!best || gaps<best.gaps) best={w:+w,gaps};
  });
  return best;
}
function autoPlan(memberIds, byId, times){
  const members=memberIds.map(id=>byId[id]).filter(Boolean);
  const count={}; members.forEach(m=>count[m.id]=0);
  const arr=[]; let prev=null;
  times.forEach(({s,e})=>{
    const elig=members.filter(m=>driverFree(m,s,e));
    let pool=elig.filter(m=>m.id!==prev); if(!pool.length) pool=elig;  // avoid back-to-back unless forced
    let pick=null;
    if(pool.length){ pool.sort((a,b)=>(count[a.id]-count[b.id])||(b._score-a._score)); pick=pool[0]; count[pick.id]++; }
    prev=pick?pick.id:null; arr.push(pick?pick.id:null);
  });
  return arr;
}
function allTeams(){
  const list=[];
  Object.keys(state.teams).forEach(cls=>['pro','casual'].forEach(tier=>{
    (state.teams[cls]&&state.teams[cls][tier]||[]).forEach(t=>{ if(t.length) list.push(t); });
  }));
  return list;
}
function windowSummary(byId, len, race){
  const teamsList=allTeams();
  const allIds=[...new Set(teamsList.reduce((a,t)=>a.concat(t),[]))];
  return Object.keys(START_OFFSETS).map(w=>{
    const off=START_OFFSETS[w], times=blockTimes(off,len,race);
    const free=allIds.filter(id=>{const m=byId[id]; return m&&driverFree(m,off,off+race);}).length;
    let covered=0;
    teamsList.forEach(team=>{ const mem=team.map(id=>byId[id]).filter(Boolean); if(times.every(t=>mem.some(m=>driverFree(m,t.s,t.e)))) covered++; });
    return {w:+w, off, free, covered, cars:teamsList.length, total:allIds.length};
  });
}
function renderStints(byId){
  const len=Math.max(10,state.stint.len), race=Math.max(30,state.stint.race);
  const sig=[len,race,JSON.stringify(state.teams)].join('|');
  if(state.stintSig!==sig){ state.stintAssign={}; state.stintSig=sig; }  // block length / teams changed -> fresh auto-plan
  let html='<div class="importbox" style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end">';
  html+='<div class="ctl"><label>DEFAULT START (UTC)</label><select data-action="stintwin">'+
    [1,2,3,4].map(w=>'<option value="'+w+'"'+(w===state.stint.window?' selected':'')+'>#'+w+' · '+START_LABELS[w]+'</option>').join('')+'</select></div>';
  html+='<div class="ctl"><label>BLOCK LENGTH (min)</label><input type="number" min="10" max="240" step="5" value="'+state.stint.len+'" data-action="stintlen" style="width:90px;padding:6px"></div>';
  html+='<div class="ctl"><label>RACE LENGTH (min)</label><input type="number" min="30" max="1440" step="10" value="'+state.stint.race+'" data-action="stintrace" style="width:90px;padding:6px"></div>';
  html+='<button class="btn btn-ghost" data-action="stintreset" style="align-self:center">Auto-fill all</button>';
  html+='<div class="meta" style="align-self:center;max-width:340px"><b>Each car can run a different session.</b> Pick its start below (or set a default for all). Clock = Brisbane. green=available, red !=not free, GAP=empty.</div>';
  html+='</div>';
  const classes=Object.keys(state.teams);
  if(!classes.length) return html+'<div class="meta">Hit Generate to build teams first.</div>';
  const summ=windowSummary(byId,len,race);
  const bestW=summ.slice().sort((a,b)=>(b.covered-a.covered)||(b.free-a.free))[0];
  html+='<div style="margin:2px 0 18px"><div class="meta" style="margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em">Session finder, click a window to set it as the default for all cars</div><div style="display:flex;gap:8px;flex-wrap:wrap">';
  summ.forEach(s=>{
    const sel=s.w===state.stint.window, isBest=bestW&&s.w===bestW.w;
    const bd=sel?'2px solid var(--yellow)':isBest?'1px solid var(--green)':'1px solid var(--line)';
    html+='<button data-action="stintwin" value="'+s.w+'" style="text-align:left;cursor:pointer;background:'+(sel?'rgba(240,240,0,.08)':'var(--panel)')+';border:'+bd+';border-radius:3px;padding:9px 12px;min-width:158px;color:var(--body);font-family:Karla">'
      +'<div style="font-family:Prompt;font-weight:600;color:#fff;text-transform:uppercase;font-size:12px">#'+s.w+' · '+fmtClock(s.off)+(isBest?' <span style="color:var(--green)">best</span>':'')+'</div>'
      +'<div class="meta">'+s.free+'/'+s.total+' drivers free (full race)</div>'
      +'<div class="meta">'+s.covered+'/'+s.cars+' cars coverable</div></button>';
  });
  html+='</div></div>';
  // driver bank — drag any name onto any stint block
  const bankByCls={}; Object.values(byId).forEach(d=>{(bankByCls[d._class]=bankByCls[d._class]||[]).push(d);});
  Object.keys(bankByCls).forEach(c=>bankByCls[c].sort((a,b)=>a.name.localeCompare(b.name)));
  html+='<div class="importbox" style="margin-bottom:16px"><div class="meta" style="margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em">Driver bank — drag a name onto any block. (P)=Pro (C)=Casual</div><div style="display:flex;flex-wrap:wrap;align-items:center;gap:2px">';
  Object.keys(bankByCls).sort().forEach(c=>{
    html+='<span class="meta" style="margin:0 4px">'+esc(c)+'</span>';
    html+=bankByCls[c].map(d=>'<span class="chip" data-bankchip data-driver="'+d.id+'" style="cursor:grab;user-select:none;touch-action:none">'+esc(d.name.split(' ')[0])+(d._tier==='pro'?' (P)':' (C)')+'</span>').join('');
  });
  html+='<span class="chip" data-bankchip data-driver="" style="cursor:grab;user-select:none;touch-action:none;color:var(--red);margin-left:8px">✕ empty</span></div></div>';
  classes.forEach(cls=>{
    [['pro','PRO','var(--gold)'],['casual','CASUAL','var(--steel)']].forEach(([tier,label,col])=>{
      const list=(state.teams[cls]&&state.teams[cls][tier])||[];
      list.forEach((team,ti)=>{
        const members=team.map(id=>byId[id]).filter(Boolean);
        if(!members.length) return;
        const key=cls+'|'+tier+'|'+ti;
        const win=state.stintWin[key]||state.stint.window;
        const off=START_OFFSETS[win]||0;
        const times=blockTimes(off,len,race);
        if(!state.stintAssign[key] || state.stintAssign[key].length!==times.length) state.stintAssign[key]=autoPlan(team,byId,times);
        const arr=state.stintAssign[key];
        const count={}; members.forEach(m=>count[m.id]=0); arr.forEach(id=>{ if(id!=null && count[id]!=null) count[id]++; });
        let gaps=0, conflicts=0;
        const cells=arr.map((id,i)=>{
          const t=times[i];
          const m=id!=null?byId[id]:null;
          const free=m?driverFree(m,t.s,t.e):false;
          if(id==null) gaps++; else if(!free) conflicts++;
          const bg=id==null?'var(--panel2)':(free?'rgba(95,211,138,.16)':'rgba(255,90,106,.20)');
          const lbl=m?(esc(m.name.split(' ')[0])+(free?'':' <span style="color:var(--red)">!</span>')):'<span style="color:var(--dim)">drop here</span>';
          return '<td data-stintcell data-key="'+key+'" data-block="'+i+'" data-driver="'+(id==null?'':id)+'" title="'+(m?(free?'available':'NOT available this block'):'empty')+'" style="padding:10px 12px;text-align:center;background:'+bg+';border:1px solid var(--line);white-space:nowrap;cursor:grab;user-select:none;touch-action:none">'+lbl+'</td>';
        }).join('');
        const badge = gaps? '<span class="meta" style="color:var(--red)">'+gaps+' empty</span>' : conflicts? '<span class="meta" style="color:var(--red)">'+conflicts+' conflict'+(conflicts>1?'s':'')+'</span>' : '<span class="meta" style="color:var(--green)">all covered</span>';
        const winSel='<select data-action="carwin" data-key="'+key+'">'+[1,2,3,4].map(w=>'<option value="'+w+'"'+(w===win?' selected':'')+'>start #'+w+' · '+START_LABELS[w]+'</option>').join('')+'</select>';
        const best=carBestWindow(team,byId,len,race);
        const curCovGaps=times.filter(t=>!members.some(m=>driverFree(m,t.s,t.e))).length;
        const bestHint=(best && best.gaps<curCovGaps)? '<span class="meta" style="color:var(--green);cursor:pointer;text-decoration:underline" data-action="usebest" data-key="'+key+'" data-win="'+best.w+'">better fit: #'+best.w+' '+START_LABELS[best.w]+' ('+(best.gaps?best.gaps+' gaps':'full cover')+'), use</span>' : '';
        html+='<div style="margin-bottom:22px"><div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap"><span class="swatch" style="background:'+col+'"></span><span class="head" style="letter-spacing:.04em;color:#fff">'+esc(cls)+' · '+label[0]+(ti+1)+'</span>'+winSel+badge+bestHint+'</div>';
        html+='<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:12px;min-width:100%"><tr><td style="padding:6px 10px;color:var(--dim)">Block</td>';
        times.forEach((t,i)=>{ html+='<td style="padding:6px 10px;text-align:center;color:var(--dim);white-space:nowrap;border-left:1px solid var(--line)">'+(i+1)+'<br><span style="font-size:9px">'+fmtClock(t.s)+'</span></td>'; });
        html+='</tr><tr><td style="padding:6px 10px;color:var(--dim)">Driver</td>'+cells+'</tr></table></div>';
        const cnt={}; arr.forEach(id=>{ if(id!=null) cnt[id]=(cnt[id]||0)+1; });
        const summ2=Object.keys(cnt).map(id=>esc((byId[id]?byId[id].name.split(' ')[0]:'?'))+': '+cnt[id]+'×'+state.stint.len+'m').join('  ·  ');
        html+='<div class="meta" style="margin-top:7px">Drag from the bank above onto a block, or drag a block onto another to swap.  '+(summ2?'&nbsp; Stints: '+summ2:'')+'</div></div>';
      });
    });
  });
  return html;
}

function renderContent(){
  const model=computeModel();
  const byId={}; Object.keys(model).forEach(c=>model[c].forEach(d=>byId[d.id]=d));
  renderSummary(model);
  const el=document.getElementById('content');
  el.innerHTML = state.tab==='setup' ? renderSetup() : state.tab==='teams' ? renderTeams(byId) : state.tab==='stints' ? renderStints(byId) : renderDrivers(model);
}

function setStatus(t){ const s=document.getElementById('status'); s.textContent=t; if(t) setTimeout(()=>{if(s.textContent===t)s.textContent='';},3000); }

function syncControls(){
  document.getElementById('pace').value=state.w.pace; document.getElementById('lpace').textContent=state.w.pace;
  document.getElementById('clean').value=state.w.clean; document.getElementById('lclean').textContent=state.w.clean;
  document.getElementById('prep').value=state.w.prep; document.getElementById('lprep').textContent=state.w.prep;
  document.getElementById('propct').value=state.proPct; document.getElementById('lpro').textContent=state.proPct;
}

// ---- wire up ----
['pace','clean','prep'].forEach(k=>{
  document.getElementById(k).addEventListener('input',e=>{
    state.w[k]=+e.target.value; document.getElementById('l'+k).textContent=state.w[k]; save(); renderContent();
  });
});
document.getElementById('propct').addEventListener('input',e=>{
  state.proPct=+e.target.value; document.getElementById('lpro').textContent=state.proPct; save(); renderContent();
});
document.getElementById('gen').addEventListener('click',()=>{ generate(); state.tab='teams'; setActiveTab(); save(); renderContent(); });
document.getElementById('reset').addEventListener('click',()=>{ seed(SAMPLE); state.w={pace:50,clean:30,prep:20}; state.proPct=40; syncControls(); generate(); save(); renderContent(); setStatus('Reset to sample'); });
document.getElementById('imp').addEventListener('click',()=>{ document.getElementById('importbox').classList.toggle('hide'); });
document.getElementById('load').addEventListener('click',()=>{
  try{
    const arr=JSON.parse(document.getElementById('importtext').value);
    if(!Array.isArray(arr)||!arr.length) throw new Error('expected a non-empty list');
    let id=1;
    state.drivers=arr.map(r=>({id:id++,name:String(r.name||('Driver '+id)),cars:(r.cars&&typeof r.cars==='object')?r.cars:{},assignedCar:r.cars?Object.keys(r.cars)[0]:undefined,avail:r.avail||null}));
    generate(); save(); document.getElementById('importbox').classList.add('hide'); renderContent();
    setStatus('Imported '+state.drivers.length+' drivers');
  }catch(e){ setStatus('Import failed: '+e.message); }
});

document.querySelectorAll('.tab').forEach(t=>t.addEventListener('click',()=>{ state.tab=t.dataset.tab; setActiveTab(); renderContent(); }));
function setActiveTab(){ document.querySelectorAll('.tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===state.tab)); }

document.getElementById('content').addEventListener('change',e=>{
  const a=e.target.dataset.action;
  if(a==='assign'){ const d=state.drivers.find(x=>x.id==e.target.dataset.id); if(d){ d.assignedCar=e.target.value; save(); renderContent(); } }
  else if(a==='move'){
    const id=+e.target.dataset.id, p=e.target.value.split('|'), tc=p[0], tt=p[1], tj=+p[2];
    Object.keys(state.teams).forEach(c=>['pro','casual'].forEach(t=>{ if(state.teams[c][t]) state.teams[c][t]=state.teams[c][t].map(car=>car.filter(x=>x!==id)); }));
    if(state.teams[tc] && state.teams[tc][tt] && state.teams[tc][tt][tj]) state.teams[tc][tt][tj].push(id);
    save(); renderContent();
  }
  else if(a==='stintwin'){ state.stint.window=+e.target.value; state.stintWin={}; state.stintAssign={}; save(); renderContent(); }
  else if(a==='carwin'){ const k=e.target.dataset.key; state.stintWin[k]=+e.target.value; delete state.stintAssign[k]; save(); renderContent(); }
  else if(a==='stintlen'){ state.stint.len=Math.max(10,+e.target.value||60); save(); renderContent(); }
  else if(a==='stintrace'){ state.stint.race=Math.max(30,+e.target.value||360); save(); renderContent(); }
});
document.getElementById('content').addEventListener('click',e=>{
  const winBtn=e.target.closest&&e.target.closest('button[data-action="stintwin"]');
  if(winBtn){ state.stint.window=+winBtn.value; state.stintWin={}; state.stintAssign={}; save(); renderContent(); return; }
  if(e.target.dataset.action==='remove'){ state.drivers=state.drivers.filter(x=>x.id!=e.target.dataset.id); generate(); save(); renderContent(); }
  else if(e.target.dataset.action==='stintreset'){ state.stintAssign={}; state.stintSig=''; save(); renderContent(); }
  else if(e.target.dataset.action==='usebest'){ const k=e.target.dataset.key; state.stintWin[k]=+e.target.dataset.win; delete state.stintAssign[k]; save(); renderContent(); }
  else if(e.target.dataset.action==='addcar'){ const c=e.target.dataset.class, t=e.target.dataset.tier||'pro'; if(!state.teams[c])state.teams[c]={pro:[],casual:[]}; state.teams[c][t].push([]); save(); renderContent(); }
  else if(e.target.dataset.action==='delcar'){ const c=e.target.dataset.class,t=e.target.dataset.tier,j=+e.target.dataset.idx; if(state.teams[c]&&state.teams[c][t]&&state.teams[c][t][j]&&!state.teams[c][t][j].length){ state.teams[c][t].splice(j,1); save(); renderContent(); } }
});

// ---- custom pointer drag (works in embedded previews where native drag-and-drop does not) ----
// Drag a bank chip onto a block to assign anyone; drag a block onto another to swap/move.
let _pd=null,_ghost=null;
const _stintContent=document.getElementById('content');
function _drvName(id){ const d=state.drivers.find(x=>x.id==id); return d?d.name.split(' ')[0]:'?'; }
function _ghostAt(x,y){ if(_ghost) _ghost.style.transform='translate('+(x+12)+'px,'+(y+12)+'px)'; }
_stintContent.addEventListener('pointerdown',e=>{
  const chip=e.target.closest&&e.target.closest('[data-bankchip]');
  const cell=e.target.closest&&e.target.closest('[data-stintcell]');
  if(chip){ _pd={kind:'bank',driver:chip.dataset.driver}; }
  else if(cell){ if(cell.dataset.driver==='') return; _pd={kind:'cell',key:cell.dataset.key,block:+cell.dataset.block,driver:cell.dataset.driver}; }
  else return;
  e.preventDefault();
  _ghost=document.createElement('div');
  _ghost.textContent=_pd.driver===''?'clear':_drvName(_pd.driver);
  _ghost.style.cssText='position:fixed;left:0;top:0;z-index:99999;pointer-events:none;background:var(--yellow);color:#0a0a0a;font-family:Prompt,sans-serif;font-weight:700;font-size:12px;padding:4px 9px;border-radius:2px';
  document.body.appendChild(_ghost); _ghostAt(e.clientX,e.clientY);
});
document.addEventListener('pointermove',e=>{ if(_pd) _ghostAt(e.clientX,e.clientY); });
document.addEventListener('pointerup',e=>{
  if(_pd){
    const el=document.elementFromPoint(e.clientX,e.clientY);
    const cell=el&&el.closest&&el.closest('[data-stintcell]');
    if(cell){ const k=cell.dataset.key,b=+cell.dataset.block,a=state.stintAssign[k];
      if(a){
        if(_pd.kind==='bank'){ a[b]=_pd.driver===''?null:(+_pd.driver); save(); renderContent(); }
        else if(k===_pd.key){ const tmp=a[b]; a[b]=a[_pd.block]; a[_pd.block]=tmp; save(); renderContent(); }
        else { a[b]=+_pd.driver; save(); renderContent(); }
      }
    }
  }
  if(_ghost){ _ghost.remove(); _ghost=null; } _pd=null;
});



/* ===== EDR Team Builder — data import (WordPress plugin) ===== */
const API=(window.EDR_TB&&EDR_TB.root)||''; const NONCE=(window.EDR_TB&&EDR_TB.nonce)||'';
const H={'X-WP-Nonce':NONCE,'Content-Type':'application/json'};
function apiGET(p){return fetch(API+p,{headers:{'X-WP-Nonce':NONCE}}).then(r=>r.json());}
function apiPOST(p,b){return fetch(API+p,{method:'POST',headers:H,body:JSON.stringify(b)}).then(r=>r.json());}
const CAN=!!(window.EDR_TB&&EDR_TB.can_edit);
let lastTrackIds=[], _saveT=null;
function serializePlan(){ return {drivers:state.drivers,w:state.w,proPct:state.proPct,teams:state.teams,stint:state.stint,stintAssign:state.stintAssign,stintWin:state.stintWin,stintSig:state.stintSig,overrides:overrides,meta:IMPORT_META,winStart:WIN_START_MS,startOffsets:START_OFFSETS,startLabels:START_LABELS,matches:lastMatches,trackIds:lastTrackIds}; }
function save(){ if(!CAN) return; clearTimeout(_saveT); _saveT=setTimeout(function(){ try{ apiPOST('plan',{plan:serializePlan()}); }catch(e){} }, 600); }
function loadPlan(){ return apiGET('plan').then(function(r){ var p=r&&r.plan; if(!p||!p.drivers||!p.drivers.length) return false;
  state.drivers=p.drivers; state.w=p.w||state.w; state.proPct=(typeof p.proPct==='number')?p.proPct:state.proPct; state.teams=p.teams||{};
  state.stint=Object.assign(state.stint,p.stint||{}); state.stintAssign=p.stintAssign||{}; state.stintWin=p.stintWin||{}; state.stintSig=p.stintSig||'';
  if(p.overrides)overrides=p.overrides; if(p.meta)IMPORT_META=p.meta; if(p.winStart)WIN_START_MS=p.winStart;
  if(p.startOffsets&&Object.keys(p.startOffsets).length)START_OFFSETS=p.startOffsets; if(p.startLabels&&Object.keys(p.startLabels).length)START_LABELS=p.startLabels;
  lastMatches=p.matches||[]; lastTrackIds=p.trackIds||[]; return true; }).catch(function(){return false;}); }
let SEL_SURVEY=0, SEL_TRACK=0;
function preselectNearest(){
  if(EVENTS&&EVENTS.length){ var now=Date.now(),best=0,bd=Infinity; EVENTS.forEach(function(e){var t=Date.parse(e.start_time);if(!t)return;var d=Math.abs(t-now);if(d<bd){bd=d;best=e.id;}}); SEL_SURVEY=best; }
  SEL_TRACK=(lastTrackIds&&lastTrackIds[0]) || (TRACKS&&TRACKS.length?TRACKS[0].id:0);
}
const norm=s=>String(s||'').toLowerCase().replace(/[^a-z]/g,'');
function classOfCar(c){const n=String(c).toUpperCase();if(/GTP|HYBRID|LMDH/.test(n))return'GTP';if(/LMP2|P217|LMP/.test(n))return'LMP2';if(/GT4/.test(n))return'GT4';if(/GT3/.test(n))return'GT3';return'Other';}
const OVR_KEY='edrTB_overrides';
let overrides=(function(){try{return JSON.parse(localStorage.getItem(OVR_KEY))||{};}catch(e){return{};}})();
function saveOverrides(){try{localStorage.setItem(OVR_KEY,JSON.stringify(overrides));}catch(e){}}
let TRACKS=null, EVENTS=null, lastPayload=null, lastScrape=null, setupMsg='';
let IMPORT_META={window_min:1980,race_min:360,candidate_starts:[]};

function setSetupMsg(t){ setupMsg=t; if(state.tab==='setup') renderContent(); }
function computeAvail(windows){
  const wm=IMPORT_META.window_min||1980, race=IMPORT_META.race_min||360;
  const total=(windows||[]).reduce((a,w)=>a+(w[1]-w[0]),0);
  const starts=(IMPORT_META.candidate_starts||[]).filter(c=>(windows||[]).some(w=>w[0]<=c.offset&&w[1]>=c.offset+race)).map(c=>c.n);
  return {hours:Math.round(total/60*10)/10, pct:wm?Math.round(total/wm*100):0, starts, windows:windows||[]};
}
function findBySlug(roster,slug){return (roster||[]).find(r=>norm(r.name)===norm(slug));}
function buildCars(r,prefsStr){
  const cars={};
  if(r){ Object.entries(r.cars).filter(([c])=>['GTP','LMP2','GT3','GT4'].includes(classOfCar(c))).sort((a,b)=>b[1].laps-a[1].laps).forEach(([c,s])=>cars[c]=s); }
  String(prefsStr||'').split(',').map(x=>x.split(' +')[0].trim()).filter(x=>x&&!/^\d+$/.test(x)).forEach(p=>{ if(!cars[p]&&['GTP','LMP2','GT3','GT4'].includes(classOfCar(p))) cars[p]={laps:0,medianLap:null,cleanPct:0}; });
  if(r){ Object.entries(r.cars).forEach(([c,s])=>{ if(!cars[c]) cars[c]=s; }); }
  return cars;
}
let lastMatches=[];
function applyImport(payload, scrape){
  IMPORT_META={window_min:payload.window_min||1980, race_min:payload.race_min||360, candidate_starts:payload.candidate_starts||[]};
  if(payload.window_start) WIN_START_MS=Date.parse(payload.window_start);
  START_OFFSETS={}; START_LABELS={}; (payload.candidate_starts||[]).forEach(c=>{ START_OFFSETS[c.n]=c.offset; START_LABELS[c.n]=(c.iso||'').slice(11,16)+'Z'; });
  if(!Object.keys(START_OFFSETS).length){ START_OFFSETS={1:0,2:540,3:840,4:1080,5:1560}; START_LABELS={1:'22:00Z',2:'07:00Z',3:'12:00Z',4:'16:00Z',5:'00:00Z'}; }
  const g61={}; (payload.roster||[]).forEach(r=>{ g61[norm(r.name)]=r; });
  let availList=[];
  if(scrape&&scrape.length){ availList=scrape.map(d=>({name:d.name, prefs:d.cars||'', windows: d.windows_min ? d.windows_min : (d.windows_frac ? d.windows_frac.map(w=>[Math.round(w[0]*IMPORT_META.window_min), Math.round(w[1]*IMPORT_META.window_min)]) : (d.windows||[])) })); }
  else { availList=(payload.availability||[]).map(d=>({name:d.name,windows:d.windows_min||[],prefs:''})); }
  const drivers=[]; const matches=[]; let id=1;
  if(availList.length){
    availList.forEach(a=>{
      const slug=overrides[a.name] || (g61[norm(a.name)]?g61[norm(a.name)].name:null);
      const r=slug?(g61[norm(slug)]||findBySlug(payload.roster,slug)):null;
      matches.push({irp:a.name, g61:r?r.name:null});
      drivers.push({id:id++, name:a.name, cars:buildCars(r,a.prefs), assignedCar:Object.keys(buildCars(r,a.prefs))[0], avail:computeAvail(a.windows)});
    });
  } else {
    (payload.roster||[]).forEach(r=>{ drivers.push({id:id++, name:r.name, cars:r.cars, assignedCar:Object.keys(r.cars)[0], avail:null}); });
  }
  lastMatches=matches; state.drivers=drivers; state.stintAssign={}; state.stintSig=''; state.stintWin={};
  generate(); save();
}

async function doImport(trackIds, surveyId){
  lastTrackIds=trackIds; setSetupMsg('Pulling Garage 61 + iRacePlan...');
  try{
    const payload=await apiPOST('import',{trackIds:trackIds,surveyId:surveyId});
    if(payload.code){ setSetupMsg('Error: '+(payload.message||payload.code)); return; }
    lastPayload=payload; lastScrape=null;
    applyImport(payload,null);
    if(payload.needs_availability){ setSetupMsg('Pace imported. No iRacePlan planning yet, so run the availability bookmarklet on the survey page and paste below to add availability.'); }
    else { setSetupMsg('Imported '+state.drivers.length+' drivers with availability.'); }
    state.tab='setup'; setActiveTab(); renderContent();
  }catch(e){ setSetupMsg('Import failed: '+e.message); }
}
function applyPaste(txt){
  try{ const scrape=JSON.parse(txt); if(!Array.isArray(scrape)||!scrape.length) throw new Error('expected a non-empty list'); if(!lastPayload){ setSetupMsg('Import pace first, then paste availability.'); return; } lastScrape=scrape; applyImport(lastPayload,scrape); setSetupMsg('Merged availability for '+scrape.length+' drivers.'); renderContent(); }
  catch(e){ setSetupMsg('Paste failed: '+e.message); }
}

function renderSetup(){
  let h='<div class="edr-setup">';
  h+='<div class="importbox" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">';
  h+='<div class="ctl"><label>TRACK (Garage 61)</label><select data-s="track">'+(TRACKS?TRACKS.map(t=>'<option value="'+t.id+'"'+(t.id===SEL_TRACK?' selected':'')+'>'+esc(t.name+(t.variant?' - '+t.variant:''))+'</option>').join(''):'<option>loading...</option>')+'</select></div>';
  h+='<div class="ctl"><label>EVENT (iRacePlan) <span style="color:var(--green)">nearest auto-selected</span></label><select data-s="event"><option value=""'+(SEL_SURVEY?'':' selected')+'>(none, pace only)</option>'+(EVENTS?EVENTS.map(e=>'<option value="'+e.id+'"'+(e.id===SEL_SURVEY?' selected':'')+'>'+esc(e.title)+(e.responses!=null?' ('+e.responses+'/'+e.drivers+')':'')+'</option>').join(''):'')+'</select></div>';
  h+='<button class="btn btn-amber" data-s="import">Import / Refresh now</button>';
  h+='<span class="meta" style="align-self:center;max-width:360px">'+esc(setupMsg||'This is the shared team plan, saved for everyone. Nearest event is auto-selected. Pick a track, then Import / Refresh.')+'</span>';
  h+='</div>';
  // availability paste (fallback)
  h+='<div class="importbox"><div class="meta" style="margin-bottom:6px">AVAILABILITY (survey phase): run the iRacePlan bookmarklet on the logged-in survey page, then paste here.</div>';
  h+='<textarea data-s="paste" style="width:100%;min-height:70px;padding:8px;font-size:12px" placeholder=\'[{"name":"...","cars":"Dallara P217, ...","windows_min":[[0,1980]]}]\'></textarea>';
  h+='<button class="btn btn-green" data-s="dopaste" style="margin-top:8px">Merge availability</button></div>';
  // match review
  if(lastMatches&&lastMatches.length){
    h+='<div class="importbox edr-match"><div class="meta" style="margin-bottom:6px">NAME MATCHES (iRacePlan &rarr; Garage 61). Fix any wrong/blank ones:</div>';
    const slugs=(lastPayload&&lastPayload.roster||[]).map(r=>r.name);
    lastMatches.forEach(m=>{
      h+='<div style="display:flex;gap:8px;align-items:center;margin-bottom:4px"><span style="min-width:170px">'+esc(m.irp)+'</span><span style="color:var(--dim)">&rarr;</span>'
        +'<select data-s="ovr" data-irp="'+esc(m.irp)+'"><option value="">(no pace match)</option>'+slugs.map(s=>'<option value="'+esc(s)+'"'+(norm(s)===norm(m.g61||'')?' selected':'')+'>'+esc(s)+'</option>').join('')+'</select>'
        +(m.g61?'':' <span style="color:var(--red)">unmatched</span>')+'</div>';
    });
    h+='</div>';
  }
  h+='</div>';
  return h;
}

/* delegated handlers for setup controls */
document.getElementById('content').addEventListener('change',function(e){
  const s=e.target.dataset.s;
  if(s==='ovr'){ const irp=e.target.dataset.irp; if(e.target.value) overrides[irp]=e.target.value; else delete overrides[irp]; saveOverrides(); if(lastPayload) applyImport(lastPayload,lastScrape); renderContent(); }
});
document.getElementById('content').addEventListener('click',function(e){
  const s=e.target.dataset.s;
  if(s==='import'){ const tEl=document.querySelector('[data-s=track]'); const eEl=document.querySelector('[data-s=event]'); doImport([parseInt(tEl.value,10)], eEl.value?parseInt(eEl.value,10):0); }
  else if(s==='dopaste'){ const p=document.querySelector('[data-s=paste]'); applyPaste(p.value); }
});

async function bootSetup(){
  if(!CAN){ _app.classList.add('readonly'); }
  syncControls();
  state.tab = CAN ? 'setup' : 'teams'; setActiveTab();
  var ok=false; try{ ok=await loadPlan(); }catch(e){}
  if(ok && state.drivers.length && !Object.keys(state.teams||{}).length) generate();
  renderContent();
  if(CAN){
    try{ TRACKS=await apiGET('tracks'); }catch(e){ TRACKS=[]; }
    try{ EVENTS=await apiGET('events'); }catch(e){ EVENTS=[]; }
    preselectNearest();
    if(state.tab==='setup') renderContent();
  }
}

// ---- boot ----
document.getElementById('edr-tb-app').dataset.ready='1';
bootSetup();

})();
