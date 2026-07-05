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
  +"/* generic read-only rules live in the HTML <style>; WP only hides the Setup tab for non-admins */\n"
  +".readonly .tabs .tab[data-tab=\"setup\"]{display:none}\n")

# ---- SCRIPT ----
script=re.search(r"<script>(.*?)</script>", html, re.S).group(1)
# 1) drop embedded SAMPLE data
script=re.sub(r"const SAMPLE = \[.*?\];", "const SAMPLE = [];", script, count=1, flags=re.S)
# 2) timing globals (WIN_START_MS, START_OFFSETS, START_LABELS) and the season calendar
#    (CAL_EVENTS) now live in the HTML as reassignable `let`s and are shared by both builds —
#    nothing to strip or rewrite. Guard against the collision that broke 2.0.2:
assert "const EVENTS" not in script, "standalone script declares const EVENTS — collides with the WP layer's `let EVENTS`"
# 3) renderContent: add a Setup branch
script, n = re.subn(
  r"el\.innerHTML = state\.tab==='event' \? renderEventTab\(\)",
  "el.innerHTML = state.tab==='setup' ? renderSetup() : state.tab==='event' ? renderEventTab()",
  script, count=1)
assert n == 1, "renderContent tab routing not found — check the el.innerHTML marker in EDR-Team-Builder.html"
# 4) cut original boot block
script=script.split("// ---- boot ----")[0]

APP_HTML = r"""
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
"""

APPEND = r"""
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
    try{ TRACKS=await apiGET('tracks'); }catch(e){ TRACKS=[]; }
    try{ EVENTS=await apiGET('events'); }catch(e){ EVENTS=[]; }
    preselectNearest();
    renderContent();
  })();
}
/* per-driver availability syncs through its own public route, not the plan.
   Returns the fetch promise so the Submit button can await it and confirm. */
function persistAvail(evk,name){
  const slots=((state.availStore[evk]||{})[name])||[];
  return fetch(API+'avail',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ev:evk,name:name,slots:slots})})
    .then(function(r){ if(!r.ok) throw new Error('avail save failed'); return r.json(); });
}
let lastTrackIds=[], _saveT=null;
function serializePlan(){ return {drivers:state.drivers,w:state.w,proPct:state.proPct,teams:state.teams,stint:state.stint,stintAssign:state.stintAssign,stintWin:state.stintWin,stintSig:state.stintSig,overrides:overrides,meta:IMPORT_META,winStart:WIN_START_MS,startOffsets:START_OFFSETS,startLabels:START_LABELS,matches:lastMatches,trackIds:lastTrackIds,evsel:state.evsel,evWinMin:EV_WIN_MIN}; }
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
  if(p.evsel)state.evsel=p.evsel; if(p.evWinMin)EV_WIN_MIN=p.evWinMin;
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
  if(!state.evsel){
    /* no calendar event selected: take timing from the iRacePlan payload (legacy path).
       With an event selected, the Event tab owns WIN_START_MS / START_OFFSETS. */
    if(payload.window_start) WIN_START_MS=Date.parse(payload.window_start);
    START_OFFSETS={}; START_LABELS={}; (payload.candidate_starts||[]).forEach(c=>{ START_OFFSETS[c.n]=c.offset; START_LABELS[c.n]=(c.iso||'').slice(11,16)+'Z'; });
    if(!Object.keys(START_OFFSETS).length){ START_OFFSETS={1:0,2:540,3:840,4:1080,5:1560}; START_LABELS={1:'22:00Z',2:'07:00Z',3:'12:00Z',4:'16:00Z',5:'00:00Z'}; }
  }
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
      const bc=buildCars(r,a.prefs);
      drivers.push({id:id++, name:a.name, cars:bc, assignedCar:fastestCar(bc), avail:computeAvail(a.windows)});
    });
  } else {
    (payload.roster||[]).forEach(r=>{ drivers.push({id:id++, name:r.name, cars:r.cars, assignedCar:fastestCar(r.cars), avail:null}); });
  }
  lastMatches=matches; state.drivers=drivers; state.stintAssign={}; state.stintSig=''; state.stintWin={};
  applyAvailToDrivers();  // our own availability submissions overlay the iRacePlan data
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
  const selEv=state.evsel?calEvent(state.evsel):null;
  const evTrackIds=(selEv&&selEv.g61Tracks)||null;
  let h='<div class="edr-setup">';
  h+='<div class="importbox" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">';
  if(evTrackIds){
    const tnames=evTrackIds.map(function(id){ const t=(TRACKS||[]).find(x=>x.id===id); return t?(t.name+(t.variant?' - '+t.variant:'')):('#'+id); });
    h+='<div class="ctl"><label>TRACK — from the selected event</label><div style="font-size:13px;color:#fff;padding:6px 0">'+esc(selEv.n)+': '+esc(tnames.join(' + '))+'</div></div>';
  } else {
    h+='<div class="ctl"><label>TRACK (Garage 61)</label><select data-s="track">'+(TRACKS?TRACKS.map(t=>'<option value="'+t.id+'"'+(t.id===SEL_TRACK?' selected':'')+'>'+esc(t.name+(t.variant?' - '+t.variant:''))+'</option>').join(''):'<option>loading...</option>')+'</select></div>';
  }
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
  if(s==='import'){
    const selEv=state.evsel?calEvent(state.evsel):null;
    const evIds=(selEv&&selEv.g61Tracks)||null;
    const tEl=document.querySelector('[data-s=track]'); const eEl=document.querySelector('[data-s=event]');
    const ids=evIds||(tEl?[parseInt(tEl.value,10)]:[]);
    if(ids.length) doImport(ids, (eEl&&eEl.value)?parseInt(eEl.value,10):0);
  }
  else if(s==='dopaste'){ const p=document.querySelector('[data-s=paste]'); applyPaste(p.value); }
});

async function bootSetup(){
  try{ const lp=JSON.parse(localStorage.getItem('edrTB_local'))||{}; if(lp.role) state.role=lp.role; if(lp.me) state.me=lp.me; if(lp.pass) state.pass=lp.pass; }catch(e){}
  if(CAN) state.role='admin';
  document.getElementById('rolebar').addEventListener('click',function(e){
    const b=e.target.closest&&e.target.closest('[data-role-action]');
    if(!b) return;
    if(b.dataset.roleAction==='unlock') unlockAdmin(); else lockAdmin();
  });
  renderRolebar();
  syncControls();
  state.tab = CAN ? 'setup' : 'event'; setActiveTab();
  var ok=false; try{ ok=await loadPlan(); }catch(e){}
  try{ const av=await apiGET('avail'); if(av&&typeof av==='object'&&!Array.isArray(av)) state.availStore=av; }catch(e){}
  try{ const tr=await apiGET('roster'); if(Array.isArray(tr)&&tr.length) TEAM_ROSTER=tr; }catch(e){}
  applyAvailToDrivers();
  if(ok && state.drivers.length && !Object.keys(state.teams||{}).length) generate();
  renderContent();
  if(isAdmin()){
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
