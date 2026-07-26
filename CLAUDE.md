# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Internal planning system for **Endurotech Racing (EDR)**, a GT3/GTP iRacing endurance team. It
turns real performance + availability data into Pro/Casual team line-ups, stint rotations, and
EDR-branded race deliverables (car-selection briefs, strategy sheets, debriefs). The repo lives
in iCloud (`EDR/Planning`); paths contain spaces — quote them.

## Two faces of the same tool (the key architectural fact)

The Team Builder exists twice, and one is **generated** from the other:

- **`files/EDR-Team-Builder.html`** — the standalone single-file app and the **source of truth
  for all front-end logic** (season calendar, availability collection, roles, scoring, Pro/Casual
  split, stints, per-car sessions, drag-drop, EDR theme). Ships with the current event's data
  embedded as a `SAMPLE` const; state persists in `localStorage`. (~100 KB with a base64 logo +
  one huge `SAMPLE` line — the Read tool chokes on it; use `grep`/`sed`/`awk`, exact-string
  `Edit`, or python splices instead of reading it whole.)
- **`files/edr-team-builder/`** — the same tool as a **WordPress plugin**. Its
  `assets/builder.js` + `builder.css` are **built from the HTML** by
  `build/assemble_builder.py` (strips `SAMPLE`, rewrites the boot block, makes the event
  globals reassignable, appends the WP import/Setup/shared-plan layer). **Never hand-edit
  `builder.js`/`builder.css`** — they're overwritten on rebuild. Change behaviour in the HTML
  (core logic) or in the `APPEND`/`APP_HTML` string literals in `assemble_builder.py` (WP glue).
  The plugin has its own deeper [CLAUDE.md](files/edr-team-builder/CLAUDE.md).

## Build & release (the plugin)

```bash
python3 files/edr-team-builder/build/assemble_builder.py   # regenerate builder.js/css from the HTML
```
- Source/output paths are env-overridable: `EDR_SRC` / `EDR_OUT` (defaults are repo-relative).
- **Always parse-check the generated `builder.js` after a rebuild.** The assembler is string
  concatenation inside one IIFE, so a new top-level `const` in the HTML that collides with the WP
  `APPEND` layer (e.g. the old `const EVENTS`) is a SyntaxError that kills the app with no console
  output. **The assembler now catches this whole class itself** (`_fatal_clash`): it compares
  top-level declarations in the HTML against the WP `APPEND` layer and fails the build on any
  overlap involving `let`/`const`. Duplicate `function` declarations still pass — that is the
  deliberate stub-override pattern (`persistAvail`, `verifyAdminPass`, `pruneOldEvents`, …).
  To use a WP-layer global from HTML code, probe it with `typeof NAME` rather than declaring it.
  Node **is** installed (v22) — `node --check files/edr-team-builder/assets/builder.js` is
  the quickest check. To exercise behaviour, the established pattern is a `wp-test.html` harness
  with fetch stubs for `/plan`, `/avail`, `/roster`, `/auth`, `/tracks`, `/import`, `/iracing`;
  build it outside the repo and serve it (the bundle is IIFE-wrapped, so drive it through the DOM
  — its internals are not reachable from the console). The assembler asserts its HTML markers
  exist and fails loudly if they move.
- `python3 files/edr-team-builder/build/package_plugin.py` rebuilds **and** writes the uploadable
  zip to `Admin Folder/edr-team-builder-V2.zip` (and syncs `HANDOFF.md` beside it). `EDR_ZIP`
  overrides the path. That zip is what gets uploaded to WordPress, so regenerate it whenever the
  plugin version changes — **CI fails if its version does not match `edr-team-builder.php`**.
- **CI** (`.github/workflows/ci.yml`): on push/PR, rebuilds assets, **fails if the committed
  assets or zip are stale**, parse-checks the bundle, lints PHP, uploads the zip artifact.
- **Release** (`.github/workflows/release.yml`): pushing a `v*` tag builds + publishes a GitHub
  Release with `edr-team-builder-<version>.zip` (version stamped from the tag). Bump the version
  in `edr-team-builder.php` to match the tag.

