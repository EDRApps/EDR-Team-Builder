#!/usr/bin/env python3
import re, os
# Paths are repo-relative by default (works in CI); override with EDR_SRC / EDR_OUT.
_HERE = os.path.dirname(os.path.abspath(__file__))                      # files/edr-team-builder/build
SRC = os.environ.get("EDR_SRC", os.path.normpath(os.path.join(_HERE, "..", "..", "EDR-Team-Builder.html")))
OUT = os.environ.get("EDR_OUT", os.path.normpath(os.path.join(_HERE, "..", "assets")))
os.makedirs(OUT, exist_ok=True)
html=open(SRC,encoding="utf-8").read()

# ---- CSS ----
css=re.search(r"<style>(.*?)</style>", html, re.S).group(1).strip()
open(os.path.join(OUT,"builder.css"),"w",encoding="utf-8").write("/* EDR Team Builder styles (generated from EDR-Team-Builder.html) */\n#edr-tb-app{display:block}\n"+css+"\n"
  +".edr-setup .ctl{min-width:220px}\n.edr-setup select,.edr-setup input{padding:7px;font-size:13px}\n.edr-match{font-size:12px}\n"
  +"/* read-only mode for non-admin members */\n"
  +".readonly .controls{display:none}\n"
  +".readonly .tabs .tab[data-tab=\"setup\"]{display:none}\n"
  +".readonly #content select,.readonly #content .chip,.readonly #content [data-stintcell],.readonly #content button{pointer-events:none;opacity:.85}\n"
  +".readonly .x{display:none}\n")

# ---- SCRIPT ----
script=re.search(r"<script>(.*?)</script>", html, re.S).group(1)
# 1) drop embedded SAMPLE data
script=re.sub(r"const SAMPLE = \[.*?\];", "const SAMPLE = [];", script, count=1, flags=re.S)
# 2) make event params reassignable (set from import)
for c in ["WIN_START_MS","START_OFFSETS","START_LABELS"]:
    script=re.sub(r"const\s+"+c+r"\s*=", "let "+c+" =", script, count=1)
# 3) renderContent: add a Setup branch
script=script.replace(
  "el.innerHTML = state.tab==='teams' ? renderTeams(byId) : state.tab==='stints' ? renderStints(byId) : renderDrivers(model);",
  "el.innerHTML = state.tab==='setup' ? renderSetup() : state.tab==='teams' ? renderTeams(byId) : state.tab==='stints' ? renderStints(byId) : renderDrivers(model);")
# 4) cut original boot block
script=script.split("// ---- boot ----")[0]

APP_HTML = r"""
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
"""

APPEND = r"""
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
"""

logo_line = "const LOGO=(window.EDR_TB&&EDR_TB.logo)||'';"
app_js = "const APP_HTML=`"+APP_HTML.replace("`","\\`").replace("${LOGO}","${LOGO}")+"`;"
builder = ("/* EDR Team Builder (generated). Front-end logic adapted from EDR-Team-Builder.html. */\n"
  "(function(){\n"
  + logo_line + "\n"
  + app_js + "\n"
  + "const _app=document.getElementById('edr-tb-app'); if(!_app){return;} _app.innerHTML=APP_HTML;\n"
  + script + "\n"
  + APPEND + "\n"
  + "})();\n")
open(os.path.join(OUT,"builder.js"),"w",encoding="utf-8").write(builder)
print("wrote builder.css ("+str(os.path.getsize(os.path.join(OUT,'builder.css')))+" b) and builder.js ("+str(os.path.getsize(os.path.join(OUT,'builder.js')))+" b)")
