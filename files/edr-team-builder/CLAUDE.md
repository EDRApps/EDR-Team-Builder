# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A self-contained **WordPress plugin** (`edr-team-builder/`) that plans iRacing
endurance line-ups for Endurotech Racing (endurotechracing.com). It pulls **pace** from
Garage 61, collects **availability** in-house (drivers tick 2h blocks), and pulls **official
session times** from an iRacing proxy, then builds Pro/Casual teams and stint rotations.
iRacePlan was fully removed in 2.3.0 — imports are pure Garage 61 pace. Activated by the
`[edr_team_builder]` shortcode. Workflow (tab order):
**Event (season calendar) → Availability (drivers tick 2h blocks) → Drivers → Teams →
Stints**, with a role model on top:

- **Driver (default, no account):** browses the calendar, picks an event, submits their
  own availability. Everything else is read-only. `GET /plan`, `GET/POST /avail` are
  public by design — the page itself should stay private/password-protected; that is the
  only thing hiding team data from the web.
- **Admin:** a logged-in WP user, or anyone who unlocks with the **builder admin
  password** (the `edit_pass` field in plugin Settings, verified server-side via
  `POST /auth`; sent as `X-EDR-Pass` on writes — see `edr_tb_req_can_edit()`). Admins
  edit Drivers/Teams/Stints, run imports, and their plan saves persist. The standalone
  HTML has its own hash-checked password (`ADMIN_HASH` in the file; default `edr2026`).
- The WP Settings page (API keys + edit password) stays `manage_options` only.

The season calendar is the `CAL_EVENTS` array in `EDR-Team-Builder.html` (shared by both
builds — edit it there). Per-driver availability lives in the `edr_tb_avail` option keyed
by event, and converts client-side to the `{hours, pct, starts, windows}` shape the
scoring/stints already use (`slotsToAvail()`).

## Build

The front-end is **generated**, not hand-edited:

```bash
python3 build/assemble_builder.py        # regenerates assets/builder.js + assets/builder.css
python3 build/package_plugin.py          # rebuilds, then writes the uploadable zip
node --check assets/builder.js           # always parse-check a rebuild
```

- **Source of truth for the UI is `../../EDR-Team-Builder.html`** (one level above the
  plugin dir, i.e. `files/EDR-Team-Builder.html`), a standalone single-file app.
  `build/assemble_builder.py` extracts its `<style>` and `<script>`, strips the embedded
  `SAMPLE` data, rewrites the boot block, and appends the WordPress data-import layer
  (`bootSetup`, REST calls, the Setup tab) to produce `assets/builder.js`/`builder.css`.
- **Do not edit `assets/builder.js` or `assets/builder.css` by hand** — they are
  overwritten on the next build. Change behavior either in `EDR-Team-Builder.html`
  (core team/stint/render logic) or in the `APPEND`/`APP_HTML` string literals inside
  `build/assemble_builder.py` (the WordPress glue: import, setup, plan save/load).
- Override source/output paths via env vars `EDR_SRC` / `EDR_OUT` (defaults are repo-relative).

There is no test suite, linter, or package manifest. Distribution is a zip of the plugin dir,
built by `build/package_plugin.py` to `Admin Folder/edr-team-builder-V2.zip` at the repo root
(`EDR_ZIP` overrides the path); the packager also copies [HANDOFF.md](HANDOFF.md) beside the
zip, so **this file is the source of truth for that note** and the copy in `Admin Folder/` is
generated. CI fails when the committed assets or the committed zip's version fall behind the
source. Install/usage steps are in [INSTALL.md](INSTALL.md) and [HANDOFF.md](HANDOFF.md).

## Architecture

**Server (PHP).** `edr-team-builder.php` is the plugin entry: settings page (stores
`g61_token`, `team_slug`, `edit_pass`, `iracing_url`/`iracing_key` server-side only), the
`[edr_team_builder]` shortcode, and the REST API under `/wp-json/edr/v1/`:
- `GET /tracks`, `POST /import`, `POST /plan`, `GET /iracing` — logged-in user OR the
  builder admin password (`X-EDR-Pass` header, `edr_tb_req_can_edit()`).
