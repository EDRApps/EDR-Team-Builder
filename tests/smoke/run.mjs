// Smoke test for the built Team Builder bundle (files/edr-team-builder/assets/builder.js).
// Opens tests/smoke/index.html in headless Chrome against the live site's public data and fails on
// any script error, "undefined"/"NaN" text, or a broken driver flow. Added in 3.1.6 after the blank
// Availability tab (3.0.7 deleted a helper that was still called) shipped and was found by a driver.
//
//   bash tests/smoke/fetch-fixtures.sh        # live public GETs into tests/smoke/fixtures/
//   npm i --no-save --prefix tests/smoke puppeteer-core@23
//   CHROME_PATH=/path/to/chrome node tests/smoke/run.mjs
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const require = createRequire(path.join(here, 'package.json'));
const puppeteer = require('puppeteer-core');

const fx = (n) => { try { return JSON.parse(fs.readFileSync(path.join(here, 'fixtures', n + '.json'), 'utf8')); } catch { return null; } };
const plan = fx('plan'), roster = fx('roster');
if (!plan || !plan.plan || !roster) {
  console.log('::warning::No live data in tests/smoke/fixtures (site unreachable?). Smoke test skipped.');
  process.exit(0);
}

// a driver who is in a car on the live plan, picked by data so no name is hard-coded in this public repo
const p = plan.plan, inCar = new Set();
Object.values(p.teams || {}).forEach((t) => ['pro', 'casual'].forEach((tier) => (t[tier] || []).forEach((car) => car.forEach((id) => inCar.add(id)))));
const nameOf = Object.fromEntries((p.drivers || []).map((d) => [d.id, d.name]));
const irid = Object.entries(roster.ids || {}).find(([, nm]) => [...inCar].some((id) => nameOf[id] === nm))?.[0];

const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png' };
const server = http.createServer((req, res) => {
  const f = path.join(root, decodeURIComponent(req.url.split('?')[0]));
  if (!f.startsWith(root) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); }
  res.writeHead(200, { 'Content-Type': types[path.extname(f)] || 'application/octet-stream' });
  fs.createReadStream(f).pipe(res);
}).listen(0, '127.0.0.1');
await new Promise((r) => server.once('listening', r));
const base = `http://127.0.0.1:${server.address().port}/tests/smoke/index.html`;

const chrome = process.env.CHROME_PATH || ['/usr/bin/google-chrome', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((c) => fs.existsSync(c));
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ['--no-sandbox'] });
const fails = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function open(label, query, { phone = false, tz = 'Australia/Brisbane' } = {}) {
  const page = await browser.newPage();
  page.on('pageerror', (e) => fails.push(`${label}: page error: ${e.message}`));
  await page.emulateTimezone(tz);
  await page.setViewport(phone ? { width: 375, height: 812, isMobile: true, hasTouch: true } : { width: 1280, height: 900 });
  await page.goto(base + query, { waitUntil: 'networkidle0' });
  await sleep(800);
  return page;
}
async function crawl(label, page) {
  const res = await page.evaluate(() => window.__crawl());
  if (res.length < 5) fails.push(`${label}: only ${res.length} tabs found`);
  for (const t of res) {
    if (t.errs.length) fails.push(`${label} / ${t.tab}: ${t.errs.join(' | ')}`);
    if (t.bad.length) fails.push(`${label} / ${t.tab}: bad text: ${t.bad.join(' | ')}`);
    if (t.over.length && label.includes('phone')) fails.push(`${label} / ${t.tab}: off the screen: ${t.over.join(', ')}`);
    if (t.h < 150) fails.push(`${label} / ${t.tab}: rendered almost nothing (${t.h}px)`);
  }
  console.log(`${label}: ${res.length} tabs checked`);
}

for (const [label, opts] of [['admin desktop', {}], ['admin phone', { phone: true }]]) {
  const page = await open(label, '?admin=1', opts);
  await crawl(label, page);
  // Stints: every car on desktop, exactly one (with a car picker) on a phone
  await page.evaluate(() => [...document.querySelectorAll('#edr-tb-app .tabs button')].find((b) => /^Stints/.test(b.textContent.trim()))?.click()); await sleep(500);
  const cars = await page.evaluate(() => { const all = [...document.querySelectorAll('.stintcar')]; return { all: all.length, shown: all.filter((x) => x.offsetParent).length }; });
  if (cars.all > 1 && cars.shown !== (opts.phone ? 1 : cars.all)) fails.push(`${label}: Stints shows ${cars.shown} of ${cars.all} cars`);
  await page.close();
}

