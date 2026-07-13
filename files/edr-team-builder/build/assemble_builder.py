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
  +".readonly .tabs .tab[data-tab=\"setup\"]{display:none}\n"
  +"""/* ---- theme armor: WordPress themes style input/button/select globally and bleed into
   the app (white slider boxes, theme-coloured buttons). Force ours back. ---- */
#edr-tb-app input[type=range]{-webkit-appearance:auto!important;appearance:auto!important;background:transparent!important;border:0!important;box-shadow:none!important;padding:0!important;margin:0!important;min-height:0!important;height:auto!important;width:100%!important;border-radius:0!important}
#edr-tb-app input[type=checkbox]{-webkit-appearance:auto!important;appearance:auto!important;background:transparent!important;border:none!important;box-shadow:none!important;padding:0!important;min-height:0!important}
#edr-tb-app select,#edr-tb-app textarea,#edr-tb-app input[type=text],#edr-tb-app input[type=number],#edr-tb-app input[type=time]{background:#0a0a14!important;color:#fff!important;border:1px solid var(--line)!important;border-radius:8px!important;box-shadow:none!important;text-shadow:none!important;line-height:1.4!important;min-height:0!important}
#edr-tb-app{color-scheme:dark}
#edr-tb-app optgroup{background:#11111c;color:var(--dim);font-style:normal}
#edr-tb-app button{box-shadow:none!important;text-shadow:none!important;text-decoration:none!important;letter-spacing:inherit;min-height:0!important}
#edr-tb-app button:focus{outline:2px solid rgba(240,240,0,.55);outline-offset:1px}
#edr-tb-app .tab{background:transparent!important;border:1px solid transparent!important;color:var(--dim)!important;border-radius:999px!important;padding:9px 18px!important;text-transform:uppercase!important}
#edr-tb-app .tab:hover{color:#fff!important;border-color:var(--line)!important}
#edr-tb-app .tab.active{color:#0a0a0a!important;background:var(--yellow)!important;border-color:var(--yellow)!important}
#edr-tb-app .btn-amber,#edr-tb-app .btn-green{background:var(--yellow)!important;color:#0a0a0a!important;border:none!important;border-radius:999px!important}
#edr-tb-app .btn-amber:hover,#edr-tb-app .btn-green:hover{background:var(--yellowH)!important}
#edr-tb-app .btn-ghost{background:transparent!important;color:var(--yellow)!important;border:1px solid rgba(240,240,0,.55)!important;border-radius:999px!important}
#edr-tb-app .btn-ghost:hover{background:var(--yellow)!important;color:#0a0a0a!important}
#edr-tb-app .chip{background:var(--panel2)!important;color:var(--body)!important;border:1px solid var(--line)!important;border-radius:999px!important}
#edr-tb-app .chip:hover{border-color:var(--yellow)!important;color:#fff!important}
#edr-tb-app .chip.calactive{background:var(--yellow)!important;color:#0a0a0a!important;border-color:var(--yellow)!important}
#edr-tb-app .winbtn{background:var(--panel)!important;color:var(--body)!important;border:1px solid var(--line)!important;border-radius:14px!important;text-transform:none!important}
#edr-tb-app .winbtn.sel{border-color:var(--yellow)!important;background:rgba(240,240,0,.07)!important}
#edr-tb-app .blockcell{background:#0a0a14!important;color:var(--body)!important;border:1px solid var(--line)!important;border-radius:10px!important;text-transform:none!important;padding:13px 6px!important;font-weight:400!important}
#edr-tb-app .blockcell.on{background:rgba(95,211,138,.18)!important;border-color:var(--green)!important;color:#fff!important;font-weight:700!important}
#edr-tb-app .x{background:none!important;border:none!important;color:var(--dim)!important}
#edr-tb-app a{text-decoration:none}
#edr-tb-app p{margin:0}
""")

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
  const prefs=((state.prefStore[evk]||{})[name])||null;
  return fetch(API+'avail',{method:'POST',headers:_hdrs(true),body:JSON.stringify({ev:evk,name:name,slots:slots,prefs:prefs,token:DEV_TOKEN})})
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
function serializePlan(){ return {drivers:state.drivers,w:state.w,proPct:state.proPct,teams:state.teams,stint:state.stint,stintAssign:state.stintAssign,stintWin:state.stintWin,stintSig:state.stintSig,overrides:overrides,meta:IMPORT_META,winStart:WIN_START_MS,startOffsets:START_OFFSETS,startLabels:START_LABELS,matches:lastMatches,trackIds:lastTrackIds,evsel:state.evsel,evWinMin:EV_WIN_MIN,evTiming:state.evTiming,teamsLocked:state.teamsLocked,stintsLocked:state.stintsLocked,teamNames:state.teamNames,fuelCfg:state.fuelCfg,customEvents:state.customEvents,irEvents:state.irEvents,evWeather:state.evWeather,prefStore:state.prefStore}; }
var _postBusy=false, _postAgain=false;
function _flushPlan(){
  if(_postBusy){ _postAgain=true; return; }              // serialize: the retry below re-posts the LATEST state with the updated rev
  _postBusy=true;
  apiPOST('plan',{plan:serializePlan(), baseRev:PLAN_REV}).then(function(r){
    _postBusy=false;
    if(r&&r.ok&&typeof r.rev==='number'){ PLAN_REV=r.rev; _postRetried=false; if(_postAgain){ _postAgain=false; _flushPlan(); } }
    else if(r&&r.code==='stale_plan'){ _postAgain=false; clearTimeout(_saveT); _saveT=null; refreshShared(true); }   // adopt latest; message shown after the pull actually lands
    else if(_postAgain){ _postAgain=false; _flushPlan(); }
  }).catch(function(){ _postBusy=false; if(!_postRetried){ _postRetried=true; setTimeout(_flushPlan, 1500); } else { _postAgain=false; } });
}
var _postRetried=false;
function save(){
  try{ localStorage.setItem('edrTB_local', JSON.stringify({role:state.role,me:state.me,pass:state.pass})); }catch(e){}
  if(!isAdmin()) return;
  clearTimeout(_saveT); _saveT=setTimeout(function(){ _saveT=null; _flushPlan(); }, 600);
}
var PLAN_REV=0;
function _adoptPlan(p, keepEvsel){
  var _localEv=keepEvsel?state.evsel:null;
  state.drivers=p.drivers; state.w=p.w||state.w; state.proPct=(typeof p.proPct==='number')?p.proPct:state.proPct; state.teams=p.teams||{};
  state.stint=Object.assign(state.stint,p.stint||{}); state.stintAssign=p.stintAssign||{}; state.stintWin=p.stintWin||{}; state.stintSig=p.stintSig||'';
  if(p.overrides)overrides=p.overrides; if(p.meta)IMPORT_META=p.meta; if(p.winStart)WIN_START_MS=p.winStart;
  if(p.startOffsets&&Object.keys(p.startOffsets).length)START_OFFSETS=p.startOffsets; if(p.startLabels&&Object.keys(p.startLabels).length)START_LABELS=p.startLabels;
  if(p.evsel)state.evsel=p.evsel; if(p.evWinMin)EV_WIN_MIN=p.evWinMin; if(p.evTiming)state.evTiming=p.evTiming; state.teamsLocked=!!p.teamsLocked; state.stintsLocked=!!p.stintsLocked;
  state.teamNames=p.teamNames||{}; if(p.fuelCfg)state.fuelCfg=p.fuelCfg; state.customEvents=p.customEvents||[]; state.irEvents=p.irEvents||[]; state.evWeather=p.evWeather||{}; state.prefStore=p.prefStore||{};
  lastMatches=p.matches||[]; lastTrackIds=p.trackIds||[];
  if(_localEv && _localEv!==state.evsel && calEvent(_localEv)){ state.evsel=_localEv; }   // don't yank the viewer off their selected event
}
function loadPlan(){ return apiGET('plan').then(function(r){ if(r&&typeof r.rev==='number') PLAN_REV=r.rev; var p=r&&r.plan; if(!p||!p.drivers||!p.drivers.length) return false; _adoptPlan(p); return true; }).catch(function(){return false;}); }
/* Multi-device convergence: when this tab wakes up (phone unlocked, tab refocused), pull the
   latest shared state if the server revision moved instead of sitting on stale weekend state.
   Hardened per adversarial review: monotonic rev guard, re-check for local edits when the GET
   RESOLVES (not just at entry), per-event availability merge that honours staged ticks, defer
   while an inline edit has focus, and a force mode for 409 recovery. */
