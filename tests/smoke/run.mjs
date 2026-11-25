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
  const s4 = await page.evaluate(() => { const t = document.getElementById('edr-tb-app').innerText; return { drive: /YOU DRIVE|not driving a stint yet/i.test(t), order: /DRIVING ORDER/i.test(t), sydney: /Sydney/.test(t) }; });
  if ((await step()) !== '4' || !s4.drive || !s4.order) fails.push(`${label}: step 4 (your stints) incomplete ${JSON.stringify(s4)}`);
  if (!s4.sydney) fails.push(`${label}: step 4 does not say the times are Sydney time`);
  const errs = await page.evaluate(() => window.__errs.slice());
  if (errs.length) fails.push(`${label}: ${errs.join(' | ')}`);
  console.log(`${label}: steps 2-4 checked`);
  await page.evaluate(() => [...document.querySelectorAll('#edr-tb-app a, #edr-tb-app button, #edr-tb-app [data-action]')].find((x) => /full planner/i.test(x.textContent))?.click()); await sleep(600);
  await crawl('driver phone full planner', page);
  await page.close();
}

await browser.close(); server.close();
if (fails.length) { fails.forEach((f) => console.log('::error::' + f)); console.log(`\n${fails.length} problem(s).`); process.exit(1); }
console.log('\nSmoke test passed.');
