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
  fortnight. The client keeps only rounds starting in the drafted **race week** and the pick list
  is built from those — so the tab shows what is actually on, not a fixed menu.
- **A race week is Tuesday 00:00 UTC to the following Tuesday**, iRacing's own rollover (10:00
  Brisbane year-round; 10:00 or 11:00 Melbourne with daylight saving). `draftWeekWindow()` anchors
  on that tick, and `edr_tb_next_week_tick()` is the PHP half so the recap window and the ratings
  snapshot key land on the same boundary. Anchoring on a local Monday instead (as this did until
  2.4.23) picks the Tuesday *eight* days out whenever the draft is generated on a Monday, so the
  whole write-up ran a week ahead — and it only read correctly Wed–Sun, which is why the Sunday
  job never showed it.
- **The Weekly tab chooses which race week** — `state.weekly.scope`, `'next'` (default) or
  `'this'`. `refreshRecap()` sends the matching recap window, so both halves of one draft always
  describe the same pair of weeks.
- **The car for a round comes from `schedules[].race_week_cars`, never from the season.**
  Season-level `car_class_ids` is every car the *season* may use, so for a rotating-car series it
  never moves — reading only that made Ring Meister announce the same car every week of the
  season, matching the current week by coincidence and every other week by accident. The per-week
  array carries `car_id` + `car_name_abbreviated`. `edr_ir_week_cars()` names it as a class where
  it can (greedy largest-first cover over the season's own classes, so a GT3 week reads "GT3" and
  not its nine cars, and a nested single-make class is not listed beside the class containing it),
  otherwise falls back to the cars' own names. Order of authority: `race_week_cars` →
  `car_restrictions` (older, by class) → season-wide list. Only the last is a guess, and it sets
  `carsWeek:false`, which puts **"please confirm car"** in the draft and a panel on the tab
  instead of stating a car as fact. Verified against `iracing-week-planner`, which reads the same
  field (`build/api/getSeason.js`).
- **Race length is `race_time_limit` *or* `race_lap_limit`.** Lap-limited rounds — Ring Meister,
  both cup cars, most ovals — carry no time limit at all, so reading only the minutes dropped the
  length silently and made an hourly Nordschleife round look implausible when "2 laps" was there
  all along. `fmtRaceDist()` prefers minutes and falls back to laps.
- **One `race_time_descriptor` decides the whole pattern.** Taking each field from whichever
  descriptor first carried it can describe a schedule that exists in none of them: a fixed
  6-hour enduro inheriting `repeat_minutes` and a 4am start from the sibling descriptor. Read
  descriptor `[0]` (as the reference planner does) and look past it only when `[0]` carries
  neither `session_times` nor a repeat. A round with an interval but no start now honestly
  reports no start time — the tab's "No start time for N series" panel is where that shows up.
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

### Two boxes: the draft, and the post (2.4.37)

The write-up is **copied out, edited somewhere else and pasted back**, so the tab has two
textareas and they are deliberately not the same one:

- **`#wkdraft`** — generated by `weeklyDraft()`. Rebuilt from scratch on every Generate.
- **`#wkfinal`** — the pasted-back version, and **what actually posts**. Nothing on the tab
  rewrites it; Generate says so rather than touching it. Empty means "nothing pasted yet", in
  which case the draft posts as it stands. `wkPostText()` is the one place that decides.

Both boxes are captured on `input` into `_wkDraft`/`_wkFinal` and **neither re-renders the tab** —
the tab is rebuilt from a string, so a re-render replaces the textarea and drops the caret at the
end, which is unusable in a 3000-character post. Only the one-line counter (`wkSyncCount()`)
refreshes. Edits are pushed to `POST /weekly/draft` on a 1.2s debounce, because the scheduled job
posts the server's copy with no browser involved and an edited write-up that lived only in one
tab's memory dies with that tab. `cacheDraft()` resolves **true only on a confirmed write** — the
"saved" stamp is a promise that the text exists outside this browser, so it must not appear on a
failed one (the standalone stub returns false: it stores nothing). `loadStoredPost()` restores the
server's copy into `#wkfinal` on boot, and never over anything the tab already holds.