There is **no test suite or package manifest** for the repo as a whole, but both linters the
code actually needs are on this machine now: `node --check <bundle>` for the generated
JavaScript and `php -l <file>` for the plugin (PHP 8.3, installed via winget — if `php` is not
on PATH in a fresh shell it lives under
`%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_*\php.exe`). Run both before calling a
change done; CI runs the same two.

## Staying live (2.4.12)

`GET /rev` is a heartbeat every open tab polls every 20s: `{plan, avail, paceAt, paceErr}` from
three option reads. Clients pull the full `/plan` or `/avail` only when a revision moved, do not
poll at all while the tab is hidden, check immediately on focus, and back off to 3 min on
repeated failure. Availability writes bump `edr_tb_avail_rev`, so a driver submitting blocks
lands on everyone else's screen within ~20s.

Garage 61 pace is **warmed on a schedule, never auto-applied**: the heartbeat queues a
background `wp_schedule_single_event` pull when the cache is stale (hourly within 10 days of the
selected event, daily otherwise, transient-debounced), and `POST /import` serves that cache when
it matches the tracks. Auto-applying pace would silently reshuffle a settled line-up, so the
admin still presses Import. Details in the plugin's [CLAUDE.md](files/edr-team-builder/CLAUDE.md).

## Team Builder workflow & roles (v2.4)

Tab order is the planning flow: **Instructions → Event → Availability → Drivers → Teams →
Stints** (WP adds an admin-only Setup tab in front). Instructions is the landing tab and is the
driver-facing how-to, including the swap contact (2.4.8). Key facts:

- **Events** come from the `CAL_EVENTS` array in the HTML (shared by both builds — edit it there).
  Each event can carry `winStart/winMin/raceMin/offsets/labels` (Spa has the survey-accurate set),
  `g61Tracks` (Garage 61 track ids the Setup import pulls automatically; a manual-picker override
  exists), and is derived-timing otherwise. Selecting an event rewrites `WIN_START_MS`/
  `START_OFFSETS`/`START_LABELS`, race length, and resets the stint plan. The selected event heads
  the page. **Per-event timing overrides** live in `state.evTiming[evKey]` (persisted in the
  plan) — `applyEventTiming()` prefers them over the `CAL_EVENTS` defaults; `applyIrTiming()`
  writes them from official iRacing session times (see the plugin CLAUDE.md).
- **Availability is strictly per event**: blocks over the event window, stored in
  `state.availStore[evKey][driverName]` (server-side in `edr_tb_avail` via public
  `GET/POST /avail`). `AV_BLOCK` defaults to 120 min but is **per event** since 2.4.11
  (`state.evTiming[evKey].avBlock`, admin buttons on the Availability tab). Stored ticks are
  slot *indexes*, so the size is part of how they are read — `avSetBlock()` rescales through
  real minute windows rather than reinterpreting, and **coarsening uses the strict
  fully-covered rule** so a rescale can never hand back availability a driver did not give. Drivers tick either the mobile-friendly **block picker** (`renderMyBlocks`,
  with Tick all / Clear) or **their own column in the wide matrix** (`canEditAvail(nm)` gates the
  checkbox) — both edit the same data; other drivers' columns stay read-only, and the matrix is
  the full admin edit surface.
  Ticks stage locally; **Submit** persists. `slotsToAvail()` ↔ `windowsToSlots()` convert to/from
  the `{hours, pct, starts, windows}` shape scoring/stints use. Switching events wipes carried-over
  `d.avail`. **Writes are advisory** (2.4.2 onwards): any device may edit any driver's blocks and
  the team runs on trust. The old hard lock (first device to submit a name owns it, device token
  hashed into `edr_tb_avail_owners`) was removed because it kept locking drivers out of their own
  availability on a phone→PC switch; with no accounts the server cannot tell "same person, new
  device" from "someone else". `DEV_TOKEN`, `LOCKED_NAMES` and the `release` param survive as
  accepted-and-ignored vestiges so older clients keep working — `edr_tb_avail_owners` is dead.
  The route is public but bounded: `ev` must match `<name>|<YYYY-MM-DD>`, and new keys are
  refused past `EDR_TB_MAX_EVENTS`/`EDR_TB_MAX_DRIVERS` so the option cannot grow without limit.
  Writes take `GET_LOCK('edr_tb_avail_write')` (concurrent submits used to silently drop one).
  Drivers get bulk pickers (per-day, time-of-day, copy-from-last-event matched on local
  weekday+hour), drag-to-paint on mouse/pen only (touch keeps tap, so the page can still
  scroll), a **sticky Submit bar rendered as the last child of the tab** (`position:sticky`
  only holds inside its own containing block — nested in the header panel it vanished exactly
  when you needed it), and an "already in" line from the server's `seen` stamps.