var _refreshLast=0;
function _editingNow(){ try{ if(typeof _renaming!=='undefined'&&_renaming!==null) return true; var ae=document.activeElement, c=document.getElementById('content'); return !!(ae&&c&&c.contains(ae)&&/^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName)); }catch(e){ return false; } }
function refreshShared(force){
  var now=Date.now();
  if(!force && now-_refreshLast<2500) return;           // visibilitychange+focus both fire on wake — one fetch is enough
  _refreshLast=now;
  if(!force && (_saveT||_postBusy)) return;             // our own save is queued/in flight; it will reconcile
  apiGET('plan').then(function(r){
    if(!(r&&r.plan&&r.plan.drivers&&r.plan.drivers.length)) return;
    var rev=(typeof r.rev==='number')?r.rev:null;
    if(rev===null) return;                              // legacy PHP (deploy skew): never blind-adopt on focus
    if(rev<=PLAN_REV && !force) return;                 // monotonic: a late stale GET must not rewind us
    if(!force && (_saveT||_postBusy)) return;           // an edit landed while this GET was in flight — let its save reconcile
    if(!force && _editingNow()){ setTimeout(function(){ refreshShared(); },4000); return; }  // don't rip an open input out from under the user
    PLAN_REV=rev;
    var _localNames=state.teamNames||{};
    _adoptPlan(r.plan, true);
    var _renamedBack=false; Object.keys(_localNames).forEach(function(k){ if(_localNames[k] && !state.teamNames[k]){ state.teamNames[k]=_localNames[k]; _renamedBack=true; } });   // a rename in flight when another device saved must survive the adopt
    if(_renamedBack && isAdmin()) save();
    if(state.evsel){ var _ev=calEvent(state.evsel); if(_ev){ var _r0=state.stint.race; applyEventTiming(_ev); if(_r0>0) state.stint.race=_r0; } }
    apiGET('avail').then(function(av){
      if(av&&av.store){
        var dirty={}; Object.keys(_availDirty||{}).forEach(function(k){ if(Object.keys(_availDirty[k]||{}).length) dirty[k]=1; });
        Object.keys(av.store).forEach(function(evk){ if(!dirty[evk]) state.availStore[evk]=av.store[evk]; });   // never wipe staged, unsubmitted ticks — any event
        if(av.prefs) Object.keys(av.prefs).forEach(function(evk){ if(!dirty[evk]) state.prefStore[evk]=av.prefs[evk]; });
        LOCKED_NAMES=av.locked||[];
      }
    }).catch(function(){}).then(function(){ applyAvailToDrivers(); renderContent(); setStatus(force?'Another device updated the plan — showing the latest; re-apply your last change.':'Synced the latest plan from the server.'); });
  }).catch(function(){});
}
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
  var evDate=ev.s?Date.parse(ev.s+'T00:00:00Z'):NaN;
  var best=null,bs=-1;
  IR_SEASONS.forEach(function(se){
    var nn=nk(se.name), tk=nk(se.track), sc=0;
    // token overlap on the event name
    (ev.n.toLowerCase().match(/[a-z0-9]+/g)||[]).forEach(function(w){ if(w.length>2 && nn.indexOf(w)>=0) sc+=2; });
    if(evtk && tk && (tk.indexOf(evtk.slice(0,8))>=0 || evtk.indexOf(tk.slice(0,8))>=0)) sc+=3;
    // date proximity is decisive: an official round runs in its own calendar week. Without it,
    // same-track different-series rounds collide (Spa 24HR was auto-applied to Creventic 12H Spa).
    if(se.start_date && !isNaN(evDate)){
      var sd=Date.parse(se.start_date+'T00:00:00Z');
      if(!isNaN(sd)){ var dd=Math.abs(sd-evDate)/86400000;
        if(dd<=2) sc+=4; else if(dd<=6) sc+=2; else return;   // a week apart = a different round
      }
    }
    // race-length sanity: a 12h event must not adopt a 24h session's times
    if(se.race_min && ev.dur){ var dm=Math.abs(se.race_min-(ev.dur*60)); if(dm<=30) sc+=2; else if(dm>=120) sc-=3; }
    if(sc>bs){ bs=sc; best=se; }
  });
  return bs>=5?best:null;
}
function autoApplyTiming(){  // auto-apply official session times to the selected event (once; a manual pick or revert wins)
  try{
    if(!state.evsel || !IR_SEASONS.length) return;
    var ev=calEvent(state.evsel); if(!ev) return;
    if(state.evTiming && state.evTiming[state.evsel]) return;   // already applied / manually set / deliberately reverted — leave it
    if(state.stintsLocked) return;                              // locked stint plan: timing changes only via the manual Apply
    var se=irMatchFor(ev); if(!se || !(se.sessions&&se.sessions.length)) return;
    if(applyIrTiming(ev, se)){ applyEventTiming(ev); state.stintAssign={}; state.stintWin={}; state.stintSig=''; applyAvailToDrivers(); save(); setSetupMsg('Auto-applied official session times from "'+se.name+'".'); renderContent(); }
  }catch(e){}
}
function autoApplyWeather(){  // auto-pull: match the selected event to an iRacing season and apply its weather (manual override wins)
  try{
    if(!state.evsel || !IR_SEASONS.length) return;
    var ev=calEvent(state.evsel); if(!ev) return;
    var cur=state.evWeather[state.evsel];
    if(cur && cur.src==='manual') return;
    var se=irMatchFor(ev);
    if(se && se.weather){ applyIrWeather(ev, se); save(); if(state.tab==='stints') renderContent(); }
  }catch(e){}
}
var _selectEvent0=selectEvent; selectEvent=function(k){ _selectEvent0(k); autoApplyTiming(); autoApplyWeather(); };   // picking an event auto-applies its iRacing session times + weather
function syncIrEvents(){  // F1: pull whatever endurance events the proxy exposes and merge into the calendar
  _evSyncMsg='Syncing iRacing…'; renderContent();
  loadIracing().then(function(){
    var existing={}; CAL_EVENTS.concat(state.customEvents||[]).forEach(function(e){ existing[evKey(e)]=1; });
    var fresh=[], added=0, have=0;
    (IR_SEASONS||[]).forEach(function(se){
      if(!se.name||!se.start_date) return;
      var dur=se.race_min?Math.max(1,Math.round(se.race_min/60)):6;
      var isEnd=/endur|24|12\s*h|le mans|petit|creventic|global endurance|imsa|nurburg|bathurst|sebring|spa|daytona|suzuka|road america/i.test(se.name);
      var ev={n:se.name, track:se.track||'', s:se.start_date, e:se.start_date, cars:'GT3', cat:isEnd?'endurance':'other', dur:dur, special:isEnd, src:'iracing', raceMin:se.race_min||0};
      if(existing[evKey(ev)]){ have++; return; }
      fresh.push(ev); if(se.weather) applyIrWeather(ev, se); added++;
    });
    state.irEvents=fresh; save(); autoApplyTiming(); autoApplyWeather();
    _evSyncMsg = IR_STATUS ? IR_STATUS : ('Synced: added '+added+' iRacing event'+(added===1?'':'s')+(have?', '+have+' already on the calendar':'')+'.');
    renderContent();
  }).catch(function(){ _evSyncMsg='iRacing unavailable right now.'; renderContent(); });
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
  var prevByKey={}; (state.drivers||[]).forEach(function(d){ prevByKey[nameKey(d.name)]=d; });   // keep admin-locked car choices across G61 imports
  (payload.roster||[]).forEach(function(r){
    var prev=prevByKey[nameKey(r.name)];
    var locked=!!(prev&&prev.carLock);
    var keep=(locked&&prev.assignedCar&&r.cars&&r.cars[prev.assignedCar])?prev.assignedCar:null;
    drivers.push({id:id++, name:r.name, cars:r.cars, assignedCar:keep||lastCar(r.cars), avail:null, irating:(typeof r.irating==='number'?r.irating:(prev?prev.irating:null)), carLock:locked});
  });
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
  h+='<div class="importbox"><div class="meta" style="margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em">Official iRacing session times — applied automatically when a round matches; pick below to override</div>';
  if(!selEv){ h+='<div class="meta">Select a target event on the Event tab to pull its official session start times and race length.</div>'; }
  else {
    var cur=state.evTiming&&state.evTiming[evKey(selEv)];
    var match=irMatchFor(selEv);
    h+='<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">';
    h+='<select data-s="irsel"><option value="">(pick the matching iRacing event)</option>'+IR_SEASONS.map(function(se,i){var sel=(match&&se===match)?' selected':''; return '<option value="'+i+'"'+sel+'>'+esc(se.name)+' · '+esc(se.track)+(se.race_min?' · '+Math.round(se.race_min/60)+'h':'')+'</option>';}).join('')+'</select>';
    h+='<button class="btn btn-amber" data-s="irapply">Apply to '+esc(selEv.n)+'</button>';
    h+='<span class="meta" data-s="irrefresh" style="cursor:pointer;text-decoration:underline">refresh</span>';
    if(!match && IR_SEASONS.length) h+='<div class="meta" style="margin-top:6px;width:100%">No iRacing round matches '+esc(selEv.n)+' yet (same week + similar race length required) — rounds usually appear close to race week. Pick one manually above, or refresh.</div>';
    h+='</div>';
    if(cur&&cur.offsets) h+='<div class="meta" style="margin-top:8px;color:var(--green)">Using official times'+(cur.src?' from "'+esc(cur.src)+'"':'')+' — '+Object.keys(cur.offsets||{}).length+' starts, '+Math.round((cur.raceMin||0)/60)+'h race. <span data-s="irclear" style="cursor:pointer;text-decoration:underline;color:var(--red)">revert to calendar</span></div>';
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
    if(selEv){ state.evTiming[evKey(selEv)]={src:'calendar'};   /* tombstone: blocks auto re-apply after a deliberate revert */ applyEventTiming(selEv); state.stintAssign={}; state.stintWin={}; state.stintSig=''; applyAvailToDrivers(); save(); setSetupMsg('Reverted to calendar session times.'); renderContent(); }
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
  if(state.evsel){ var _bev=calEvent(state.evsel); if(_bev){ var _br=state.stint.race; applyEventTiming(_bev); if(_br>0) state.stint.race=_br; } }  // rebuild timing globals from the restored event (don't trust persisted globals)
  try{
    const av=await apiGET('avail');
    if(av&&av.store){ state.availStore=av.store; LOCKED_NAMES=av.locked||[]; if(av.prefs)state.prefStore=av.prefs; }
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
    loadIracing().then(function(){ autoApplyTiming(); autoApplyWeather(); if(state.tab==='setup') renderContent(); });
  }
}

// ---- boot ----
document.getElementById('edr-tb-app').dataset.ready='1';
document.addEventListener('visibilitychange', function(){ if(document.visibilityState==='visible') refreshShared(); });
window.addEventListener('pagehide', function(){ if(_saveT){ clearTimeout(_saveT); _saveT=null; try{ fetch(API+'plan',{method:'POST',headers:_hdrs(true),body:JSON.stringify({plan:serializePlan(),baseRev:PLAN_REV}),keepalive:true}); }catch(e){} } });   // a rename made just before closing the tab must not die in the 600ms debounce
window.addEventListener('focus', function(){ refreshShared(); });
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