- `GET /plan` (public) — the single shared plan, stored in the `edr_tb_plan` option.
- `POST /auth` (public) — verifies the admin password for the builder's role unlock. Wrong
  passwords are counted per IP (`EDR_TB_MAX_PASS_FAILS` in 10 min, shared with
  `edr_tb_req_can_edit()` so guessing through a write route counts too) and refused with a 429
  once tripped. It used to `sleep(1)` per failure, which held a PHP worker on a public route.
- `GET/POST /avail` (public) — per-driver availability slots per event
  (`edr_tb_avail` option); drivers submit without an account. Because it is unauthenticated the
  write is bounded: `ev` must look like `<name>|<YYYY-MM-DD>` (`edr_tb_valid_ev()`), and a key
  that does not already exist is refused once the store hits `EDR_TB_MAX_EVENTS` events or
  `EDR_TB_MAX_DRIVERS` names on that event. Updating an existing entry is always allowed.
  The whole read-modify-write sits inside `GET_LOCK('edr_tb_avail_write')` — same guard as the
  plan, and for the same reason: without it two drivers submitting at once silently lose one of
  the two, right after being told "Submitted". `GET` also returns `seen`
  (`edr_tb_avail_seen`: `{ev: {name: {at, n}}}`), the last-submission stamp the Availability tab
  shows back to the driver.
- `POST /avail/prune` (edit-gated) — drops availability, prefs and stamps for events whose date
  is more than `days` (default 90) old. The store caps above are only ever reached legitimately
  by accumulation, so admins need a way to tidy up; the Availability tab has a button.
- `GET /rev` (public) — the heartbeat: `{plan, avail, paceAt, paceErr}` from three option reads.
  Every open tab polls it every 20s and only pulls `/plan` or `/avail` when a number moved, so
  the steady-state cost of feeling live is a few bytes per tab. It is also the **scheduler
  tick** (`edr_tb_maybe_schedule_pace()`) — see below.

## Staying live

Two different problems, deliberately solved differently.

**Team data (plan + availability) is near-live.** Refresh used to happen only on window focus,
so two people planning together saw each other's work only by clicking away and back. Now
`_revTick()` polls `/rev` every 20s while the tab is *visible*, compares the revisions it holds,
and calls the existing `refreshShared()` only on a change. Hidden tabs poll nothing (a phone
left on the page all weekend is free), focus triggers an immediate check, and failures back off
20s → 40s → … → 180s so a dead connection does not hammer. Availability writes bump
`edr_tb_avail_rev` so a driver's submission propagates exactly like a plan edit.

**Garage 61 pace is warmed, not live.** A heavy pull can get the site's IP rate-limited, and
pace only moves when people practise. `edr_tb_maybe_schedule_pace()` runs off the heartbeat and
queues `wp_schedule_single_event('edr_tb_pace_refresh')` when the cache is stale — hourly within
`EDR_TB_PACE_NEAR_DAYS` of the selected event, daily otherwise, debounced by a transient so
concurrent tabs cannot pile up jobs. Hanging this off the heartbeat rather than real cron is
deliberate: WP-Cron only fires on traffic and this page is private and quiet, so the refresh is
considered exactly when somebody is using the tool. `POST /import` then serves the warm cache
when it covers the same tracks and is recent (`fresh=1` forces a live pull), which is why an
admin importing on race morning normally gets an instant answer.

## Weekly update (Weekly tab, admin only)

Generates a pasteable "This week in iRacing" draft. Hidden from drivers by
`.readonly .tabs .tab[data-tab="weekly"]{display:none}`, the same mechanism as Setup.

- **Next week's rounds come from the live iRacing schedule.** `GET /iracing` now returns a
  second array, `weeks` (`edr_ir_weeks_from()`), holding every official round in the next
  fortnight. The client keeps only rounds starting in next week's Mon–Sun window and the pick
  list is built from those — so the tab shows what is actually on, not a fixed menu.