- **The event pool rule** (same as the old survey rule): with an event selected,
  Drivers/Teams/Stints only include drivers with `avail.hours > 0` for that event. Anyone who
  submits availability joins the pool (no pace until the next import). **Self-identification is
  by iRacing customer ID** where the roster supplies one (2.4.7): `GET /roster` returns
  `{names, ids}`, the builder fills `ROSTER_IDS`, and a driver typing their ID is resolved via
  `GET /roster?lookup=<id>` (a cache miss re-pulls membership at most once per 10 min) and
  remembered in `localStorage`. Only when no IDs are available — which is the case in the
  standalone build — does it fall back to picking a name from the live Garage 61 membership
  (both EDR teams, cached 6 h) with a baked-in `TEAM_ROSTER` fallback; `nameKey()` +
  `NAME_ALIASES` dedupe survey-vs-G61 spellings.
- **Roles**: default is read-only driver. Admin unlock is an **inline header password field**
  (never `window.prompt` — it is blocked in embedded/iframe views). Admin = WP login, or the
  builder admin password: standalone checks `ADMIN_HASH` in the file (password `edr2026`); WP
  verifies the Settings `edit_pass` server-side (`POST /auth`, sent as `X-EDR-Pass` on writes,
  rate-limited to `EDR_TB_MAX_PASS_FAILS` wrong tries per IP per 10 min). **The standalone
  `ADMIN_HASH` check is a convenience gate, not a security boundary** — the hash is in the file
  and the comparison runs in the browser, so anyone with the file can bypass it. Only the WP
  build enforces anything.
  Drivers-tab car dropdowns default to each driver's fastest-median car (`fastestCar()`), listed
  fastest-first. WP CSS is `!important`-armored under `#edr-tb-app` so themes can't bleed in.
- The full REST surface and role model are documented in the plugin's
  [CLAUDE.md](files/edr-team-builder/CLAUDE.md).

## Data sources and their hard constraints

- **Garage 61** (pace/telemetry) — base `https://garage61.net/api/v1`, `Authorization: Bearer`.
  Pull `/laps` with `tracks`, `teams=edr-endurotech`, `unclean=true`, `group=none`, **`age=-1`**.
  `age=-1` scopes to the **current iRacing season (Season 3 onwards) only** — keep this window on
  every pace query (project convention). Per-lap fields include `lapTime` (s), `clean`,
  `incomplete`, `pitIn/Out`, `fuelUsed`, `fuelLevel`, `trackUsage` (rubber %), `trackTemp`,
  `startTime` (UTC), and `driver` (whose `slug` is the stable id; build display names from
  `firstName`+`lastName`). `colab_pull_garage61.py` is the reference pull (paged `get_paged` + tally).
  Query mechanics: `age=-1` = current season; **`age=N` = last N days** (verified: `age=7`/`age=40`
  filter server-side by day-count — use this for month-scoped pulls, e.g. `age=36` then filter
  client-side to the calendar month in Brisbane time); **omit `age` for all-time** history. `tracks`
  is **required** and accepts a **comma-separated list** of IDs (from `/tracks`; ~468 exist) — but
  only **~10 IDs per request** (10 works, 20 is a 400); sweep more tracks in chunks (a full 47-chunk
  sweep with `age=36` takes ~2 min and is rate-limit safe at 0.3 s pacing). Max
  `limit` is **1000** (higher values are silently clamped). There is **no aggregate/count
  endpoint** — paginate `/laps` and tally client-side; `group=driver` returns one best lap per
  driver (no counts) and `/teams/edr-endurotech` members carry iRatings but not lap counts.
  There is **no per-driver filter**: the `drivers` param rejects slugs, driver ULIDs, and iRacing
  customer IDs — only the literal `drivers=me` is accepted. To compare specific drivers, pull the
  team's laps and tally locally. The team's full all-time history is ~194k laps (~13 min pull at
  1000/page with 0.3 s pacing, safely under the rate limiter). `/teams/edr-endurotech` members
  carry `accounts[]` with each driver's **iRacing customer ID** plus current iRating/Safety Rating
  in all six licence categories — this is the join key between Garage 61 names and official
  iRacing results.