if (!irid) fails.push('driver: no roster iRacing ID matches a driver in a car');
else {
  const label = 'driver phone (Sydney clock)';
  const page = await open(label, `?driver=1&irid=${irid}`, { phone: true, tz: 'Australia/Sydney' });
  const step = () => page.evaluate(() => (document.getElementById('edr-tb-app').innerText.match(/STEP (\d) OF 4/) || [])[1] || '');
  if ((await step()) !== '2') fails.push(`${label}: expected step 2 (race), got "${await step()}"`);
  await page.evaluate(() => document.querySelector('[data-action=wizrace]')?.click()); await sleep(600);
  const cells = await page.evaluate(() => document.querySelectorAll('.blockcell').length);
  if ((await step()) !== '3' || !cells) fails.push(`${label}: step 3 (your times) did not draw its blocks (${cells})`);
  await page.evaluate(() => [...document.querySelectorAll('#edr-tb-app button')].find((b) => /your stints/i.test(b.textContent))?.click()); await sleep(600);
  const s4 = await page.evaluate(() => { const t = document.getElementById('edr-tb-app').innerText; return { drive: /YOU DRIVE|not driving a stint yet/i.test(t), order: /DRIVING ORDER/i.test(t), sydney: /sydney/i.test(t) }; });
  if ((await step()) !== '4' || !s4.drive || !s4.order) fails.push(`${label}: step 4 (your stints) incomplete ${JSON.stringify(s4)}`);
  if (!s4.sydney) fails.push(`${label}: step 4 does not say the times are Sydney time`);
  // 3.3.0: a car with no captain offers "I'll captain this car"; tapping it is a request, not a captaincy
  const offered = await page.evaluate(() => { const o = document.querySelector('[data-action=capoffer]'); if (!o) return 'none'; o.click(); return 'clicked'; });
  if (offered === 'clicked') { await sleep(400);
    const ob = await page.evaluate(() => window.__posts.filter((x) => x.path === 'captain').map((x) => x.body)[0]);
    if (!ob || ob.action !== 'offer' || !ob.name || ob.pin) fails.push(`${label}: offer posted ${JSON.stringify(ob)}`);
    if (!/You offered to captain this car/i.test(await page.evaluate(() => document.getElementById('edr-tb-app').innerText))) fails.push(`${label}: offer not shown back`);
  } else if (!(p.captains && Object.keys(p.captains).length)) fails.push(`${label}: no "I'll captain this car" for a car without a captain`);
  const errs = await page.evaluate(() => window.__errs.slice());
  if (errs.length) fails.push(`${label}: ${errs.join(' | ')}`);
  console.log(`${label}: steps 2-4 checked`);
  await page.evaluate(() => [...document.querySelectorAll('#edr-tb-app a, #edr-tb-app button, #edr-tb-app [data-action]')].find((x) => /full planner/i.test(x.textContent))?.click()); await sleep(600);
  await crawl('driver phone full planner', page);
  await page.close();
}