- **`weeks` is deliberately separate from `seasons`.** `edr_ir_seasons()` only emits weeks that
  carry `session_times`, because `irMatchFor()` scores across it; feed it every schedule week
  and it can settle on a sessionless one, at which point `applyIrTiming()` bails and the
  official-times feature silently stops working. One HTTP call, two shapes (`edr_ir_all()`).
- **Dates are compared as ISO strings**, never timestamps. `start_date` is a plain YYYY-MM-DD
  and mixing it with local-midnight epochs skews the window by hours in Brisbane, which drops
  or double-counts rounds at the boundary.
- **Series matching is exact on a normalised key** (`seriesKey()`: strip the season suffix and
  the sponsor tail). No substring fallback — "imsa iracing series" is a prefix of "imsa iracing
  series fixed", so a loose match collapses the Fixed and open splits and names the wrong track
  for half the write-up. Unmatched reads "track TBC", which is honest.
- **`WEEKLY_SERIES` and `DRIVER_NOTES` in the HTML are seeds, not truth.** The series figures
  came from a one-off popularity report and only supply ordering, default ticks and
  who-races-what until live data exists. `TEAM_SERIES` (WordPress layer) is the live slot:
  populate it from iRacing results matched to the Garage 61 roster and `teamRunners()` prefers
  it automatically. **Not yet built** — the UI says so rather than implying the numbers are live.
- Driver mentions are de-duplicated across a draft, and the endurance section drops anything
  that finishes before next week starts.

### Last-week recap (`includes/results.php`)

The draft opens with how the week just gone went: top three on iRating, most improved, biggest
loss, Safety Rating movers, wins, busiest and cleanest.

**This is the expensive pull.** One `results/search_series` per driver plus one `results/get`
per unique subsession, against a GET-only proxy on a single shared iRacing account that
rate-limits. It runs at `EDR_TB_RECAP_PACE` (0.45s) between calls and is capped at
`EDR_TB_RECAP_MAX_SUBS`; anything dropped by that cap is reported in the UI rather than
silently truncated. It **never** runs in a page request — `POST /recap/refresh` sets a
running flag and queues `edr_tb_recap_job`, the client polls `GET /recap` every 5s, and the
result is cached in `edr_tb_recap` until the next run. Both routes are edit-gated.

Conventions this code depends on, all easy to get wrong:
- **finish positions are 0-based** (winner = 0) — the recap adds one only for display
- `results/search_series` returns practice and qualifying too; only `event_type_name == "Race"`
  and `official_session` count
- team events nest the real drivers under `driver_results`, so rows are flattened before tallying
- Safety Rating sub-levels encode `licence_class*1000 + SR*100`, so 4217 is B 2.17
  (`edr_tb_sr_label()`)
- iRacing customer IDs come from the Garage 61 membership (`edr_g61_all_members()['ids']`),
  which is the only join between Garage 61 names and official results

The awards are computed **client-side** from the cached driver rows, so adding a new one never
needs another sweep of the API.

**Pace is never auto-applied.** The warm cache only makes the *next* import instant; it does not
rewrite `state.drivers` behind anyone's back. Silently re-running the split could reshuffle a
line-up an admin had already settled — the same class of surprise the plan's 409 guard exists to
prevent. The Setup tab shows how old the pace is (`paceAgeLabel()`) and the admin decides.
- `GET /iracing` (edit-gated, cached 12h in `edr_tb_iracing`) — official iRacing session
  start times + race lengths via a teammate's proxy (`includes/iracing.php`, same
  server-side pattern as `garage61.php`; proxy URL + key in Settings). Returns only the
  currently-active seasons that expose `race_time_descriptors` (special events appear once
  active). The builder's Setup tab matches a calendar event to a season and `applyIrTiming()`
  converts `session_times`/`race_time_limit` into a per-event timing override
  (`state.evTiming[evKey]`, persisted in the plan) — this reproduces the hand-typed Spa
  values exactly. Failures (e.g. expired proxy session) are surfaced, not cached, and the
  event falls back to its calendar/derived times.