**Draft layout is fixed and Discord-specific**: one `##` heading per section (the only place
emoji appear, plus 🥇🥈🥉 on the top three), the subject of an entry bold and alone on its line,
its facts on the next line, quiet detail on `-#`, the human note as `>`, blank line between
entries. `wkTidy()` normalises the spacing in one pass at the end — never two blank lines, always
exactly one above a heading, none trailing — because each section emits its own trailing blank
from inside its loop, so an *empty* section emitted none and welded its "nothing on this week"
line to the next heading. The Gemini compose prompt states the same layout, or it rewrites it away.

### Preview before post (2.4.37)

**There is no path that posts without showing it first.** Both `Preview & post` buttons (top row,
and again beside the final box — that is where you are standing when you finish pasting) call
`wkOpenPreview()`; the only `wkpost` action left in the app lives *inside* the preview. It reads
the boxes from the DOM rather than `_wkFinal`/`_wkDraft`, because text pasted a keystroke ago has
not cleared the 1.2s debounce and previewing something other than what Post will send defeats the
point.

`wkMdToHtml()` renders the markdown subset the write-up uses (`#`/`##`/`###`, `-#`, `>`, `-`,
`**`, `*`, `__`, `~~`, `` ` ``) in Discord's own colours, one card per chunk from `wkChunks()`, so
a `**` that never closed, a heading that lost its space, or a split landing somewhere daft is
visible before it reaches the team. **It escapes first, then formats** — the text is pasted in from
outside, so treating it as trusted markup would be an injection straight into an admin page.

The overlay is created in JS and appended to `#edr-tb-app` (not `<body>`) so the plugin's armored
CSS reaches it, and everything inside `.wkmsg` carries `!important`: a theme styling `h1`/
`blockquote`/`ul` would make the preview confidently wrong about the one thing it exists to show.
Dismissed by the button, the scrim, or Escape.

### The Discord chunker (fixed in 2.4.37)

`edr_tb_discord_chunks()` had literal **CRLF inside its own string literals** — this file is CRLF,
and the newlines were typed rather than escaped. The draft arrives LF-only, so the paragraph split
matched nothing, every update over the limit fell through to `str_split()` at a hard byte offset
(mid-word, and mid-character on every `·`, `—` and emoji), and the first flush pushed an **empty
chunk** — which Discord 400s, aborting the whole post with "sent 0 of N". Any weekly update over
~1900 bytes therefore failed outright. Now: normalise to `\n` first, split on blank lines, never
emit an empty chunk, and chop oversized lines on character boundaries (`edr_tb_split_utf8()`)
while still measuring against the **byte** budget `strlen()` enforces. The client's `wkChunks()`
is a line-for-line port so the message count on the tab is what the team actually receives; they
are checked against each other by fuzzing (4000+ cases incl. CRLF and 4-byte emoji), which is how
the empty-chunk bug surfaced.

### Last-week recap (`includes/results.php`)

The draft opens with how the week just gone went: top three on iRating, most improved, biggest
loss, Safety Rating movers, wins, busiest and cleanest.

**This is the expensive pull.** One `results/search_series` per driver plus one `results/get`
per unique subsession, against a GET-only proxy on a single shared iRacing account that
rate-limits. It runs at `EDR_TB_RECAP_PACE` (0.45s) between calls and is capped at
`EDR_TB_RECAP_MAX_SUBS`; anything dropped by that cap is reported in the UI rather than
silently truncated. It **never** runs whole in one request. `POST /recap/refresh` builds the work
plan synchronously (one Garage 61 membership call, so a bad token surfaces immediately) then arms
the run; the sweep advances one bounded slice (`EDR_TB_RECAP_BUDGET`, ~10s) at a time, banking
state after **every** driver and every subsession so a killed request loses at most one item. The
result is cached in `edr_tb_recap` until the next run. Both routes are edit-gated.

**What advances a slice (2.4.26).** `edr_tb_recap_advance()` runs exactly one slice under a
non-blocking MySQL lock (`GET_LOCK('edr_tb_recap_step')`), so the cron job and a client poll can
never step the same state at once. It is called both by `edr_tb_recap_job` (WP-Cron, which
re-queues itself while work remains) **and by `GET /recap` on every 5s poll**. That second caller
is the point: on a host with WP-Cron loopback disabled (`DISABLE_WP_CRON`, or a firewall blocking
the self-HTTP `spawn_cron` fires) the queued job never runs, and before 2.4.26 the sweep stalled
after its first slice and the tab reported "the host cut it off" — which is exactly what kept
happening. Letting the open tab's own polling drive the run makes it finish with no working cron
at all. The `edr_tb_recap_running` transient is the single source of truth that a sweep is live:
armed by `/recap/refresh`, cleared only by `edr_tb_recap_advance()` on done/error, so the cron
re-queue can never outlive it. Trade-off: if every tab closes mid-sweep and cron is dead, the run
pauses until someone reopens the Weekly tab (state persists, so it resumes, never restarts).

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

### Ratings movement without the sweep

iRating and Safety Rating movement do **not** need the results sweep, and should never wait on
it. Garage 61 already carries both on each member account, so `POST /ratings` stores a dated
snapshot (`edr_tb_rating_snaps`, keyed by the race week's own Tuesday via `edr_tb_next_week_tick()`,
same boundary as the draft window) and `GET /ratings` diffs the two most recent — two HTTP calls,
seconds. That covers the whole roster, not just whoever turned up in the races the sweep fetched.

**Only `sports_car` counts** — `edr_g61_member_ratings_full()` reads the max `irating` /
`safety_rating` in that one category per driver. A diff therefore only moves for someone who raced
sports_car official sessions between the two snapshots; drivers who did not, or who only race other
categories, read as unchanged. That is the first thing to check if "N drivers moved" looks too low.

The sweep is now only needed for **wins, podiums, incidents and race counts**. `recapAwards()`
prefers the snapshot diff for ratings and folds the sweep's counts onto each mover by name;
a driver present in one source and not the other degrades to whichever half exists.

The sweep also reports progress (`edr_tb_recap_progress`, stamped every driver and every race).
With step-on-read driving it, the heartbeat only falls silent when every tab has closed, so
`GET /recap` now treats **five** minutes of silence (not three) as an abandoned run before it
surfaces the "host cut it off" message and clears the flag.

### Team history (`includes/history.php`, 2.4.27)

The per-driver / per-track / per-series record the write-up used to fake. Until 2.4.27 the
"who races this" figures came from the seeded `WEEKLY_SERIES` constant and the driver mentions from
hand-written `DRIVER_NOTES`, so every draft said the same thing no matter what anyone had raced.

**Cheap where the recap is expensive.** `results/search_series` returns one row per race *for the
driver searched*, carrying the track, series and that driver's own finish — so history is one call
per driver per ≤90-day chunk (~30–60 calls), and never touches `results/get`. The recap is only
costly because it must find *other* EDR drivers in races it did not know about.

- **Same resumable, step-on-read machinery as the recap** (`edr_tb_hist_advance()`, its own
  `GET_LOCK('edr_tb_hist_step')` and `edr_tb_hist_running` flag). `POST /history/refresh` builds the
  plan synchronously; `GET /history` advances a slice on every poll. Finishes without working cron.
- **Degrades honestly.** Every field is probed across spellings and the payload reports which
  arrived (`fields.pos`, `fields.inc`). If the proxy omits finish positions the draft shows "starts
  only" — never a fabricated "0 wins". Confirmed by test: positions present → "12 starts, 3 wins,
  6 podiums (best 1st, Sam Millar)"; absent → "12 starts".
- **Track keys are config-insensitive and accent-folded** (`edr_tb_hist_track_key()` ↔ client
  `histTrackKey()`, verified byte-identical across PHP and JS, so "Nürburgring" joins "Nurburgring").
- **The series join can't drift.** The client re-keys history entries through its *own*
  `seriesKey()` over each label to build `TEAM_SERIES`, rather than trusting PHP's
  `edr_tb_hist_series_key()` to match — the mismatch the old handoff warned about is now
  structurally impossible. `TEAM_SERIES` feeds `teamRunners()`; `histTrackLine()` and `flareFor()`
  read `HISTORY` for the per-track line and the earned driver mention.
- Client: `HISTORY`/`HIST_*` declared in the HTML core (so the standalone build no-ops cleanly);
  the fetch/poll/`applyHistory()` live in the WP `APPEND` layer. "Pull team history" button sits
  beside "Pull last week's results" on the Weekly tab.

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
