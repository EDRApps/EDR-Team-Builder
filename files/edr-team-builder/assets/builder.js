/* EDR Team Builder (generated). Front-end logic adapted from EDR-Team-Builder.html. */
(function(){
const LOGO=(window.EDR_TB&&EDR_TB.logo)||'';
const APP_HTML=`
<div class="header">
  <img src="${LOGO}" alt="Endurotech Racing">
  <div>
    <div class="head" style="letter-spacing:.18em;font-size:11px;color:var(--yellow);text-transform:uppercase">Endurotech Racing</div>
    <h1 style="font-size:26px;margin:2px 0;letter-spacing:.04em;text-transform:uppercase;color:#fff">Endurance Team Builder</h1>
    <div id="evheading" style="margin-top:2px"></div>
    <div class="meta" id="summary" style="display:flex;gap:16px;flex-wrap:wrap;margin-top:4px"></div>
  </div>
  <div id="rolebar" style="margin-left:auto;display:flex;gap:10px;align-items:center"></div>
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
  <button class="tab" data-tab="event">Event</button>
  <button class="tab" data-tab="availability">Availability</button>
  <button class="tab" data-tab="drivers">Drivers</button>
  <button class="tab" data-tab="teams">Teams</button>
  <button class="tab" data-tab="stints">Stints</button>
</div>
<div class="wrap"><div class="importbox hide" id="importbox">
  <div class="meta" style="margin-bottom:8px">Paste a roster JSON (advanced / manual fallback), then Load.</div>
  <textarea id="importtext" placeholder='[{"name":"...","cars":{"Ferrari 296 GT3":{"laps":168,"medianLap":105.21,"cleanPct":0.91}}}]'></textarea>
  <button class="btn btn-green" id="load" style="margin-top:8px">Load</button>
</div><div id="content"></div></div>
`;
const _app=document.getElementById('edr-tb-app'); if(!_app){return;} _app.innerHTML=APP_HTML;

const KEY = 'edrTeamBuilder_spa_v14'; /* Spa 24h — S3 pace, survey 2307 availability */

// --- Spa 24h event timing (for the Stints tab) ---
// Availability window starts 2026-07-10 22:00 UTC (= 11 Jul 08:00 Brisbane). All offsets in minutes from there.
// 4 candidate start slots (iRacePlan survey 2307 session_times).
let WIN_START_MS = Date.parse('2026-07-10T22:00:00Z');
let START_OFFSETS = {1:0, 2:540, 3:840, 4:1080};
let START_LABELS = {1:'Sat 08:00 · 22:00Z', 2:'Sat 17:00 · 07:00Z', 3:'Sat 22:00 · 12:00Z', 4:'Sun 02:00 · 16:00Z'};

// Spa 24h: Garage 61 pace (iRacing 2026 Season 3 only, from 2026-06-16; age=-1) for the 18 drivers in iRacePlan survey 2307.
// Availability merged from iRacePlan survey 2307 timeline (green = available). Empty cars = no Spa data shared.
const SAMPLE = [];

let state = { drivers:[], w:{pace:50,clean:30,prep:20}, proPct:40, tab:'event', teams:{}, stint:{window:1,len:120,race:1440}, stintAssign:{}, stintWin:{}, stintSig:'', evsel:null, role:'driver', me:'', pass:'', availStore:{}, evWinMin:0, evTiming:{} };

function fastestCar(cars){
  let best=null, bt=Infinity;
  Object.keys(cars||{}).forEach(c=>{ const m=cars[c]&&cars[c].medianLap; if(m!=null&&m<bt){ bt=m; best=c; } });
  return best||Object.keys(cars||{})[0];
}
function seed(list){
  let id=1;
  state.drivers = list.map(d => ({ id:id++, name:d.name, cars:d.cars||{}, assignedCar:fastestCar(d.cars), avail:d.avail||null }));
  seedSpaAvail();
}

function save(){ try{ localStorage.setItem(KEY, JSON.stringify({drivers:state.drivers,w:state.w,proPct:state.proPct,teams:state.teams,stint:state.stint,stintAssign:state.stintAssign,stintWin:state.stintWin,stintSig:state.stintSig,evsel:state.evsel,role:state.role,me:state.me,pass:state.pass,availStore:state.availStore,evTiming:state.evTiming,evWinMin:EV_WIN_MIN,winStart:WIN_START_MS,startOffsets:START_OFFSETS,startLabels:START_LABELS})); }catch(e){} }
function load(){
  try{
    const s = JSON.parse(localStorage.getItem(KEY));
    if(s && s.drivers && s.drivers.length){
      state.drivers=s.drivers; state.w=s.w||state.w; state.proPct=(typeof s.proPct==='number')?s.proPct:state.proPct; state.teams=s.teams||{}; state.stint=Object.assign(state.stint, s.stint||{}); state.stintAssign=s.stintAssign||{}; state.stintWin=s.stintWin||{}; state.stintSig=s.stintSig||'';
      state.evsel=s.evsel||null; state.role=s.role||'driver'; state.me=s.me||''; state.pass=s.pass||''; state.availStore=s.availStore||{}; state.evTiming=s.evTiming||{};
      if(s.evWinMin) EV_WIN_MIN=s.evWinMin;
      if(s.winStart) WIN_START_MS=s.winStart;
      if(s.startOffsets&&Object.keys(s.startOffsets).length){ START_OFFSETS=s.startOffsets; START_LABELS=s.startLabels||START_LABELS; }
      /* migrate pre-2.1 saves: no event selected + availability living only on the driver
         objects (survey windows). Default to Spa and fold the windows into the per-event
         store so the pool/stints keep working without re-ticking anything. */
      if(!state.evsel) state.evsel='Spa 24HR|2026-07-10';
      const st=state.availStore[state.evsel]=state.availStore[state.evsel]||{};
      state.drivers.forEach(d=>{ if(d.avail&&d.avail.windows&&d.avail.windows.length&&!(st[d.name]&&st[d.name].length)) st[d.name]=windowsToSlots(d.avail.windows); });
      return true;
    }
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

/* with an event selected, the pool is drivers with availability for THAT event
   (hours > 0) — same rule as the old survey flow. No event = everyone. */
function eventPool(){
  if(!state.evsel) return state.drivers;
  return state.drivers.filter(d=>d.avail && d.avail.hours>0);
}
function computeModel(){
  const enriched = eventPool().map(d=>{
    const car = (d.cars && d.cars[d.assignedCar]) ? d.assignedCar : fastestCar(d.cars);
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

/* ===== Season calendar =====
   iRacing specials + endurance events (team calendar + iRacePlan surveys). Edit here to
   add or correct events. target = special OR (endurance AND dur>=3h). Optional per-event
   overrides: winStart (ISO — when the availability window opens), winMin (window length,
   minutes), offsets/labels (candidate race starts), raceMin (race length, minutes). */
const CAL_EVENTS=[
  {n:"iRacing ROAR", s:"2026-01-09", e:"2026-01-10", track:"Daytona International Speedway", cars:"LMP3 // GT4 // Touring Cars", cat:"other"},
  {n:"Daytona 24", s:"2026-01-16", e:"2026-01-18", track:"Daytona International Speedway", cars:"GTP & HYP // LMP2 // GT3", cat:"endurance", dur:24, special:true, g61Tracks:[105]},
  {n:"Daytona 500", s:"2026-02-11", e:"2026-02-18", track:"Daytona International Speedway", cars:"NASCAR Cup Series", cat:"nascar", special:true},
  {n:"Bathurst 12 Hour", s:"2026-02-20", e:"2026-02-22", track:"Mount Panorama Circuit", cars:"GT3", cat:"endurance", dur:12, special:true, g61Tracks:[79]},
  {n:"NEC Round 1", s:"2026-03-21", e:"2026-03-22", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"1 / 10", dur:4, g61Tracks:[94]},
  {n:"Sebring 12HR", s:"2026-03-27", e:"2026-03-29", track:"Sebring International Raceway", cars:"GTP & HYP // LMP2 // GT3", cat:"endurance", dur:12, special:true, g61Tracks:[35]},
  {n:"NEC Round 2", s:"2026-04-04", e:"2026-04-05", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"2 / 10", dur:4, g61Tracks:[94]},
  {n:"IMSA Classic 500", s:"2026-04-10", e:"2026-04-11", track:"WeatherTech Raceway Laguna Seca", cars:"Nissan GTP // Audi 90", cat:"endurance", dur:4, g61Tracks:[504, 34]},
  {n:"NEC Round 3", s:"2026-04-25", e:"2026-04-26", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"3 / 10", dur:4, g61Tracks:[94]},
  {n:"Nürburgring 24h", s:"2026-05-01", e:"2026-05-03", track:"Nürburgring Combined", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", dur:24, special:true, g61Tracks:[93]},
  {n:"INDY 500", s:"2026-05-05", e:"2026-05-18", track:"Indianapolis Motor Speedway", cars:"Dallara IR-18 INDYCAR", cat:"other", special:true},
  {n:"World 600", s:"2026-05-20", e:"2026-05-25", track:"Charlotte Motor Speedway", cars:"NASCAR Cup Series", cat:"nascar", special:true},
  {n:"NEC Round 4", s:"2026-05-23", e:"2026-05-24", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"4 / 10", dur:4, g61Tracks:[94]},
  {n:"4 Hours at Thruxton", s:"2026-05-29", e:"2026-05-31", track:"Thruxton Circuit", cars:"Touring Cars", cat:"endurance", dur:4, g61Tracks:[453]},
  {n:"NEC Round 5", s:"2026-06-06", e:"2026-06-07", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"5 / 10", dur:4, g61Tracks:[94]},
  {n:"Creventic Round 1 — 12H Mugello", s:"2026-06-13", e:"2026-06-14", track:"Autodromo Internazionale del Mugello", cars:"GT3 // Cup // GT4", cat:"endurance", series:"Creventic", round:"1 / 5", dur:12, g61Tracks:[418]},
  {n:"Watkins Glen 6 Hour", s:"2026-06-19", e:"2026-06-21", track:"Watkins Glen International", cars:"GTP & HYP // LMP2 // GT3", cat:"endurance", dur:6, special:true, g61Tracks:[324]},
  {n:"Dale Jr Charity Event", s:"2026-06-25", e:"2026-06-25", track:"Iowa Speedway", cars:"TBD", cat:"other", tbd:true},
  {n:"Firecracker 400", s:"2026-06-30", e:"2026-07-06", track:"Daytona International Speedway", cars:"1987 NASCAR Cup (historic)", cat:"other", special:true, g61Tracks:[39]},
  {n:"NEC Round 6", s:"2026-07-04", e:"2026-07-05", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"6 / 10", dur:4, g61Tracks:[94]},
  {n:"Spa 24HR", s:"2026-07-10", e:"2026-07-12", track:"Circuit de Spa-Francorchamps", cars:"GT3", cat:"endurance", dur:24, special:true,
    winStart:"2026-07-10T22:00:00Z", winMin:2568, raceMin:1440, g61Tracks:[444,446],
    offsets:{1:0,2:540,3:840,4:1080}, labels:{1:"Sat 08:00 · 22:00Z",2:"Sat 17:00 · 07:00Z",3:"Sat 22:00 · 12:00Z",4:"Sun 02:00 · 16:00Z"}},
  {n:"Global Endurance Tour", s:"2026-07-11", e:"2026-07-12", track:"TBD", cars:"GT3 // GTP", cat:"endurance", dur:6},
  {n:"Creventic Round 2 — 12H Spa", s:"2026-07-18", e:"2026-07-19", track:"Circuit de Spa-Francorchamps", cars:"GT3 // Cup // GT4", cat:"endurance", series:"Creventic", round:"2 / 5", dur:12, g61Tracks:[444, 446]},
  {n:"Brickyard 400", s:"2026-07-22", e:"2026-07-27", track:"Indianapolis Motor Speedway", cars:"NASCAR Cup Series", cat:"nascar", special:true},
  {n:"6 Hours of Road America", s:"2026-07-24", e:"2026-07-26", track:"Road America", cars:"GTP & HYP // LMP2 // GT3", cat:"endurance", dur:6, special:true, g61Tracks:[49]},
  {n:"Knoxville Nationals", s:"2026-08-04", e:"2026-08-09", track:"Knoxville Raceway", cars:"410 Winged Sprint Cars", cat:"other"},
  {n:"NEC Round 7", s:"2026-08-08", e:"2026-08-09", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"7 / 10", dur:4, g61Tracks:[94]},
  {n:"Portimao 1000", s:"2026-08-14", e:"2026-08-15", track:"Algarve International Circuit", cars:"HPD // GT1 // GT2", cat:"endurance", dur:6, g61Tracks:[425]},
  {n:"Creventic Round 3 — 12H Barcelona", s:"2026-08-15", e:"2026-08-16", track:"Circuit de Barcelona-Catalunya", cars:"GT3 // Cup // GT4", cat:"endurance", series:"Creventic", round:"3 / 5", dur:12, g61Tracks:[76]},
  {n:"Crandon Championship", s:"2026-08-25", e:"2026-08-30", track:"Crandon International Raceway", cars:"Pro 4 Off-Road Truck", cat:"other"},
  {n:"NEC Round 8", s:"2026-08-29", e:"2026-08-30", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"8 / 10", dur:4, g61Tracks:[94]},
  {n:"Southern 500", s:"2026-09-02", e:"2026-09-07", track:"Darlington Raceway", cars:"NASCAR Cup Series", cat:"nascar", special:true},
  {n:"Suzuka 1000km", s:"2026-09-10", e:"2026-09-15", track:"Suzuka Circuit", cars:"GT3", cat:"endurance", dur:6, special:true, g61Tracks:[57]},
  {n:"Creventic Round 4 — 12H Nürburgring GP", s:"2026-09-12", e:"2026-09-13", track:"Nürburgring GP-Strecke", cars:"GT3 // Cup // GT4", cat:"endurance", series:"Creventic", round:"4 / 5", dur:12, g61Tracks:[66, 100]},
  {n:"Britcar 24HR", s:"2026-09-18", e:"2026-09-20", track:"Silverstone Circuit", cars:"GT3 // GT4", cat:"endurance", dur:24, g61Tracks:[80]},
  {n:"Petit Le Mans", s:"2026-09-25", e:"2026-09-27", track:"Road Atlanta", cars:"GTP // LMP2 // GT3", cat:"endurance", dur:10, special:true, g61Tracks:[40]},
  {n:"Creventic Round 5 — 24H Barcelona", s:"2026-09-26", e:"2026-09-27", track:"Circuit de Barcelona-Catalunya", cars:"GT3 // Cup // GT4", cat:"endurance", series:"Creventic", round:"5 / 5", dur:24, g61Tracks:[76]},
  {n:"Bathurst 1000", s:"2026-10-02", e:"2026-10-04", track:"Mount Panorama Circuit", cars:"Supercars", cat:"endurance", dur:6, special:true, g61Tracks:[79]},
  {n:"NEC Round 9", s:"2026-10-10", e:"2026-10-11", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"9 / 10", dur:4, g61Tracks:[94]},
  {n:"8 Hours of Indianapolis", s:"2026-10-16", e:"2026-10-18", track:"Indianapolis Motor Speedway", cars:"GT3", cat:"endurance", dur:8, special:true, g61Tracks:[380]},
  {n:"iRacing FF1600 Festival", s:"2026-10-30", e:"2026-10-31", track:"Brands Hatch", cars:"Ray FF1600", cat:"other"},
  {n:"Homestead Championship", s:"2026-11-04", e:"2026-11-09", track:"Homestead-Miami Speedway", cars:"NASCAR Cup Series", cat:"nascar", special:true},
  {n:"NEC Round 10", s:"2026-11-07", e:"2026-11-08", track:"Nürburgring Combined VLN", cars:"GT3 // Cup // GT4 // TCR // M2", cat:"endurance", series:"NEC", round:"10 / 10", dur:4, g61Tracks:[94]},
  {n:"SFL Mountain Showdown", s:"2026-11-13", e:"2026-11-15", track:"Mount Panorama Circuit", cars:"Super Formula Lights", cat:"other"},
  {n:"SCCA Runoffs", s:"2026-11-17", e:"2026-11-21", track:"Various / 6 classes", cars:"6 Classes", cat:"other"},
  {n:"992 Endurance Cup", s:"2026-11-27", e:"2026-11-29", track:"TBD", cars:"Porsche Cup 992.2", cat:"endurance", dur:3},
  {n:"Winter Derby", s:"2026-12-02", e:"2026-12-07", track:"Five Flags Speedway", cars:"Super Late Model", cat:"other"},
  {n:"Chili Bowl", s:"2026-12-15", e:"2026-12-20", track:"Tulsa Expo Center", cars:"Dirt Midget", cat:"other"},
  {n:"Production Car Challenge @ViR", s:"2026-12-18", e:"2026-12-19", track:"VIR Grand Course", cars:"Production Car Challenge cars", cat:"endurance", dur:2, g61Tracks:[394]}
];
const CAT_COLOR={endurance:'var(--yellow)', nascar:'var(--red)', other:'var(--steel)'};
function isTarget(ev){ return !!ev.special || (ev.cat==='endurance' && (ev.dur||0)>=3); }
function evKey(ev){ return ev.n+'|'+ev.s; }
function calEvent(key){ return CAL_EVENTS.find(e=>evKey(e)===key)||null; }
const _today=new Date(); _today.setHours(0,0,0,0);
function statusFor(ev){
  const s=new Date(ev.s+'T00:00:00'), e=new Date(ev.e+'T23:59:59');
  if(_today<s) return {label:Math.round((s-_today)/86400000), state:'upcoming'};
  if(_today>=s&&_today<=e) return {label:0,state:'live'};
  return {label:0,state:'past'};
}
function fmtDates(s,e){
  const o={month:'short',day:'numeric'}; const sd=new Date(s+'T00:00:00'), ed=new Date(e+'T00:00:00');
  const l=sd.toLocaleDateString('en-AU',o), r=ed.toLocaleDateString('en-AU', sd.getMonth()===ed.getMonth()?{day:'numeric'}:o);
  return s===e?l:l+'–'+r;
}

/* ===== Roles: driver (default, edits own availability only) vs admin (password / WP login) ===== */
function isAdmin(){ return state.role==='admin'; }
/* Admin password (standalone): change it by replacing ADMIN_HASH with _hash('yournewpassword')
   — run _hash('...') in the browser console. Current password: edr2026 */
const ADMIN_HASH='1rdbl5c';
function _hash(s){ let x=5381; for(let i=0;i<s.length;i++) x=(((x*33)>>>0)^s.charCodeAt(i))>>>0; return x.toString(36); }
function verifyAdminPass(p,cb){ cb(_hash(p)===ADMIN_HASH); }  /* WP build overrides: asks the server */
function onAdminUnlocked(){}  /* WP build overrides: loads the Setup tab's track/event lists */
/* inline password field (never window.prompt — that is blocked in sandboxed
   iframes/embeds and the Admin button would silently do nothing) */
let _unlockOpen=false, _unlockMsg='';
function unlockAdmin(){ _unlockOpen=true; _unlockMsg=''; renderRolebar(); const i=document.getElementById('adminpass'); if(i) i.focus(); }
function submitUnlock(){
  const i=document.getElementById('adminpass'); const p=(i&&i.value)||'';
  if(!p) return;
  verifyAdminPass(p, ok=>{
    if(ok){ _unlockOpen=false; _unlockMsg=''; state.role='admin'; state.pass=p; save(); renderRolebar(); onAdminUnlocked(); renderContent(); setStatus('Admin unlocked'); }
    else { _unlockMsg='wrong password'; renderRolebar(); const j=document.getElementById('adminpass'); if(j) j.focus(); }
  });
}
function lockAdmin(){ state.role='driver'; state.pass=''; _unlockOpen=false; save(); renderRolebar(); renderContent(); }
function renderRolebar(){
  const el=document.getElementById('rolebar'); if(!el) return;
  if(isAdmin()){
    el.innerHTML='<span class="rolechip admin">ADMIN</span><button class="btn btn-ghost" data-role-action="lock" style="font-size:10px;padding:4px 12px">Lock</button>';
  } else if(_unlockOpen){
    el.innerHTML='<input type="password" id="adminpass" placeholder="admin password" autocomplete="off" style="padding:7px 12px;font-size:12px;width:150px">'
      +'<button class="btn btn-amber" data-role-action="go" style="font-size:10px;padding:5px 13px">Unlock</button>'
      +'<button class="btn btn-ghost" data-role-action="cancel" style="font-size:10px;padding:5px 11px">✕</button>'
      +(_unlockMsg?'<span class="meta" style="color:var(--red)">'+_unlockMsg+'</span>':'');
  } else {
    el.innerHTML='<span class="rolechip">DRIVER</span><button class="btn btn-ghost" data-role-action="unlock" style="font-size:10px;padding:4px 12px">Admin</button>';
  }
}

/* ===== Event selection → timing (window, candidate starts, race length) ===== */
let EV_WIN_MIN=2568;  // availability-window length (min) for the selected event; Spa default
/* Format an absolute timestamp as "Sat 08:00 · 22:00Z" (Brisbane clock + UTC) — used for
   candidate-start labels so they read the same whichever event's window is loaded. */
function fmtStartLabel(ms){
  const bris=new Date(ms).toLocaleString('en-AU',{timeZone:'Australia/Brisbane',weekday:'short',hour:'2-digit',minute:'2-digit',hour12:false});
  const d=new Date(ms); const utc=String(d.getUTCHours()).padStart(2,'0')+':'+String(d.getUTCMinutes()).padStart(2,'0');
  return bris+' · '+utc+'Z';
}
/* Turn official iRacing session times into a per-event timing override (winStart,
   candidate starts, race length). Reproduces the hand-typed Spa values exactly. */
function applyIrTiming(ev, sea){
  const times=(sea.sessions||[]).map(t=>Date.parse(t)).filter(x=>!isNaN(x)).sort((a,b)=>a-b);
  if(!times.length) return false;
  const winStart=times[0], offsets={}, labels={};
  times.slice(0,6).forEach((t,i)=>{ offsets[i+1]=Math.round((t-winStart)/60000); labels[i+1]=fmtStartLabel(t); });
  const raceMin=sea.race_min||state.stint.race||360;
  const maxOff=Math.max.apply(null,Object.values(offsets));
  // availability window spans the first start to the last candidate race end (we have exact
  // times here, so don't pad to calendar days); respect a larger hand-typed winMin if set
  const winMin=Math.max(maxOff+raceMin, ev.winMin||0);
  state.evTiming[evKey(ev)]={winStart:new Date(winStart).toISOString(), raceMin:raceMin, offsets:offsets, labels:labels, winMin:winMin, src:sea.name||'iRacing'};
  return true;
}
function applyEventTiming(ev0){
  const ovr=state.evTiming&&state.evTiming[evKey(ev0)];
  const ev=ovr?Object.assign({},ev0,ovr):ev0;
  WIN_START_MS = ev.winStart ? Date.parse(ev.winStart) : Date.parse(ev.s+'T00:00:00+10:00');
  const days=Math.round((Date.parse(ev.e+'T00:00:00Z')-Date.parse(ev.s+'T00:00:00Z'))/86400000)+1;
  EV_WIN_MIN = ev.winMin || days*1440;
  const raceMin = ev.raceMin || Math.min((ev.dur||6)*60, EV_WIN_MIN);
  state.stint.race = raceMin;
  if(ev.offsets){ START_OFFSETS=Object.assign({},ev.offsets); START_LABELS=Object.assign({},ev.labels||{}); }
  else{
    START_OFFSETS={}; START_LABELS={};
    const maxOff=Math.max(0, EV_WIN_MIN-raceMin);
    const step=Math.max(240, Math.round(maxOff/180)*60||240);
    let n=1;
    for(let off=0; off<=maxOff && n<=4; off+=step){ START_OFFSETS[n]=off; START_LABELS[n]=fmtClock(off); n++; }
    if(!Object.keys(START_OFFSETS).length){ START_OFFSETS={1:0}; START_LABELS={1:fmtClock(0)}; }
  }
  if(!START_OFFSETS[state.stint.window]) state.stint.window=+Object.keys(START_OFFSETS)[0];
}
function selectEvent(key){
  const ev=calEvent(key); if(!ev||!isTarget(ev)) return;
  state.evsel=key;
  applyEventTiming(ev);
  state.stintAssign={}; state.stintWin={}; state.stintSig='';
  applyAvailToDrivers();
  save(); state.tab='availability'; setActiveTab(); renderContent();
}

/* ===== Availability: 4h blocks over the event window, converted to the
   {hours, pct, starts, windows} shape the scoring and stints already use ===== */
const AV_BLOCK=240;
function evSlots(){ return Math.max(1, Math.ceil(EV_WIN_MIN/AV_BLOCK)); }
function slotsToAvail(slots){
  const set=new Set(slots||[]); const windows=[]; let run=null; const n=evSlots();
  for(let i=0;i<n;i++){
    if(!set.has(i)){ run=null; continue; }
    const s=i*AV_BLOCK, e=Math.min((i+1)*AV_BLOCK, EV_WIN_MIN);
    if(run && run[1]===s){ run[1]=e; } else { run=[s,e]; windows.push(run); }
  }
  const total=windows.reduce((a,w)=>a+(w[1]-w[0]),0);
  const race=Math.max(30,state.stint.race);
  const starts=Object.keys(START_OFFSETS).filter(k=>windows.some(w=>w[0]<=START_OFFSETS[k]&&w[1]>=START_OFFSETS[k]+race)).map(Number);
  return {hours:Math.round(total/6)/10, pct:EV_WIN_MIN?Math.round(total/EV_WIN_MIN*100):0, starts, windows};
}
function applyAvailToDrivers(){
  if(!state.evsel) return;
  const a=state.availStore[state.evsel]||{};
  /* availability is strictly per event: wipe first so nobody carries a previous
     event's windows into this one (e.g. Spa availability showing up at NEC) */
  state.drivers.forEach(d=>{ d.avail=null; });
  const byKey={}; state.drivers.forEach(d=>{ byKey[nameKey(d.name)]=d; });
  Object.keys(a).forEach(n=>{
    let d=byKey[nameKey(n)];
    if(!d){
      /* anyone who submits availability joins the driver pool; pace fills in on the next import */
      const id=state.drivers.reduce((m,x)=>Math.max(m,x.id),0)+1;
      d={id:id, name:n, cars:{}, assignedCar:undefined, avail:null};
      state.drivers.push(d); byKey[nameKey(n)]=d;
    }
    d.avail=slotsToAvail(a[n]);
  });
}
function windowsToSlots(windows, winMin){
  const W=winMin||EV_WIN_MIN;
  const out=[]; const n=Math.max(1, Math.ceil(W/AV_BLOCK));
  for(let i=0;i<n;i++){
    const s=i*AV_BLOCK, e=Math.min((i+1)*AV_BLOCK, W);
    if((windows||[]).some(w=>Math.min(w[1],e)-Math.max(w[0],s) >= (e-s)/2)) out.push(i);
  }
  return out;
}
/* Re-seed Spa availability from the embedded SAMPLE (survey 2307) on every boot.
   Idempotent and never overwrites blocks a driver has submitted since; repairs saves
   where event-switching wiped the survey availability before it reached the store. */
function seedSpaAvail(){
  const SPA='Spa 24HR|2026-07-10', SPA_WIN=2568;
  const st=state.availStore[SPA]=state.availStore[SPA]||{};
  SAMPLE.forEach(s=>{ if(s.avail&&s.avail.windows&&s.avail.windows.length&&!(st[s.name]&&st[s.name].length)) st[s.name]=windowsToSlots(s.avail.windows, SPA_WIN); });
}
function persistAvail(evk,name){}  /* WP build overrides: syncs this driver's slots to the server */
function releaseLock(name){}       /* WP build overrides: admin clears a driver's submission lock */
/* Per-driver locking: a random device token identifies this browser; the first browser to
   submit a name owns it (server-enforced in the WP build — admins bypass and can release). */
let DEV_TOKEN=(function(){ try{ let t=localStorage.getItem('edrTB_devtok'); if(!t){ t=Array.from((window.crypto&&crypto.getRandomValues)?crypto.getRandomValues(new Uint8Array(16)):[...Array(16)].map(()=>Math.floor(Math.random()*256))).map(b=>b.toString(16).padStart(2,'0')).join(''); localStorage.setItem('edrTB_devtok',t); } return t; }catch(e){ return 'mem-'+Math.random().toString(36).slice(2); } })();
let LOCKED_NAMES=[];               /* names locked server-side (WP build fills this from GET /avail) */
function ownedNames(){ try{ return JSON.parse(localStorage.getItem('edrTB_owned'))||[]; }catch(e){ return []; } }
function markOwned(name){ try{ const o=ownedNames(); if(o.indexOf(name)<0){ o.push(name); localStorage.setItem('edrTB_owned',JSON.stringify(o)); } }catch(e){} }
function lockedForMe(name){ return LOCKED_NAMES.indexOf(name)>=0 && ownedNames().indexOf(name)<0; }
/* EDR membership pulled live from Garage 61 on 2026-07-05 (teams edr-endurotech +
   edr-endurotech-casual).
   Baked-in fallback; the WP build replaces it with the live list from GET /roster. */
let TEAM_ROSTER=["Aaron Werth", "Aden Lennox-Bradley", "Ben Hagstrom", "Bernardo Hickmann", "Bradley Whittaker", "Brock Hellmech", "Chris w", "David Piljek", "Dominic Bou-Samra", "Erik van der Bijl", "Fred Zufelt", "Jake Lennox-Bradley", "Janne Salminen", "John Nyhouse", "Joseph Tavora", "Landon Schrecengost", "Laurent Masson", "Luke Hay", "matt blee", "Matthew Halden", "Michael Cullen", "Roland Fokkens", "Sam Mackenzie", "Sam Millar", "Stipe Ljubić", "Thomas McEwan", "Thomaz Hernandes", "Valentin Ozhiganov", "Zachary Martin"];
let _availDirty={};   /* {evKey:{name:1}} — ticked but not yet submitted */
let _avMsg='';
/* the survey/plan and Garage 61 sometimes spell the same person differently — dedup via
   the documented override map + diacritic-insensitive matching */
const NAME_ALIASES={'joey tavora':'joseph tavora','matt halden':'matthew halden','zach martin':'zachary martin','chris wilson':'chris w','michael s cullen':'michael cullen'};
function nameKey(n){ n=String(n||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s+/g,' ').trim(); return NAME_ALIASES[n]||n; }
function availRoster(){
  const out=[], seen={};
  const add=n=>{ const k=nameKey(n); if(k&&!seen[k]){ seen[k]=1; out.push(n); } };
  state.drivers.forEach(d=>add(d.name));
  TEAM_ROSTER.forEach(add);
  Object.keys(state.availStore[state.evsel]||{}).forEach(add);
  return out.sort((x,y)=>x.localeCompare(y));
}
function fmtDay(absMin){
  return new Date(WIN_START_MS+absMin*60000).toLocaleDateString('en-AU',{timeZone:'Australia/Brisbane',weekday:'short',day:'numeric',month:'short'});
}

/* ===== Event tab (season calendar) ===== */
let _calFilter='all', _calTargets=true, _calPast=false;
function weekStartOf(s){ const dt=new Date(s+'T00:00:00'); const day=dt.getDay(); dt.setDate(dt.getDate()+((day===0?-6:1)-day)); return dt; }
function renderEventTab(){
  let html='';
  const upcoming=CAL_EVENTS.filter(ev=>statusFor(ev).state!=='past').sort((a,b)=>a.s.localeCompare(b.s))[0];
  if(upcoming){
    const st=statusFor(upcoming);
    html+='<div class="pitboard"><div><div class="meta" style="text-transform:uppercase;letter-spacing:.1em;color:var(--yellow)">'+(st.state==='live'?'live now':'next up')+'</div>'
      +'<div class="head" style="font-size:22px;color:#fff;text-transform:uppercase;letter-spacing:.03em">'+esc(upcoming.n)+'</div>'
      +'<div class="meta">'+esc(upcoming.track)+' · '+fmtDates(upcoming.s,upcoming.e)+(isTarget(upcoming)?' · <span style="color:var(--yellow)">EDR target</span>':'')+'</div></div>'
      +'<div class="pitcount"><div class="num">'+(st.state==='live'?'GO':st.label)+'</div><div class="meta">'+(st.state==='live'?'racing':(st.label===1?'day':'days'))+'</div></div></div>';
  }
  html+='<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">';
  [['all','All'],['endurance','Endurance / team'],['other','Other specials'],['nascar','NASCAR']].forEach(([k,l])=>{
    html+='<button class="chip'+(k===_calFilter?' calactive':'')+'" data-action="calfilter" data-val="'+k+'" style="cursor:pointer">'+l+'</button>';
  });
  html+='<label class="meta" style="margin-left:8px;cursor:pointer"><input type="checkbox" data-action="caltargets"'+(_calTargets?' checked':'')+'> EDR targets only</label>';
  html+='<label class="meta" style="cursor:pointer"><input type="checkbox" data-action="calpast"'+(_calPast?' checked':'')+'> show past</label>';
  html+='</div>';
  const list=CAL_EVENTS.filter(ev=>(_calFilter==='all'||ev.cat===_calFilter)&&(!_calTargets||isTarget(ev))&&(_calPast||statusFor(ev).state!=='past'))
    .slice().sort((a,b)=>a.s.localeCompare(b.s));
  if(!list.length){ return html+'<div class="meta">Nothing matches this filter.</div>'; }
  const byWeek=new Map();
  list.forEach(ev=>{ const k=weekStartOf(ev.s).getTime(); if(!byWeek.has(k)) byWeek.set(k,[]); byWeek.get(k).push(ev); });
  let nextShown=false;
  [...byWeek.keys()].sort((a,b)=>a-b).forEach(wk=>{
    const ws=new Date(wk); const we=new Date(wk); we.setDate(we.getDate()+6);
    html+='<div class="calweek"><div class="meta" style="text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">'
      +ws.toLocaleDateString('en-AU',{month:'short',day:'numeric'})+' – '+we.toLocaleDateString('en-AU',{month:'short',day:'numeric'})+'</div>';
    byWeek.get(wk).forEach(ev=>{
      const st=statusFor(ev), tgt=isTarget(ev), sel=state.evsel===evKey(ev);
      const isNext=!nextShown&&st.state==='upcoming'; if(isNext) nextShown=true;
      html+='<div class="calrow'+(st.state==='past'?' past':'')+(sel?' sel':'')+(tgt?' target':'')+'"'+(tgt?' data-action="selev" data-ev="'+esc(evKey(ev))+'"':'')+'>'
        +'<span class="swatch" style="background:'+CAT_COLOR[ev.cat]+';flex:0 0 auto"></span>'
        +'<div style="min-width:0;flex:1">'
        +'<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><span style="color:#fff;font-family:Prompt,sans-serif;font-weight:600">'+esc(ev.n)+'</span>'
        +(sel?'<span class="calbadge selb">selected</span>':'')
        +(isNext?'<span class="calbadge next">up next</span>':'')
        +(tgt?'<span class="calbadge tgt">target</span>':'<span class="calbadge ctx">context</span>')+'</div>'
        +(ev.series?'<div class="meta">'+esc(ev.series)+' · round '+esc(ev.round)+'</div>':'')
        +'<div class="meta">'+esc(ev.track)+(ev.dur?' · '+ev.dur+'h':'')+'</div></div>'
        +'<div style="text-align:right;flex:0 0 auto"><div class="meta" style="color:#fff">'+(ev.tbd?'TBD':fmtDates(ev.s,ev.e))+'</div>'
        +'<div class="meta">'+(st.state==='live'?'<span style="color:var(--green)">live now</span>':st.state==='past'?'completed':(ev.tbd?'':'in '+st.label+'d'))+'</div></div>'
        +'</div>';
    });
    html+='</div>';
  });
  html+='<div class="meta" style="margin:6px 0 0">Click a <b style="color:var(--yellow)">target</b> event to select it — the whole plan (availability, drivers, teams, stints) follows the selected event. Context events are shown for awareness only.</div>';
  return html;
}

/* ===== Availability tab ===== */
function canEditAvail(name){ return isAdmin()||(!!state.me&&name===state.me&&!lockedForMe(name)); }
function toggleAvail(name,slot,want){
  if(!canEditAvail(name)) return false;
  const store=state.availStore[state.evsel]=state.availStore[state.evsel]||{};
  const arr=store[name]=store[name]||[];
  const i=arr.indexOf(slot);
  const on=(want===undefined)?(i<0):want;
  if(on&&i<0) arr.push(slot);
  if(!on&&i>=0) arr.splice(i,1);
  (_availDirty[state.evsel]=_availDirty[state.evsel]||{})[name]=1; _avMsg='';
  applyAvailToDrivers(); save(); return true;
}
function setAllAvail(name,on){
  if(!canEditAvail(name)) return false;
  const store=state.availStore[state.evsel]=state.availStore[state.evsel]||{};
  store[name]= on ? Array.from({length:evSlots()},(_,i)=>i) : [];
  (_availDirty[state.evsel]=_availDirty[state.evsel]||{})[name]=1; _avMsg='';
  applyAvailToDrivers(); save(); return true;
}
function renderMyBlocks(a){
  const nm=state.me; const arr=a[nm]||[]; const n=evSlots(); const canEdit=canEditAvail(nm);
  const groups=[]; let cur=null;
  for(let i=0;i<n;i++){ const d=fmtDay(i*AV_BLOCK); if(!cur||cur.day!==d){ cur={day:d,slots:[]}; groups.push(cur);} cur.slots.push(i); }
  let h='<div class="importbox">';
  h+='<div class="myblocks-head"><span class="head" style="color:#fff;font-size:15px">Your blocks · '+esc(nm.split(' ')[0])+(lockedForMe(nm)?' 🔒':'')+'</span>'
    +(canEdit?'<span class="myblocks-btns"><button class="btn btn-amber avfree" data-action="avtickall">Tick all</button><button class="btn btn-ghost avfree" data-action="avclear">Clear</button></span>':'')+'</div>';
  h+='<div class="meta" style="margin:2px 0 4px">Tap every 4-hour block you can race (Brisbane time), or use Tick all. Then Submit below.</div>';
  groups.forEach(function(g){
    h+='<div class="blockday">'+g.day+'</div><div class="blockrow">';
    g.slots.forEach(function(i){
      const on=arr.indexOf(i)>=0;
      const lbl=fmtClock(i*AV_BLOCK).split(' ').slice(1).join(' ')+'–'+fmtClock(Math.min((i+1)*AV_BLOCK,EV_WIN_MIN)).split(' ').slice(1).join(' ');
      h+='<button class="blockcell'+(on?' on':'')+'"'+(canEdit?' data-avtoggle data-name="'+esc(nm)+'" data-slot="'+i+'"':' disabled')+'>'+lbl+'</button>';
    });
    h+='</div>';
  });
  h+='</div>'; return h;
}
function renderAvail(){
  if(!state.evsel) return '<div class="importbox"><div class="meta" style="margin-bottom:10px">No event selected yet.</div><button class="btn btn-amber avfree" data-action="gotoevent">Pick an event first</button></div>';
  const ev=calEvent(state.evsel);
  if(!ev) return '<div class="meta">Selected event not found in the calendar — pick another on the Event tab.</div>';
  const a=state.availStore[state.evsel]=state.availStore[state.evsel]||{};
  const roster=availRoster();
  const n=evSlots();
  let html='<div class="importbox">';
  html+='<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:baseline"><span class="head" style="font-size:17px;color:#fff;text-transform:uppercase">'+esc(ev.n)+'</span>'
    +'<span class="meta">'+esc(ev.track)+' · '+fmtDates(ev.s,ev.e)+(ev.dur?' · '+ev.dur+'h race':'')+'</span></div>';
  if(isAdmin()){
    html+='<div class="meta" style="margin-top:8px">Admin: tick any driver\'s blocks. Add drivers below. Times are Brisbane.</div>';
  } else {
    html+='<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px"><label class="meta">I am</label>'
      +'<select class="avfree" data-action="meselect"><option value="">— pick your name —</option>'
      +roster.map(nm=>'<option value="'+esc(nm)+'"'+(nm===state.me?' selected':'')+(lockedForMe(nm)?' disabled':'')+'>'+esc(nm)+(lockedForMe(nm)?' 🔒':'')+'</option>').join('')+'</select>'
      +(state.me?'':'<span class="meta">Pick your name to enter availability.</span>')+'</div>';
  }
  const dirtyN=Object.keys(_availDirty[state.evsel]||{}).length;
  html+='<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px">'
    +'<button class="btn btn-amber avfree" data-action="avsubmit">Submit availability</button>'
    +(dirtyN?'<span class="meta" style="color:var(--amber)">Unsaved changes ('+dirtyN+' driver'+(dirtyN>1?'s':'')+') — hit Submit to save for the team.</span>'
      :(_avMsg?'<span class="meta" style="color:'+(_avMsg.indexOf('failed')>=0?'var(--red)':'var(--green)')+'">'+esc(_avMsg)+'</span>'
        :'<span class="meta">Tick your blocks, then Submit — everyone sees it from then on.</span>'))
    +'</div>';
  html+='</div>';
  // driver: mobile-friendly personal block picker (primary editor)
  if(!isAdmin() && state.me) html+=renderMyBlocks(a);
  // team coverage matrix (driver: read-only overview; admin: editable)
  html+='<div class="importbox" style="overflow-x:auto"><div class="meta" style="margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em">Team coverage'+(isAdmin()?' — tick any driver, or use all/clr':'')+'</div><table class="avmatrix"><thead><tr><th class="slot">Block (Brisbane)</th>';
  roster.forEach(nm=>{ const me=!isAdmin()&&nm===state.me; html+='<th'+(me?' class="me"':'')+'>'+esc(nm.split(' ')[0])+(me?' <span style="color:var(--yellow)">(you)</span>':'')+(isAdmin()?'<br><span class="meta" style="font-weight:400" data-action="avcol" data-name="'+esc(nm)+'" data-op="all">all</span> <span class="meta" style="font-weight:400" data-action="avcol" data-name="'+esc(nm)+'" data-op="clr">clr</span>':'')+'</th>'; });
  html+='<th>Covered</th></tr></thead><tbody>';
  let lastDay='';
  for(let i=0;i<n;i++){
    const day=fmtDay(i*AV_BLOCK);
    if(day!==lastDay){ lastDay=day; html+='<tr class="dayhead"><td colspan="'+(roster.length+2)+'">'+day+'</td></tr>'; }
    let cov=0;
    html+='<tr><td class="slot">'+fmtClock(i*AV_BLOCK).split(' ').slice(1).join(' ')+' – '+fmtClock(Math.min((i+1)*AV_BLOCK,EV_WIN_MIN)).split(' ').slice(1).join(' ')+'</td>';
    roster.forEach(nm=>{
      const on=(a[nm]||[]).indexOf(i)>=0; if(on) cov++;
      const can=isAdmin();  // drivers edit via the block picker above; matrix is their read-only overview
      const me=!isAdmin()&&nm===state.me;
      html+='<td'+(me?' class="me"':'')+'><input type="checkbox" class="avfree" data-avtoggle data-name="'+esc(nm)+'" data-slot="'+i+'"'+(on?' checked':'')+(can?'':' disabled')+'></td>';
    });
    html+='<td class="cov '+(cov===0?'c0':cov===1?'c1':'c2')+'">'+cov+'</td></tr>';
  }
  html+='<tr class="totals"><td class="slot">Hours</td>';
  roster.forEach(nm=>{ html+='<td>'+((a[nm]||[]).length*4)+'h</td>'; });
  html+='<td></td></tr></tbody></table>';
  if(isAdmin()){
    html+='<div style="display:flex;gap:8px;margin-top:10px;align-items:center"><input type="text" id="avnewdrv" placeholder="Add a driver by name" style="padding:7px 10px;font-size:12px">'
      +'<button class="btn btn-ghost" data-action="avadddrv" style="font-size:10px;padding:5px 12px">+ Add driver</button></div>';
    if(LOCKED_NAMES.length){
      html+='<div class="meta" style="margin-top:10px">Locked (submitted from their own device): '
        +LOCKED_NAMES.map(nm=>'<span data-action="avrelease" data-name="'+esc(nm)+'" title="release this lock" style="cursor:pointer;text-decoration:underline;margin-right:8px">'+esc(nm)+' 🔓</span>').join('')
        +'— click a name to release its lock (e.g. new device).</div>';
    }
  }
  html+='</div>';
  // coverage summary
  let covered=0, totalHrs=0, avail=0;
  for(let i=0;i<n;i++){ if(roster.some(nm=>(a[nm]||[]).indexOf(i)>=0)) covered++; }
  roster.forEach(nm=>{ const h4=(a[nm]||[]).length*4; totalHrs+=h4; if(h4>0) avail++; });
  html+='<div class="sumgrid">'
    +'<div class="sumcard"><div class="v">'+avail+'</div><div class="k">drivers available</div></div>'
    +'<div class="sumcard"><div class="v">'+totalHrs+'h</div><div class="k">total driver-hours</div></div>'
    +'<div class="sumcard"><div class="v">'+(n?Math.round(covered/n*100):0)+'%</div><div class="k">window coverage</div></div>'
    +'<div class="sumcard"><div class="v">'+covered+'/'+n+'</div><div class="k">blocks covered</div></div>'
    +'</div>';
  html+='<div class="meta" style="margin-top:10px">Availability here feeds the '+(isAdmin()?'Drivers ranking, Teams and Stints tabs directly.':'team plan — the admin builds teams and stints from it.')+'</div>';
  return html;
}

function renderSummary(model){
  const classes=Object.keys(model);
  const ev=state.evsel?calEvent(state.evsel):null;
  const eh=document.getElementById('evheading');
  if(eh) eh.innerHTML = ev
    ? '<span style="font-family:\'Prompt\',sans-serif;font-weight:700;font-size:19px;color:var(--yellow);text-transform:uppercase;letter-spacing:.04em">'+esc(ev.n)+'</span>'
      +' <span class="meta" style="font-size:12px">'+esc(ev.track)+' · '+fmtDates(ev.s,ev.e)+(ev.dur?' · '+ev.dur+'h race':'')+'</span>'
    : '<span class="meta" style="color:var(--red);font-size:12px">No event selected — open the Event tab and pick one.</span>';
  document.getElementById('summary').innerHTML =
    '<span>'+state.drivers.length+' drivers · '+classes.length+' classes</span>'+
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
    html+='<div style="margin-bottom:26px"><h2 class="classhdr" style="font-size:17px">'+esc(cls)+'</h2>';
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
  let html='';
  if(state.evsel){
    const pool=eventPool().length, hidden=state.drivers.length-pool;
    const ev=calEvent(state.evsel);
    html+='<div class="meta" style="margin-bottom:14px">Showing the <b style="color:#fff">'+pool+'</b> driver'+(pool===1?'':'s')+' with availability for <b style="color:var(--yellow)">'+esc(ev?ev.n:state.evsel)+'</b>'+(hidden>0?' · '+hidden+' without availability hidden':'')+'.</div>';
    if(!pool) return html+'<div class="importbox"><div class="meta">No one has submitted availability for this event yet — drivers appear here as soon as they hit Submit on the Availability tab.</div></div>';
  }
  Object.keys(model).forEach(cls=>{
    const grp=model[cls];
    html+='<div style="margin-bottom:18px"><div class="classhdr">'+esc(cls)+' <small>· '+grp.length+' drivers · ranked within class</small></div><div style="display:flex;flex-direction:column;gap:8px">';
    grp.forEach((d,i)=>{
      const cars=Object.keys(d.cars||{}).sort((a,b)=>((d.cars[a].medianLap==null?1e9:d.cars[a].medianLap)-(d.cars[b].medianLap==null?1e9:d.cars[b].medianLap)));
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
  html+='<div class="ctl"><label>DEFAULT START (LOCAL · UTC)</label><select data-action="stintwin">'+
    Object.keys(START_OFFSETS).map(Number).map(w=>'<option value="'+w+'"'+(w===state.stint.window?' selected':'')+'>#'+w+' · '+START_LABELS[w]+'</option>').join('')+'</select></div>';
  html+='<div class="ctl"><label>BLOCK LENGTH (min)</label><input type="number" min="10" max="240" step="5" value="'+state.stint.len+'" data-action="stintlen" style="width:90px;padding:6px"></div>';
  html+='<div class="ctl"><label>RACE LENGTH (min)</label><input type="number" min="30" max="1440" step="10" value="'+state.stint.race+'" data-action="stintrace" style="width:90px;padding:6px"></div>';
  html+='<button class="btn btn-ghost" data-action="stintreset" style="align-self:center">Auto-fill all</button>';
  html+='<div class="meta" style="align-self:center;max-width:360px"><b style="color:#fff">Each car can run a different session.</b> Pick its start below (or set a default for all). Clock = Brisbane. Drag blocks or bank names, or click a driver\'s lane to hand them that stint.</div>';
  html+='</div>';
  const classes=Object.keys(state.teams);
  if(!classes.length) return html+'<div class="meta">Hit Generate to build teams first.</div>';
  const summ=windowSummary(byId,len,race);
  const bestW=summ.slice().sort((a,b)=>(b.covered-a.covered)||(b.free-a.free))[0];
  html+='<div style="margin:2px 0 18px"><div class="meta" style="margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em">Session finder, click a window to set it as the default for all cars</div><div style="display:flex;gap:10px;flex-wrap:wrap">';
  summ.forEach(s=>{
    const sel=s.w===state.stint.window, isBest=bestW&&s.w===bestW.w;
    html+='<button data-action="stintwin" value="'+s.w+'" class="winbtn'+(sel?' sel':'')+(isBest?' best':'')+'">'
      +'<div style="font-family:Prompt;font-weight:600;color:#fff;text-transform:uppercase;font-size:12px">#'+s.w+' · '+START_LABELS[s.w]+(isBest?' <span style="color:var(--green)">● best</span>':'')+'</div>'
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
  const PALETTE=['#6ea8ff','#5fd38a','#ffb454','#d78cff','#4fd1c5','#ff8fa3','#c8e05a','#9aa3c0'];
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
        const colr={}; members.forEach((m,i)=>colr[m.id]=PALETTE[i%PALETTE.length]);
        const count={}; members.forEach(m=>count[m.id]=0); arr.forEach(id=>{ if(id!=null && count[id]!=null) count[id]++; });
        let gaps=0, conflicts=0;
        arr.forEach((id,i)=>{ const t=times[i], m=id!=null?byId[id]:null; if(id==null) gaps++; else if(!m||!driverFree(m,t.s,t.e)) conflicts++; });
        const badge = gaps? '<span class="meta" style="color:var(--red)">'+gaps+' empty</span>' : conflicts? '<span class="meta" style="color:var(--red)">'+conflicts+' conflict'+(conflicts>1?'s':'')+'</span>' : '<span class="meta" style="color:var(--green)">all covered</span>';
        const winSel='<select data-action="carwin" data-key="'+key+'">'+Object.keys(START_OFFSETS).map(Number).map(w=>'<option value="'+w+'"'+(w===win?' selected':'')+'>start #'+w+' · '+START_LABELS[w]+'</option>').join('')+'</select>';
        const best=carBestWindow(team,byId,len,race);
        const curCovGaps=times.filter(t=>!members.some(m=>driverFree(m,t.s,t.e))).length;
        const bestHint=(best && best.gaps<curCovGaps)? '<span class="meta" style="color:var(--green);cursor:pointer;text-decoration:underline" data-action="usebest" data-key="'+key+'" data-win="'+best.w+'">better fit: #'+best.w+' '+START_LABELS[best.w]+' ('+(best.gaps?best.gaps+' gaps':'full cover')+'), use</span>' : '';
        html+='<div class="stintcar"><div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap"><span class="swatch" style="background:'+col+'"></span><span class="head" style="letter-spacing:.04em;color:#fff;font-size:15px">'+esc(cls)+' · '+label[0]+(ti+1)+'</span>'+winSel+badge+bestHint+'</div>';
        html+='<div class="stintwrap"><div class="stintgrid" style="grid-template-columns:150px repeat('+times.length+',minmax(84px,1fr))">';
        html+='<div class="sg-corner">stint →</div>';
        times.forEach((t,i)=>{ html+='<div class="sg-time"><b>'+(i+1)+'</b>'+fmtClock(t.s)+'</div>'; });
        html+='<div class="sg-label" style="font-family:Prompt;font-weight:600;color:#fff;text-transform:uppercase;font-size:11px;letter-spacing:.05em">Driving</div>';
        arr.forEach((id,i)=>{
          const t=times[i], m=id!=null?byId[id]:null;
          const free=m?driverFree(m,t.s,t.e):false;
          html+='<div class="sg-block'+(m?(free?'':' conflict'):' empty')+'" data-stintcell data-key="'+key+'" data-block="'+i+'" data-driver="'+(id==null?'':id)+'" style="--dc:'+(m?colr[id]:'transparent')+'" title="'+(m?(free?'available · drag to move or swap':'NOT available this block'):'empty · drag a driver here')+'">'+(m?esc(m.name.split(' ')[0])+(free?'':' <span class="warn">!</span>'):'+')+'</div>';
        });
        members.forEach(m=>{
          html+='<div class="sg-label"><span class="sg-dot" style="background:'+colr[m.id]+'"></span><span style="overflow:hidden;text-overflow:ellipsis">'+esc(m.name.split(' ')[0])+'</span><span class="meta">'+(count[m.id]||0)+'× · '+availBadge(m)+'</span></div>';
          times.forEach((t,i)=>{
            const free=driverFree(m,t.s,t.e), driving=arr[i]===m.id;
            html+='<div class="sg-lane '+(driving?'driving':free?'free':'busy')+'" data-lanecell data-key="'+key+'" data-block="'+i+'" data-driver="'+m.id+'" style="--dc:'+colr[m.id]+'" title="'+esc(m.name.split(' ')[0])+' · stint '+(i+1)+' · '+(free?'available':'not available')+(driving?' · driving, click to clear':' · click to assign')+'"></div>';
          });
        });
        html+='</div></div>';
        html+='<div class="meta" style="margin-top:10px">Lanes: <span style="color:var(--green)">green = available</span> · striped = not free · solid = driving that stint. Click a lane cell to assign, drag blocks to swap, or drag names from the bank.</div></div>';
      });
    });
  });
  return html;
}

function renderContent(){
  (document.getElementById('edr-tb-app')||document.body).classList.toggle('readonly', !isAdmin());
  const model=computeModel();
  const byId={}; Object.keys(model).forEach(c=>model[c].forEach(d=>byId[d.id]=d));
  renderSummary(model);
  const el=document.getElementById('content');
  el.innerHTML = state.tab==='setup' ? renderSetup() : state.tab==='event' ? renderEventTab() : state.tab==='availability' ? renderAvail() : state.tab==='teams' ? renderTeams(byId) : state.tab==='stints' ? renderStints(byId) : renderDrivers(model);
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
    state.drivers=arr.map(r=>({id:id++,name:String(r.name||('Driver '+id)),cars:(r.cars&&typeof r.cars==='object')?r.cars:{},assignedCar:fastestCar(r.cars),avail:r.avail||null}));
    generate(); save(); document.getElementById('importbox').classList.add('hide'); renderContent();
    setStatus('Imported '+state.drivers.length+' drivers');
  }catch(e){ setStatus('Import failed: '+e.message); }
});

document.querySelectorAll('.tab').forEach(t=>t.addEventListener('click',()=>{ state.tab=t.dataset.tab; setActiveTab(); renderContent(); }));
function setActiveTab(){ document.querySelectorAll('.tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===state.tab)); }

document.getElementById('content').addEventListener('change',e=>{
  const a=e.target.dataset.action;
  if(e.target.dataset.avtoggle!==undefined && e.target.type==='checkbox'){
    const name=e.target.dataset.name, slot=+e.target.dataset.slot;
    if(!toggleAvail(name,slot,e.target.checked)){ e.target.checked=!e.target.checked; return; }
    renderContent(); return;
  }
  if(a==='meselect'){ state.me=e.target.value; save(); renderContent(); return; }
  if(a==='caltargets'){ _calTargets=e.target.checked; renderContent(); return; }
  if(a==='calpast'){ _calPast=e.target.checked; renderContent(); return; }
  if(!isAdmin()) return;
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
  const calRow=e.target.closest&&e.target.closest('[data-action="selev"]');
  if(calRow){ selectEvent(calRow.dataset.ev); return; }
  const filt=e.target.closest&&e.target.closest('[data-action="calfilter"]');
  if(filt){ _calFilter=filt.dataset.val; renderContent(); return; }
  if(e.target.dataset.action==='gotoevent'){ state.tab='event'; setActiveTab(); renderContent(); return; }
  const bcell=e.target.closest&&e.target.closest('button[data-avtoggle]');
  if(bcell){ if(toggleAvail(bcell.dataset.name,+bcell.dataset.slot)) renderContent(); return; }
  if(e.target.dataset.action==='avtickall'){ if(state.me&&setAllAvail(state.me,true)) renderContent(); return; }
  if(e.target.dataset.action==='avclear'){ if(state.me&&setAllAvail(state.me,false)) renderContent(); return; }
  const avcol=e.target.closest&&e.target.closest('[data-action="avcol"]');
  if(avcol){ if(setAllAvail(avcol.dataset.name,avcol.dataset.op==='all')) renderContent(); return; }
  if(e.target.dataset.action==='avsubmit'){
    const evk=state.evsel; if(!evk) return;
    const dirty=Object.keys(_availDirty[evk]||{});
    const names=dirty.length?dirty:(isAdmin()?Object.keys(state.availStore[evk]||{}):(state.me?[state.me]:[]));
    if(!names.length){ _avMsg='Nothing to submit — pick your name and tick your blocks first.'; renderContent(); return; }
    Promise.all(names.map(function(n){ return Promise.resolve(persistAvail(evk,n)); }))
      .then(function(){
        _availDirty[evk]={};
        names.forEach(function(n){ markOwned(n); if(!isAdmin() && LOCKED_NAMES.indexOf(n)<0) LOCKED_NAMES.push(n); });
        _avMsg='Submitted — availability saved for the team. Your entry is now locked to this device.';
        save(); renderContent();
      })
      .catch(function(err){ _avMsg=(err&&err.message&&/locked/i.test(err.message))?err.message:'Save failed — check your connection and hit Submit again.'; renderContent(); });
    return;
  }
  if(e.target.dataset.action==='avrelease'){
    if(!isAdmin()) return;
    releaseLock(e.target.dataset.name);
    return;
  }
  if(e.target.dataset.action==='avadddrv'){
    if(!isAdmin()) return;
    const inp=document.getElementById('avnewdrv'); const name=(inp&&inp.value||'').trim();
    if(!name) return;
    if(!state.drivers.some(d=>d.name===name)){
      const id=state.drivers.reduce((m,d)=>Math.max(m,d.id),0)+1;
      state.drivers.push({id:id,name:name,cars:{},assignedCar:undefined,avail:null});
    }
    save(); renderContent(); return;
  }
  if(!isAdmin()) return;
  const winBtn=e.target.closest&&e.target.closest('button[data-action="stintwin"]');
  if(winBtn){ state.stint.window=+winBtn.value; state.stintWin={}; state.stintAssign={}; save(); renderContent(); return; }
  const lane=e.target.closest&&e.target.closest('[data-lanecell]');
  if(lane){ const k=lane.dataset.key,b=+lane.dataset.block,d=+lane.dataset.driver,a=state.stintAssign[k]; if(a){ a[b]=(a[b]===d)?null:d; save(); renderContent(); } return; }
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
  if(!isAdmin()) return;
  const chip=e.target.closest&&e.target.closest('[data-bankchip]');
  const cell=e.target.closest&&e.target.closest('[data-stintcell]');
  if(chip){ _pd={kind:'bank',driver:chip.dataset.driver}; }
  else if(cell){ if(cell.dataset.driver==='') return; _pd={kind:'cell',key:cell.dataset.key,block:+cell.dataset.block,driver:cell.dataset.driver}; }
  else return;
  e.preventDefault();
  _ghost=document.createElement('div');
  _ghost.textContent=_pd.driver===''?'clear':_drvName(_pd.driver);
  _ghost.style.cssText='position:fixed;left:0;top:0;z-index:99999;pointer-events:none;background:var(--yellow);color:#0a0a0a;font-family:Prompt,sans-serif;font-weight:700;font-size:12px;padding:5px 12px;border-radius:999px;box-shadow:0 4px 14px rgba(0,0,0,.4)';
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
const CAN=!!(window.EDR_TB&&EDR_TB.can_edit);
/* Auth headers: logged-in users send the REST nonce; password-admins send X-EDR-Pass
   (verified server-side). Plain viewers send neither — a stale cached-page nonce would
   otherwise make WordPress 403 even public REST routes. */
function _hdrs(json){ const h={}; if(json) h['Content-Type']='application/json'; if(CAN) h['X-WP-Nonce']=NONCE; else if(state.pass) h['X-EDR-Pass']=state.pass; return h; }
function apiGET(p){return fetch(API+p,{headers:_hdrs(false)}).then(r=>r.json());}
function apiPOST(p,b){return fetch(API+p,{method:'POST',headers:_hdrs(true),body:JSON.stringify(b)}).then(r=>r.json());}
/* password-admin: verified by the server, never against a hash in this file */
function verifyAdminPass(p,cb){
  fetch(API+'auth',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pass:p})})
    .then(r=>r.json()).then(r=>cb(!!(r&&r.ok))).catch(function(){cb(false);});
}
/* after a password unlock the Setup tab is newly visible — fetch its data now
   (at boot these only load for already-admin sessions) */
function onAdminUnlocked(){
  (async function(){
    try{ TRACKS=await apiGET('tracks'); }catch(e){ TRACKS=[]; } if(!Array.isArray(TRACKS)) TRACKS=[];
    preselectNearest();
    renderContent();
    loadIracing().then(renderContent);
  })();
}
/* per-driver availability syncs through its own public route, not the plan.
   Carries the device token (per-driver locking) and admin credentials when present.
   Returns the fetch promise so the Submit button can await it and confirm. */
function persistAvail(evk,name){
  const slots=((state.availStore[evk]||{})[name])||[];
  return fetch(API+'avail',{method:'POST',headers:_hdrs(true),body:JSON.stringify({ev:evk,name:name,slots:slots,token:DEV_TOKEN})})
    .then(function(r){
      return r.json().catch(function(){ return {}; }).then(function(j){
        if(!r.ok) throw new Error((j&&j.message)||'avail save failed');
        if(j&&j.locked) LOCKED_NAMES=j.locked;
        return j;
      });
    });
}
function releaseLock(name){
  apiPOST('avail',{name:name,release:1}).then(function(r){
    if(r&&r.ok){ LOCKED_NAMES=LOCKED_NAMES.filter(function(n){return n!==name;}); renderContent(); }
  }).catch(function(){});
}
let lastTrackIds=[], _saveT=null;
function serializePlan(){ return {drivers:state.drivers,w:state.w,proPct:state.proPct,teams:state.teams,stint:state.stint,stintAssign:state.stintAssign,stintWin:state.stintWin,stintSig:state.stintSig,overrides:overrides,meta:IMPORT_META,winStart:WIN_START_MS,startOffsets:START_OFFSETS,startLabels:START_LABELS,matches:lastMatches,trackIds:lastTrackIds,evsel:state.evsel,evWinMin:EV_WIN_MIN,evTiming:state.evTiming}; }
function save(){
  try{ localStorage.setItem('edrTB_local', JSON.stringify({role:state.role,me:state.me,pass:state.pass})); }catch(e){}
  if(!isAdmin()) return;
  clearTimeout(_saveT); _saveT=setTimeout(function(){ try{ apiPOST('plan',{plan:serializePlan()}); }catch(e){} }, 600);
}
function loadPlan(){ return apiGET('plan').then(function(r){ var p=r&&r.plan; if(!p||!p.drivers||!p.drivers.length) return false;
  state.drivers=p.drivers; state.w=p.w||state.w; state.proPct=(typeof p.proPct==='number')?p.proPct:state.proPct; state.teams=p.teams||{};
  state.stint=Object.assign(state.stint,p.stint||{}); state.stintAssign=p.stintAssign||{}; state.stintWin=p.stintWin||{}; state.stintSig=p.stintSig||'';
  if(p.overrides)overrides=p.overrides; if(p.meta)IMPORT_META=p.meta; if(p.winStart)WIN_START_MS=p.winStart;
  if(p.startOffsets&&Object.keys(p.startOffsets).length)START_OFFSETS=p.startOffsets; if(p.startLabels&&Object.keys(p.startLabels).length)START_LABELS=p.startLabels;
  if(p.evsel)state.evsel=p.evsel; if(p.evWinMin)EV_WIN_MIN=p.evWinMin; if(p.evTiming)state.evTiming=p.evTiming;
  lastMatches=p.matches||[]; lastTrackIds=p.trackIds||[]; return true; }).catch(function(){return false;}); }
var IR_SEASONS=[], IR_STATUS='';
function loadIracing(){
  return apiGET('iracing').then(function(r){
    if(r&&r.ok){ IR_SEASONS=r.seasons||[]; IR_STATUS=IR_SEASONS.length?'':'iRacing connected, but no active events expose session times right now.'; }
    else if(r&&r.reason==='not_configured'){ IR_SEASONS=[]; IR_STATUS='iRacing proxy not set in plugin Settings — using calendar/derived session times.'; }
    else { IR_SEASONS=[]; IR_STATUS=(r&&r.message)||'iRacing unavailable right now.'; }
  }).catch(function(){ IR_SEASONS=[]; IR_STATUS='iRacing unavailable right now.'; });
}
function irMatchFor(ev){
  if(!ev||!IR_SEASONS.length) return null;
  var nk=function(s){return String(s||'').toLowerCase().replace(/[^a-z0-9]/g,'');};
  var evk=nk(ev.n), evtk=nk(ev.track);
  var best=null,bs=0;
  IR_SEASONS.forEach(function(se){
    var nn=nk(se.name), tk=nk(se.track), sc=0;
    // token overlap on the event name
    (ev.n.toLowerCase().match(/[a-z0-9]+/g)||[]).forEach(function(w){ if(w.length>2 && nn.indexOf(w)>=0) sc+=2; });
    if(evtk && tk && (tk.indexOf(evtk.slice(0,8))>=0 || evtk.indexOf(tk.slice(0,8))>=0)) sc+=3;
    if(sc>bs){ bs=sc; best=se; }
  });
  return bs>=3?best:null;
}
let SEL_TRACK=0;
function preselectNearest(){
  SEL_TRACK=(lastTrackIds&&lastTrackIds[0]) || (Array.isArray(TRACKS)&&TRACKS.length?TRACKS[0].id:0);
}
const norm=s=>String(s||'').toLowerCase().replace(/[^a-z]/g,'');
function classOfCar(c){const n=String(c).toUpperCase();if(/GTP|HYBRID|LMDH/.test(n))return'GTP';if(/LMP2|P217|LMP/.test(n))return'LMP2';if(/GT4/.test(n))return'GT4';if(/GT3/.test(n))return'GT3';return'Other';}
const OVR_KEY='edrTB_overrides';
let overrides=(function(){try{return JSON.parse(localStorage.getItem(OVR_KEY))||{};}catch(e){return{};}})();
function saveOverrides(){try{localStorage.setItem(OVR_KEY,JSON.stringify(overrides));}catch(e){}}
let TRACKS=null, lastPayload=null, setupMsg='';
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
function applyImport(payload){
  var drivers=[], id=1;
  (payload.roster||[]).forEach(function(r){ drivers.push({id:id++, name:r.name, cars:r.cars, assignedCar:fastestCar(r.cars), avail:null}); });
  state.drivers=drivers; state.stintAssign={}; state.stintSig=''; state.stintWin={};
  applyAvailToDrivers();  // in-house per-event availability is the single source of truth
  generate(); save();
}
async function doImport(trackIds){
  lastTrackIds=trackIds; setSetupMsg('Pulling Garage 61 pace…');
  try{
    const payload=await apiPOST('import',{trackIds:trackIds});
    if(payload.code){ setSetupMsg('Error: '+(payload.message||payload.code)); return; }
    lastPayload=payload;
    applyImport(payload);
    setSetupMsg('Imported pace for '+state.drivers.length+' drivers.');
    state.tab='setup'; setActiveTab(); renderContent();
  }catch(e){ setSetupMsg('Import failed: '+e.message); }
}

let SETUP_MANUAL=false;
function renderSetup(){
  const selEv=state.evsel?calEvent(state.evsel):null;
  const evTrackIds=(selEv&&selEv.g61Tracks&&!SETUP_MANUAL)?selEv.g61Tracks:null;
  let h='<div class="edr-setup">';
  h+='<div class="importbox" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">';
  if(evTrackIds){
    const tnames=evTrackIds.map(function(id){ const t=(TRACKS||[]).find(x=>x.id===id); return t?(t.name+(t.variant?' - '+t.variant:'')):('#'+id); });
    h+='<div class="ctl"><label>TRACK — from the selected event</label><div style="font-size:13px;color:#fff;padding:6px 0">'+esc(selEv.n)+': '+esc(tnames.join(' + '))+'</div><span class="meta" data-s="manualtrack" style="cursor:pointer;text-decoration:underline">use the manual track picker instead</span></div>';
  } else {
    h+='<div class="ctl"><label>TRACK (Garage 61)</label><select data-s="track">'+(Array.isArray(TRACKS)?TRACKS.map(t=>'<option value="'+t.id+'"'+(t.id===SEL_TRACK?' selected':'')+'>'+esc(t.name+(t.variant?' - '+t.variant:''))+'</option>').join(''):'<option>loading...</option>')+'</select>'
      +((selEv&&selEv.g61Tracks)?'<div><span class="meta" data-s="autotrack" style="cursor:pointer;text-decoration:underline">back to the event tracks</span></div>':'')+'</div>';
  }
  h+='<button class="btn btn-amber" data-s="import">Import / Refresh now</button>';
  h+='<span class="meta" style="align-self:center;max-width:360px">'+esc(setupMsg||'This is the shared team plan, saved for everyone. Pick a track (or use the event\'s tracks), then Import / Refresh to pull Garage 61 pace.')+'</span>';
  h+='</div>';
  // official iRacing session start times + race length (via the proxy)
  h+='<div class="importbox"><div class="meta" style="margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em">Official iRacing session times</div>';
  if(!selEv){ h+='<div class="meta">Select a target event on the Event tab to pull its official session start times and race length.</div>'; }
  else {
    var cur=state.evTiming&&state.evTiming[evKey(selEv)];
    var match=irMatchFor(selEv);
    h+='<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">';
    h+='<select data-s="irsel"><option value="">(pick the matching iRacing event)</option>'+IR_SEASONS.map(function(se,i){var sel=(match&&se.season_id===match.season_id)?' selected':''; return '<option value="'+i+'"'+sel+'>'+esc(se.name)+' · '+esc(se.track)+(se.race_min?' · '+Math.round(se.race_min/60)+'h':'')+'</option>';}).join('')+'</select>';
    h+='<button class="btn btn-amber" data-s="irapply">Apply to '+esc(selEv.n)+'</button>';
    h+='<span class="meta" data-s="irrefresh" style="cursor:pointer;text-decoration:underline">refresh</span>';
    h+='</div>';
    if(cur) h+='<div class="meta" style="margin-top:8px;color:var(--green)">Using official times'+(cur.src?' from "'+esc(cur.src)+'"':'')+' — '+Object.keys(cur.offsets||{}).length+' starts, '+Math.round((cur.raceMin||0)/60)+'h race. <span data-s="irclear" style="cursor:pointer;text-decoration:underline;color:var(--red)">revert to calendar</span></div>';
    if(IR_STATUS) h+='<div class="meta" style="margin-top:8px">'+esc(IR_STATUS)+'</div>';
    if(!IR_SEASONS.length && !IR_STATUS) h+='<div class="meta" style="margin-top:8px">Loading iRacing…</div>';
  }
  h+='</div>';
  h+='</div>';
  return h;
}

/* delegated handlers for setup controls */
document.getElementById('content').addEventListener('click',function(e){
  const s=e.target.dataset.s;
  if(s==='manualtrack'){ SETUP_MANUAL=true; renderContent(); }
  else if(s==='autotrack'){ SETUP_MANUAL=false; renderContent(); }
  else if(s==='import'){
    const selEv=state.evsel?calEvent(state.evsel):null;
    const evIds=(selEv&&selEv.g61Tracks&&!SETUP_MANUAL)?selEv.g61Tracks:null;
    const tEl=document.querySelector('[data-s=track]');
    const ids=evIds||(tEl?[parseInt(tEl.value,10)]:[]);
    if(ids.length) doImport(ids);
  }
  else if(s==='irrefresh'){ setSetupMsg('Refreshing iRacing…'); loadIracing().then(function(){ setSetupMsg(''); renderContent(); }); }
  else if(s==='irapply'){
    const selEv=state.evsel?calEvent(state.evsel):null; const sel=document.querySelector('[data-s=irsel]');
    if(!selEv||!sel||sel.value===''){ setSetupMsg('Pick the matching iRacing event first.'); return; }
    const se=IR_SEASONS[parseInt(sel.value,10)];
    if(se && applyIrTiming(selEv, se)){ applyEventTiming(selEv); state.stintAssign={}; state.stintWin={}; state.stintSig=''; applyAvailToDrivers(); save(); setSetupMsg('Applied official session times from "'+se.name+'".'); renderContent(); }
    else setSetupMsg('That iRacing event has no session times.');
  }
  else if(s==='irclear'){
    const selEv=state.evsel?calEvent(state.evsel):null;
    if(selEv){ delete state.evTiming[evKey(selEv)]; applyEventTiming(selEv); state.stintAssign={}; state.stintWin={}; state.stintSig=''; applyAvailToDrivers(); save(); setSetupMsg('Reverted to calendar session times.'); renderContent(); }
  }
});

async function bootSetup(){
  try{ const lp=JSON.parse(localStorage.getItem('edrTB_local'))||{}; if(lp.role) state.role=lp.role; if(lp.me) state.me=lp.me; if(lp.pass) state.pass=lp.pass; }catch(e){}
  if(CAN) state.role='admin';
  document.getElementById('rolebar').addEventListener('click',function(e){
    const b=e.target.closest&&e.target.closest('[data-role-action]');
    if(!b) return;
    const a=b.dataset.roleAction;
    if(a==='unlock') unlockAdmin();
    else if(a==='go') submitUnlock();
    else if(a==='cancel'){ _unlockOpen=false; _unlockMsg=''; renderRolebar(); }
    else lockAdmin();
  });
  document.getElementById('rolebar').addEventListener('keydown',function(e){
    if(e.key==='Enter' && e.target.id==='adminpass') submitUnlock();
  });
  renderRolebar();
  syncControls();
  state.tab = CAN ? 'setup' : 'event'; setActiveTab();
  var ok=false; try{ ok=await loadPlan(); }catch(e){}
  try{
    const av=await apiGET('avail');
    if(av&&av.store){ state.availStore=av.store; LOCKED_NAMES=av.locked||[]; }
    else if(av&&typeof av==='object'&&!Array.isArray(av)) state.availStore=av;  /* pre-2.1.4 server shape */
  }catch(e){}
  try{ const tr=await apiGET('roster'); if(Array.isArray(tr)&&tr.length) TEAM_ROSTER=tr; }catch(e){}
  applyAvailToDrivers();
  if(ok && state.drivers.length && !Object.keys(state.teams||{}).length) generate();
  renderContent();
  if(isAdmin()){
    try{ TRACKS=await apiGET('tracks'); }catch(e){ TRACKS=[]; } if(!Array.isArray(TRACKS)) TRACKS=[];
    preselectNearest();
    if(state.tab==='setup') renderContent();
    loadIracing().then(function(){ if(state.tab==='setup') renderContent(); });
  }
}

// ---- boot ----
document.getElementById('edr-tb-app').dataset.ready='1';
bootSetup();

})();
