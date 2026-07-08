# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A self-contained **WordPress plugin** (`edr-team-builder/`) that plans iRacing
endurance line-ups for Endurotech Racing (endurotechracing.com). It pulls **pace** from
Garage 61, collects **availability** in-house (drivers tick 4h blocks), and pulls **official
session times** from an iRacing proxy, then builds Pro/Casual teams and stint rotations.
iRacePlan was fully removed in 2.3.0 — imports are pure Garage 61 pace. Activated by the
`[edr_team_builder]` shortcode. Workflow (tab order):
**Event (season calendar) → Availability (drivers tick 4h blocks) → Drivers → Teams →
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

There is no test suite, linter, or package manifest. Distribution is a zip of the plugin
dir (see `edr-team-builder-V2.zip` one level up); install/usage steps are in
[INSTALL.md](INSTALL.md) and [HANDOFF.md](HANDOFF.md).

## Architecture

**Server (PHP).** `edr-team-builder.php` is the plugin entry: settings page (stores
`g61_token`, `irp_key`, `team_slug` server-side only), the `[edr_team_builder]`
shortcode, and the REST API under `/wp-json/edr/v1/`:
- `GET /tracks`, `GET /events`, `POST /import`, `POST /plan` — logged-in user OR the
  builder admin password (`X-EDR-Pass` header, `edr_tb_req_can_edit()`).
- `GET /plan` (public) — the single shared plan, stored in the `edr_tb_plan` option.
- `POST /auth` (public) — verifies the admin password for the builder's role unlock.
- `GET/POST /avail` (public) — per-driver availability slots per event
  (`edr_tb_avail` option); drivers submit without an account.
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

**Client (generated `builder.js`).** The browser owns the merge and all scoring. Key flow:
- `applyImport(payload, scrape)` joins iRacePlan availability ↔ Garage 61 pace by
  normalized name (`norm()`), honoring manual `overrides` (`{irpName: g61Slug}`,
  persisted in `localStorage`). Unmatched names are surfaced in the Setup tab's
  "NAME MATCHES" review for correction.
- `computeAvail()` turns availability windows into hours / coverage % / which candidate
  race starts a driver can cover.
- Teams/stints scoring (PACE/CLEAN/PREP weights, Pro = top N% of each class) lives in the
  inherited `EDR-Team-Builder.html` logic. `serializePlan()`/`loadPlan()` sync the whole
  state to the shared WordPress plan (auto-saves debounced; only when `can_edit`).
- `WIN_START_MS`, `START_OFFSETS`, `START_LABELS` are mutated on import (the assembler
  rewrites them from `const` to `let` for exactly this reason).

**Availability bookmarklet** (`assets/bookmarklet.js` / `.html` / `-url.txt`). For the
survey phase (before any team planning exists, when iRacePlan's API has no availability),
the admin runs this in their own logged-in iRacePlan tab to scrape survey windows, then
pastes the JSON into the Setup tab's AVAILABILITY box → "Merge availability".

## Data conventions

- **Car classes** are inferred from car names by regex in `classOfCar()` (GTP/LMP2/GT4/GT3);
  only those four classes are kept when building a driver's cars.
- **Pace data window:** Garage 61 pulls use `age=-1` and are scoped to iRacing 2026
  Season 3 onward by project convention — keep that window when changing the pace query.
- **Driver display names** are built from Garage 61 `firstName+lastName` (`edr_g61_roster()`);
  the API's `name` field is empty or a digit-suffixed iRacing name ("Sam Millar2") that never
  matches the roster. The Garage 61 key in Settings must be **team-scoped** (a personal key
  returns only the owner's laps) and the team slug falls back to `edr-endurotech` when blank —
  both misconfigurations produce the "import only returns one driver" symptom.
- **Availability is strictly per event** and **the event pool rule** applies: with an event
  selected, Drivers/Teams/Stints include only drivers with `avail.hours > 0` for that event.
  Anyone who submits availability joins the pool (no pace until the next import). Imported
  iRacePlan availability is folded into the same per-event store via `windowsToSlots()`.
- **Times** are offsets in minutes from the event `window_start`; availability windows are
  `[startMin, endMin]` pairs, merged by `edr_merge_windows()`; the availability matrix uses
  4-hour slots (`AV_BLOCK`), converted by `slotsToAvail()`/`windowsToSlots()`.
- Credentials live only in the `edr_tb_settings` WP option and are never sent to the
  browser; the plugin makes outbound requests to `garage61.net` and `iraceplan.com`.