- **iRacePlan** (availability) — base `https://iraceplan.com/api/v1`. **Survey responses are NOT
  in the API** (only counts). Full availability is reachable only via the **plannings API** once
  a team planning exists, or by the **bookmarklet** that scrapes the logged-in survey timeline
  (`files/edr-team-builder/assets/bookmarklet.js`). See `files/iraceplan-api-notes.md`.
- **iRacing** (official results/schedules/iRatings) — via a **teammate's proxy** (shared on the
  team Discord; it runs off *their* iRacing account, not the user's) — `IRACING_PROXY_URL` /
  `IRACING_PROXY_KEY` in `~/.config/edr/secrets.env`; currently `https://iracing-bot.fly.dev`,
  `Authorization: Bearer <key>`. Prefix any members-ng `/data/...` path with the proxy URL and get
  the JSON straight back (the proxy handles auth, the expiring-S3-link hop, and chunked search
  results). **GET-only, one shared account** — back off on 429 and cache responses (schedules
  change weekly at most); never embed the key in browser-side code.
  **Failure mode:** if every call returns `400 iracing upstream error / invalid_grant: expired`,
  the bot's own iRacing session has lapsed — nothing local is wrong and no local credential change
  helps; the proxy owner must re-auth it (ping them on Discord). `/health` returning `ok` only
  proves the web app is up; probe `/data/lookup/countries` for a true auth check.
  Endpoint index: `/data/doc`. Useful: `series/seasons` (per-week `race_time_limit` minutes +
  `race_time_descriptors` session start times), `season/list?season_year&season_quarter`
  (includes special events), `results/get?subsession_id`,
  `results/search_series?cust_id&start_range_begin&start_range_end` (per-driver results in a date
  window; **includes practice/qualifying sessions** — filter `event_type_name == "Race"` and
  `official_session`), `member/chart_data?cust_id&category_id&chart_type` (1 = iRating,
  3 = Safety Rating over time; SR values encode `licence_class*1000 + SR*100`, e.g. 4217 = B 2.17).
  Results conventions: **finish positions are 0-based** (winner = 0 — add 1 for display);
  per-race iRating/SR deltas live in `results/get` rows (`oldi_rating`/`newi_rating`,
  `old_sub_level`/`new_sub_level`); team events nest drivers under `driver_results`; `reason_out`
  distinguishes finishes from DNFs. A ~350-subsession sweep at 0.45 s pacing completes in ~5 min
  without tripping the limiter. Quirk: `series/season_schedule` 404s for special-event seasons
  until they go active. (Historical note: direct OAuth is a dead end — `client_credentials` cannot
  read the Data API, and iRacing no longer issues new API tokens; `files/iracing_pull.py` exists
  as a local password_limited client but requires valid member credentials and allows exactly one
  login attempt per run.)