// A device left in Full planner opens the Discord "#stints" link: it must still be able to confirm
if (irid && p.confirm && p.confirm.start) {
  const label = 'driver phone in Full planner (unconfirmed)';
  const shift = (p.confirm.start - 86400000 - Date.now()) / 3600000;   // a day before the green flag: confirmations open
  const page = await open(label, `?driver=1&irid=${irid}&view=full&noconfirm=1&shift=${shift}#stints`, { phone: true });
  const nm = nameOf[[...inCar].find((id) => nameOf[id] === roster.ids[irid])];
  const hasStints = !!(p.confirm.drivers[nm] && p.confirm.drivers[nm].stints && p.confirm.drivers[nm].stints.length);
  const banner = () => page.evaluate(() => { const b = document.querySelector('#content .cfnag'); return b ? b.innerText : ''; });
  if (!hasStints) console.log(`${label}: skipped (${nm} has no stints in this plan)`);
  else {
    if (!/haven't confirmed your stints/i.test(await banner())) fails.push(`${label}: no confirm banner on #stints`);
    for (const tab of ['Teams', 'Race day']) {
      await page.evaluate((t) => [...document.querySelectorAll('#edr-tb-app .tabs button')].find((b) => b.textContent.trim().startsWith(t))?.click(), tab); await sleep(300);
      if (!/haven't confirmed/i.test(await banner())) fails.push(`${label}: no banner on ${tab}`);
    }
    await page.evaluate(() => document.querySelector('#content [data-action=cfnaggo]')?.click()); await sleep(600);
    const t = await page.evaluate(() => document.getElementById('edr-tb-app').innerText);
    if (!/STEP 4 OF 4/i.test(t) || !/I'm good for these/i.test(t)) fails.push(`${label}: Confirm now did not land on step 4 with the confirm button`);
    await page.evaluate(() => document.querySelector('[data-action=cfok]')?.click()); await sleep(500);
    const cf = await page.evaluate(() => window.__posts.filter((x) => x.path === 'confirm').map((x) => x.body)[0]);
    if (!cf || cf.st !== 'ok') fails.push(`${label}: confirm not posted ${JSON.stringify(cf)}`);
    await page.evaluate(() => [...document.querySelectorAll('#edr-tb-app [data-action]')].find((x) => /full planner/i.test(x.textContent))?.click()); await sleep(500);
    if (await banner()) fails.push(`${label}: banner still there after confirming`);
    const errs = await page.evaluate(() => window.__errs.slice()); if (errs.length) fails.push(`${label}: ${errs.join(' | ')}`);
    console.log(`${label}: banner, Confirm now and answer checked`);
  }
  await page.close();
}

// 3.3.0 captains: the driver above as their car's captain, on a phone
if (irid) {
  const label = 'captain phone';
  const go4 = async (page) => {
    await page.evaluate(() => document.querySelector('[data-action=wizrace]')?.click()); await sleep(500);
    await page.evaluate(() => [...document.querySelectorAll('#edr-tb-app button')].find((b) => /your stints/i.test(b.textContent))?.click()); await sleep(700);
  };
  const txt = (page) => page.evaluate(() => document.getElementById('edr-tb-app').innerText);
  const capPosts = (page) => page.evaluate(() => window.__posts.filter((x) => x.path === 'captain').map((x) => x.body));
  const overflow = (page) => page.evaluate(() => { const W = document.documentElement.clientWidth, c = document.getElementById('capcard'); if (!c) return ['no #capcard'];
    return [...c.querySelectorAll('*')].filter((e) => e.offsetParent && e.getBoundingClientRect().right > W + 1).slice(0, 3).map((e) => e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0]); });
  // the clock a day before the green flag, so this keeps working after the live plan's race has been run
  const green0 = (p.confirm && p.confirm.start) || ((p.channels?.cars || [])[0]?.stints?.[0]?.s) || 0;
  const pre = green0 ? `&shift=${(green0 - 86400000 - Date.now()) / 3600000}` : '';
  const published = !!(p.teamsLocked && p.stintsLocked);
  let page = await open(label, `?driver=1&irid=${irid}&captain=1${pre}`, { phone: true });
  await go4(page);
  if (!/You're the captain of/i.test(await txt(page))) fails.push(`${label}: step 4 has no captain card`);
  await page.type('#cappin', '000000'); await page.evaluate(() => document.querySelector('[data-action=capunlock]').click()); await sleep(400);
  if (!/PIN is not right/i.test(await txt(page))) fails.push(`${label}: a wrong PIN was not refused`);
  await page.evaluate(() => { document.getElementById('cappin').value = ''; }); await page.type('#cappin', '123456');
  await page.evaluate(() => document.querySelector('[data-action=capunlock]').click()); await sleep(500);
  const t1 = await txt(page);
  if (!/WHO DRIVES WHAT/i.test(t1) || !/CONFIRMATIONS/i.test(t1)) fails.push(`${label}: captain tools did not open (${/WHO DRIVES WHAT/i.test(t1)}, ${/CONFIRMATIONS/i.test(t1)})`);
  const pinKept = await page.evaluate(() => localStorage.getItem('edrTB_cpin'));
  if (pinKept !== '123456') fails.push(`${label}: PIN not remembered on the device`);
  // give stint 1 to the next driver in the car, then save
  const moved = await page.evaluate(() => { const s = document.querySelector('select[data-action=capsel][data-i="0"]'); if (!s) return null;
    const cur = s.value, o = [...s.options].find((x) => x.value && x.value !== cur); s.value = o.value; s.dispatchEvent(new Event('change', { bubbles: true })); return { from: cur, to: o.value }; });
  await sleep(300);
  if (!published) console.log(`${label}: plan not published, stint moves skipped`);
  else if (!moved) fails.push(`${label}: no stint selects`);
  else {
    if (!/Save changes/i.test(await txt(page))) fails.push(`${label}: no Save button after a change`);
    await page.evaluate(() => document.querySelector('[data-action=capsave]').click()); await sleep(800);
    const sv = (await capPosts(page)).filter((b) => b.action === 'save')[0];
    if (!sv || String(sv.stints[0]) !== moved.to || String(sv.was[0]) !== moved.from || sv.pin !== '123456') fails.push(`${label}: save posted ${JSON.stringify(sv)}`);
    if (!/Saved\. The team channel/i.test(await txt(page))) fails.push(`${label}: no saved message`);
  }
  const ov = await overflow(page); if (ov.length) fails.push(`${label}: off the screen: ${ov.join(', ')}`);
  let errs = await page.evaluate(() => window.__errs.slice()); if (errs.length) fails.push(`${label}: ${errs.join(' | ')}`);
  console.log(`${label}: PIN, stint move and save checked`);
  await page.close();

  // mid-race: the race-day block and the captain's report (PIN remembered from a reload is checked quietly)
  const green = (() => { const c = (p.channels?.cars || [])[0]; return c?.stints?.[0]?.s || 0; })();
  if (green) {
    const lab2 = 'captain phone mid-race';
    const shift = ((green - Date.now()) / 3600000) + 2;
    page = await open(lab2, `?driver=1&irid=${irid}&captain=1&shift=${shift}`, { phone: true });
    await page.evaluate(() => localStorage.setItem('edrTB_cpin', '123456'));
    await go4(page); await sleep(600);
    const t2 = await txt(page);
    if (!/RACE DAY/i.test(t2) || !/Driver change done|Last stint|Finished/i.test(t2)) fails.push(`${lab2}: no race-day block`);
    if (!/CAPTAIN'S REPORT/i.test(t2)) fails.push(`${lab2}: no report form`);
    await page.type('input[data-capf=result]', 'P4 in class');
    await page.evaluate(() => document.querySelector('[data-action=capfol][data-v=mostly]').click()); await sleep(200);
    await page.type('textarea[data-capf=problems]', 'one disconnect');
    await page.evaluate(() => document.querySelector('[data-action=capreport]').click()); await sleep(600);
    const rp = (await capPosts(page)).filter((b) => b.action === 'report')[0];
    if (!rp || rp.result !== 'P4 in class' || rp.followed !== 'mostly' || rp.problems !== 'one disconnect') fails.push(`${lab2}: report posted ${JSON.stringify(rp)}`);
    if (!/Sent .*P4 in class/i.test(await txt(page))) fails.push(`${lab2}: report not shown back`);
    const ov2 = await overflow(page); if (ov2.length) fails.push(`${lab2}: off the screen: ${ov2.join(', ')}`);
    errs = await page.evaluate(() => window.__errs.slice()); if (errs.length) fails.push(`${lab2}: ${errs.join(' | ')}`);
    console.log(`${lab2}: race day + report checked`);
    await page.close();
  }

  // admin: pick a captain on Teams and make a PIN; Race week lists captains
  const lab3 = 'admin captains';
  page = await open(lab3, '?admin=1');
  await page.evaluate(() => [...document.querySelectorAll('#edr-tb-app .tabs button')].find((b) => /^Teams/.test(b.textContent.trim()))?.click()); await sleep(500);
  const picked = await page.evaluate(() => { const s = document.querySelector('select[data-action=capset]'); if (!s) return null; s.value = s.options[1].value; s.dispatchEvent(new Event('change', { bubbles: true })); return { key: s.dataset.key, id: s.options[1].value }; });
  await sleep(900);
  if (!picked) fails.push(`${lab3}: no captain picker on Teams`);
  else {
    const saved = await page.evaluate(() => window.__posts.filter((x) => x.path === 'plan').map((x) => x.body.plan).pop());
    if (!saved || String((saved.captains || {})[picked.key]) !== picked.id) fails.push(`${lab3}: captain not saved in the plan`);
    if (saved && String(((saved.evPlans || {})[saved.evsel] || {}).captains?.[picked.key]) !== picked.id) fails.push(`${lab3}: captain not in the race's own plan`);
    await page.evaluate(() => document.querySelector('[data-action=capnewpin]')?.click()); await sleep(400);
    if (!/654321/.test(await txt(page))) fails.push(`${lab3}: PIN not shown after Make PIN`);
    if (JSON.stringify(saved || {}).includes('654321')) fails.push(`${lab3}: PIN leaked into the plan`);
  }
  await page.evaluate(() => [...document.querySelectorAll('#edr-tb-app .tabs button')].find((b) => /Race week/i.test(b.textContent.trim()))?.click()); await sleep(500);
  if (!/CAPTAINS/i.test(await txt(page))) fails.push(`${lab3}: Race week has no Captains step`);
  errs = await page.evaluate(() => window.__errs.slice()); if (errs.length) fails.push(`${lab3}: ${errs.join(' | ')}`);
  console.log(`${lab3}: picker, PIN and Race week checked`);
  await page.close();
}

await browser.close(); server.close();
if (fails.length) { fails.forEach((f) => console.log('::error::' + f)); console.log(`\n${fails.length} problem(s).`); process.exit(1); }
console.log('\nSmoke test passed.');
