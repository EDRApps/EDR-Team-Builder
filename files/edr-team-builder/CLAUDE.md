# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A self-contained **WordPress plugin** (`edr-team-builder/`) that plans iRacing
endurance line-ups for Endurotech Racing (endurotechracing.com). It pulls **pace**
from Garage 61 and **availability** from iRacePlan, then builds Pro/Casual teams and
stint rotations. Activated by the `[edr_team_builder]` shortcode. There is one shared
plan stored in WordPress options: **anyone who can reach the page can read it**
(`GET /plan` is public — the viewer gate was removed in 2.0.3 so members with only the
page password, not a WP account, still see data; the page itself should stay
private/password-protected). **Editing (POST /plan) and the import endpoints still
require a logged-in user**, and only the Settings page, which stores the API keys,
stays admin-only (`manage_options`).

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
- `GET /tracks`, `GET /events`, `POST /import` — available to any logged-in user.
- `GET /plan` (public) / `POST /plan` (any logged-in user) — the single shared plan,
  stored in the `edr_tb_plan` option.

`includes/` are thin API clients returning plain arrays:
- `garage61.php` — `edr_g61_roster()` pulls `/laps` for the team at the chosen track(s)
  and aggregates per-driver-per-car `{laps, medianLap, cleanPct}`. Mirrors
  `../../colab_pull_garage61.py`. Note `age=-1` (pace data window — see below).
- `iraceplan.php` — events from `/surveys`; `edr_irp_event_detail()` derives the race
  window + candidate start times from `session_times` and pulls availability from the
  **plannings** API. Survey responses are NOT in the iRacePlan API — full multi-window
  availability only exists once a team planning is created, or via the bookmarklet scrape.
- `merge.php` — `edr_tb_merge()` does NOT do the name matching; it just bundles the
  Garage 61 roster + iRacePlan event metadata for the browser to merge.

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
- **Times** are offsets in minutes from the event `window_start`; availability windows are
  `[startMin, endMin]` pairs, merged by `edr_merge_windows()`.
- Credentials live only in the `edr_tb_settings` WP option and are never sent to the
  browser; the plugin makes outbound requests to `garage61.net` and `iraceplan.com`.