Both Garage 61 and iRacePlan **block direct browser calls (no CORS)** — pulls must be server-side
(the plugin) or local scripts. The browser owns the **merge + scoring**; the PHP backend is thin.
Name matching (Garage 61 `firstName+lastName` ↔ iRacePlan/survey name) is fragile — normalise,
match **case-insensitively** (some Garage 61 profiles are all-lowercase, e.g. `matt blee`), strip
the iRacing digit suffixes from scraped survey names (`Luke Hay3`), and keep an override map.
Known survey→Garage 61 overrides: `Joey Tavora→Joseph Tavora`, `Stipe Ljubic→Stipe Ljubić`,
`Matt Halden→Matthew Halden`, `Chris Wilson→Chris w`, `Zach Martin→Zachary Martin`,
`Michael S Cullen→Michael Cullen`.

**The survey changes constantly** — drivers add, edit and withdraw responses between pulls, so
never decide who is in or out of an event from a saved snapshot or a hardcoded list: re-check the
live survey at task time. Cheap freshness probe: `GET /surveys/:id` (`responses_count` +
`updated_at`) — but counts miss edited responses, so when in doubt re-scrape the timeline.
A driver is in the event pool iff they have availability with hours > 0. **iRacePlan has been
fully removed from the Team Builder plugin (v2.3.0)** — availability is collected in-house on the
Availability tab and the hours>0 pool rule lives in the builder. The iRacePlan API notes below are
retained only as historical reference for local scripts; the plugin no longer calls it.

## Refreshing the standalone Team Builder with live data (local, no WordPress)

To "bring up the team builder with fresh data": re-pull, rebuild the embedded `SAMPLE`, bump the
`localStorage` KEY so it boots fresh (not from stale saved state), then serve it.

1. Pull laps for **both** Spa configs — tracks **444** (Grand Prix Pits) + **446** (Endurance) —
   with the standard `/laps` query.
2. Per driver per GT3 car compute `{laps: total, medianLap: median clean lap (s), cleanPct:
   clean/total}` (clean = `clean && !incomplete && 128<lapTime<150 && !pitIn/Out && !discontinuity`;
   require ≥3 clean laps to include the car).
3. Merge each **survey** driver's pace with their availability from a **fresh live scrape** of the
   iRacePlan survey (save it as a new dated `files/spa-availability-live-YYYY-MM-DD.json`; do not
   reuse an old snapshot — see the survey-freshness rule above). `avail = {hours, pct, starts,
   windows}`; "available" means `hours > 0`. Roster item shape:
   `{name, cars:{car:{laps,medianLap,cleanPct}}, avail}`.
4. Back up, then replace the single `const SAMPLE = [...]` line and bump `const KEY = '..._spa_vN'`
   in the HTML (`cp EDR-Team-Builder.html EDR-Team-Builder.html.spabak` first).
5. Serve from `files/`: `python3 -m http.server 8802 --bind 127.0.0.1` →
   `http://127.0.0.1:8802/EDR-Team-Builder.html`.

## Monthly newsletter (month review)

A recurring deliverable (first edition June 2026): a branded multi-page month review of all
drivers, mixing serious stats, fun awards and per-driver improvement points. Outputs go to the
root-level **`Monthly Newsletter/`** folder (PDF + PNG; final editions also to `EDR-results/`),
built with the standard headless-Chrome rendering workflow below. Pipeline:

1. **Garage 61 laps** for the month: sweep all ~468 track IDs in chunks of 10 with `age=<days>`
   covering the month, filter client-side to the calendar month in Brisbane time. Gives laps,
   clean %, active days, cars/tracks variety, night/wet laps, fuel, per-combo medians.
2. **Official results**: map drivers to cust_ids via the Garage 61 team endpoint, pull
   `results/search_series` per driver for the month window, then `results/get` per unique
   subsession (dedupe first — team enduros overlap) for wins/podiums (in class, 0-based),
   incidents, DNFs (`reason_out`), last places (needs class field size), and per-race iR/SR
   deltas. Sum per-race iR deltas for the month's movement; use `member/chart_data` for SR
   start/end display.