`includes/` are thin API clients returning plain arrays:
- `garage61.php` — `edr_g61_roster()` pulls `/laps` for the team at the chosen track(s)
  and aggregates per-driver-per-car `{laps, medianLap, cleanPct}`. Mirrors
  `../../colab_pull_garage61.py`. Note `age=-1` (pace data window — see below).
- `iracing.php` — `edr_ir_get()`/`edr_ir_seasons()` call a teammate's iRacing Data API proxy
  (same server-side/cached pattern as `garage61.php`) for official session start times +
  race lengths; detects the expired-proxy-session case and surfaces it.

(iRacePlan is gone as of 2.3.0: `iraceplan.php`, `merge.php`, the `/events` route, the
`irp_key` setting, and the bookmarklet assets were all removed. `POST /import` returns the
Garage 61 roster and nothing else; the browser folds in the in-house availability.)

**Client (generated `builder.js`).** The browser owns all scoring. Key flow:
- `applyImport(payload)` rebuilds `state.drivers` from the Garage 61 roster (pace only),
  then `applyAvailToDrivers()` folds in the per-event in-house availability. No name-match
  step — the roster IS the source of names.
- Teams/stints scoring (PACE/CLEAN/PREP weights, Pro = top N% of each class) lives in the
  inherited `EDR-Team-Builder.html` logic. `serializePlan()`/`loadPlan()` sync the whole
  state to the shared WordPress plan (auto-saves debounced; only when editing).
- `WIN_START_MS`, `START_OFFSETS`, `START_LABELS` are mutated when an event is selected or
  iRacing timing is applied (the assembler rewrites them from `const` to `let`).

## Data conventions

- **Car classes** are inferred from car names by regex in `classOfCar()` (GTP/LMP2/GT4/GT3);
  only those four classes are kept when building a driver's cars.
- **Pace data window:** Garage 61 pulls use `age=-1` and are scoped to iRacing 2026
  Season 3 onward by project convention — keep that window when changing the pace query.
- **Driver display names** are built from Garage 61 `firstName+lastName` (`edr_g61_roster()`);
  the API's `name` field is empty or a digit-suffixed iRacing name ("Sam Millar2") that never
  matches the roster. That suffix is iRacing's collision marker, and it duplicated a real
  driver in production (cust 906888 appeared as both "Sam Millar" and "Sam Millar2"), because
  a member with a full profile on one EDR team can have only a bare `name` on the other.
  Three defences, all needed: `edr_g61_strip_ir_suffix()` removes the suffix wherever a name is
  derived; `edr_g61_all_members()` collapses on **iRacing customer ID** and prefers the name
  from the real profile fields; and the client's `nameKey()` strips it too, so data already
  stored under the suffixed spelling folds onto the right driver. `applyAvailToDrivers()` takes
  the **union** of blocks across colliding spellings — it used to assign inside the loop, so
  whichever spelling came last silently overwrote the other driver's availability.
  Use a capture group, never lookbehind, in the client regex: Safari before 16.4 throws on
  lookbehind at parse time, which kills the whole bundle on older iPhones. The Garage 61 key in Settings must be **team-scoped** (a personal key
  returns only the owner's laps) and the team slug falls back to `edr-endurotech` when blank —
  both misconfigurations produce the "import only returns one driver" symptom.
- **Availability is strictly per event** and **the event pool rule** applies: with an event
  selected, Drivers/Teams/Stints include only drivers with `avail.hours > 0` for that event.
  Anyone who submits availability joins the pool (no pace until the next import).
- **Times** are offsets in minutes from the event `window_start`; the availability matrix uses
  2-hour slots (`AV_BLOCK`, 120 min), converted by `slotsToAvail()`/`windowsToSlots()`.
- Credentials live only in the `edr_tb_settings` WP option and are never sent to the
  browser; the plugin makes outbound requests to `garage61.net` and the iRacing proxy.