3. Save the per-driver aggregates as dated JSONs in `files/` (e.g.
   `june-driver-stats-2026-07-05.json`, `june-results-agg-2026-07-05.json`) so the next month's
   edition can reuse the pipeline and compare.

Tone: negatives are framed as light-hearted awards ("carnage report"), never shame tables — some
drivers are sensitive about bad months. Driver of the month formula (per Matt): iRating movement
+ Safety Rating movement + a notable result.

## Scoring model (lives in the HTML)

Per driver, three Garage-61 signals scored 0–1 **within their car class**: **Pace** (median clean
lap), **Clean** (clean-lap %), **Prep** (lap count). `score = pace·Wpace + clean·Wclean +
prep·Wprep` (defaults 50/30/20). Top **X%** per class (default 40%) → Pro, rest → Casual. Class is
inferred from the car name (`GTP/HYBRID/LMDH`, `LMP2/LMP`, `GT4`, `GT3`).

## Deliverables (PDFs / PNGs)

Branded reports are rendered ad-hoc (generators are not committed) by writing an HTML file and
running headless Chrome `--headless=new --no-pdf-header-footer --virtual-time-budget=15000
--print-to-pdf`, then rasterising/verifying with **PyMuPDF (`fitz`)**. The virtual-time budget is
required or the Google fonts don't load before printing (headline falls back to Times). Saira
Condensed has **no true italic on Google Fonts** — request `wght@900` only and let
`font-style:italic` synthesise the oblique. Outputs go to `files/` and the `EDR-results/` archive
(and the user's Downloads), date-stamped; team line-up deliverables also go to the root-level
`Team Allocations/` folder. **EDR brand:** black `#0b0b0b`, yellow `#f0f000`, fonts Saira
Condensed (display, italic 900) + Saira Semi Condensed (labels) + Karla (body). House style:
Australian English, no emojis, no em-dashes, sentence case in body.

## Conventions & gotchas

- **Timezone:** the user is in **Brisbane (UTC+10, no DST)**. Garage 61 `startTime` is UTC —
  convert (+10h) before grouping laps by "today"/session day.
- **Credentials are never committed** (`.gitignore` blocks `.env`, `*.token`, `*.key`, `secrets.*`).
  Local secrets live in `~/.config/edr/secrets.env` (outside the repo and iCloud), read via env at
  run time; never hardcode tokens or print secret values. `GARAGE61_TOKEN` **is in that file**
  (verified working, team-scoped: the account belongs to Garage 61 teams `edr-endurotech` +
  `edr-endurotech-casual`, 29 members total) — don't conclude it's missing.
- Plugin model: one **shared plan** in WP options. Reading is public; editing needs a WP login or
  the builder admin password (`edit_pass` setting, `X-EDR-Pass` header); per-driver availability
  writes are public by design. Only the Settings page (`manage_options`) is WP-admin-only. The
  builder page itself must stay private/password-protected — that's the fence.
- **Live-site import gotchas** (both produced the "only one driver imported" symptom): the site's
  Garage 61 key must be **team-scoped** (a personal key returns only the owner's laps), and the
  team-slug setting must not be blank (the server now falls back to `edr-endurotech`). Driver
  names are built from `firstName+lastName` — the API's `name` field is empty or a digit-suffixed
  iRacing name ("Sam Millar2").
- **`/tmp` is wiped between turns** — scratch pull scripts and `spa_laps_fresh.json` won't
  persist; recreate/re-pull as needed (don't assume cached data is still there).
- **Garage 61 rate-limits heavy pulls** — several full pulls in quick succession can get this
  machine's IP firewalled (symptom: TCP **connection refused** to `garage61.net` *only*, while
  other hosts work; lasts ~30–60 min). Space pulls out; don't hammer retries.

## Reference docs

`README.md` (overview + release flow), `PROJECT.md` (full architecture + data-access reference),
`AI-HANDOFF.md` (resume brief), `files/iraceplan-api-notes.md` (iRacePlan API limits),
`files/edr-team-builder/CLAUDE.md` (plugin internals).
