# EDR Team Builder — handoff for Claude (resume this project)

**Read this first.** It's a brief so an AI assistant (Claude) can pick this project up
and keep making changes. Upload this whole folder alongside it. Also read
`PROJECT.md` and `iraceplan-api-notes.md` for deeper detail.

## What this project is
A system for **Endurotech Racing (EDR)**, a GT3/LMP2 iRacing endurance team, to plan
**Pro/Casual driver teams and stint rotations** for endurance events, using real data:
- **Garage 61** = performance (practice/race laps → pace, lap count, clean-lap %).
- **iRacePlan** = availability (driver survey + planning) and the stint timeline.
- The tool turns those into a Pro/Casual split per car class, balanced car entries, and
  a stint plan that respects each driver's availability and the session start time.

## What's been built (files in this folder)
- **EDR-Team-Builder.html** — the original standalone tool (open in a browser). Has the
  latest Watkins Glen (Boot) data embedded as `SAMPLE`; state persists in `localStorage`.
  This is the **source of truth for the front-end logic** (scoring, Pro/Casual, stints,
  per-car sessions, session finder, drag-and-drop, EDR theme).
- **edr-team-builder/** + **edr-team-builder-V2.zip** — the standalone version as a
  **WordPress plugin (V2)** for endurotechracing.com. This is the current main deliverable.
- **EDR-Team-Planning.pdf / .html** — driver-facing one-pager explaining how teams are picked.
- **EDR-Team-Builder-Explained.pptx / .pdf** — slide deck explaining the system.
- **glen-availability.json** — availability extracted from iRacePlan survey 1525.
- **glen-teambuilder-data.json** — Garage 61 pace + availability merged (builder import shape).
- **iraceplan-api-notes.md** — what the iRacePlan API can/can't do (important).
- **colab_pull_garage61.py** — original Garage 61 pull (manual / Google Colab).
- **edr-web.skill** — EDR brand kit (a zip; unzip to get tokens/CSS/logo). Brand: yellow
  `#f0f000` on navy `#1e1e42` / black `#0a0a0a`, fonts Prompt (headings) + Karla (body).

## Hard facts that shaped the design (do not relearn the hard way)
1. **Both APIs block direct browser calls (no CORS).** Any data pull must be server-side.
2. **iRacePlan survey responses are NOT in the API** (only counts). Availability is reachable
   two ways: (a) the **plannings API** (`/plannings/:id` → `driver_availabilities` + `roster`)
   once a team planning exists; (b) **scraping the logged-in survey timeline** (green/red
   cells mapped by pixel position). The bookmarklet does (b) — see below.
3. **Garage 61:** base `https://garage61.net/api/v1`, `Authorization: Bearer <token>`
   (needs `driving_data` scope). Team slug **`edr-endurotech`**. Pull `/laps` with
   `tracks`,`teams`,`unclean=true`,`group=none`,`age=-1`; tracks from `/tracks`.
4. **iRacePlan:** base `https://iraceplan.com/api/v1`, `Authorization: Bearer <key>`.
   Useful: `/surveys`, `/surveys/:id` (teams + `session_times`), `/plannings`, `/plannings/:id`.
5. **Name matching** Garage 61 slugs ↔ iRacePlan names is the fragile bit. Normalise
   (lowercase, strip non-letters) and keep an override map. Known overrides:
   `chris-w`→"Chris Wilson6", `matthew-halden`→"Matt Halden", `michael-cullen`→"Michael S Cullen".

## The WordPress plugin (V2) — current architecture
Folder `edr-team-builder/`:
- `edr-team-builder.php` — bootstrap: admin **settings page** (stores shared Garage 61 token +
  iRacePlan key in WP options), REST routes, `[edr_team_builder]` shortcode.
- `includes/garage61.php` — laps pull + per-driver/car tally (port of colab_pull_garage61.py).
- `includes/iraceplan.php` — events list, nearest-survey pick, plannings availability + windows.
- `includes/merge.php` — thin assembler (heavy merge happens in the browser).
- `assets/builder.js` + `builder.css` — the front-end (see "regenerating" below).
- `assets/bookmarklet.js` + `bookmarklet-url.txt` + `bookmarklet.html` — availability grabber.
- `INSTALL.md`, `HANDOFF.md` — install + site-manager notes.

**Model (V2):** ONE **shared plan** stored in WP options (`edr_tb_plan`), read by everyone via
`GET /wp-json/edr/v1/plan`, written by any logged-in member via `POST .../plan`. **The admin gate
was removed: any logged-in member can edit; only the settings page (API keys) stays admin-only.**
Credentials are one shared set in the settings page (server-side only, never sent
to the browser). The Setup tab **auto-selects the nearest event** and pulls on an admin
**Import / Refresh now** button (no cron). Survey-phase availability still needs the bookmarklet
(admin clicks it on the logged-in iRacePlan survey page, pastes the JSON into the tool).

REST routes (all under `edr/v1`, capability-gated): `GET /tracks`, `GET /events`,
`POST /import {trackIds,surveyId}`, `GET /plan`, `POST /plan`.

## Regenerating the front-end (IMPORTANT)
`assets/builder.js` and `builder.css` are **generated**, not hand-written. The generator is
`edr-team-builder/build/assemble_builder.py`. It reads `EDR-Team-Builder.html`, extracts the
CSS and script, makes a few transforms (drops the embedded `SAMPLE`, makes event params
reassignable, adds the Setup tab + import/merge/shared-plan code), and writes the two asset
files. **To change builder behaviour:** edit `EDR-Team-Builder.html` (shared logic) and/or the
APPEND/markup strings in `assemble_builder.py`, then re-run it, then re-zip the plugin.
- The script has two hardcoded paths at the top (`SRC`, `OUT`) — update them to wherever this
  folder lives before running.
- Re-zip after changes: `zip -r edr-team-builder-V2.zip edr-team-builder -x "*.DS_Store"`.
- Do **not** hand-edit `builder.js` and expect it to stick; it gets overwritten on regenerate.

## Credentials (do not commit real values)
- Garage 61 API token (driving_data scope) — Garage 61 → My applications.
- iRacePlan API key — iRacePlan → Settings → API Keys.
They go in the plugin's Settings → EDR Team Builder. For local pulls, pass via env, never hardcode.

## State / what's done vs pending
- ✅ Front-end (builder.js) verified live in a browser against a **mocked** API: import,
  name-match review, bookmarklet paste, shared-plan save/load (open to any logged-in member).
- ✅ PHP backend is a faithful port of Python already validated against the live APIs.
- ⏳ **Not yet run on a real WordPress install** (the original dev box had no PHP/Docker).
  Next step: install the V2 zip on a WP site (or local wp-env/Docker), enter the two tokens,
  run a live import, confirm it reproduces `glen-teambuilder-data.json`.

## How to verify changes without a live site
There was no PHP/WordPress locally, so the front-end was tested with a tiny mock harness:
a static HTML page that defines `window.EDR_TB` and overrides `fetch` to return canned
`/tracks`, `/events`, `/import`, `/plan` responses, then loads `builder.js`. Recreate that to
test UI changes. For PHP, use `wp-env` or a Docker WordPress.

## Event reference (current)
6 Hours of the Glen, Watkins Glen (Boot), iRacePlan survey **1525**, 5 candidate start windows.
Brisbane (AEST, UTC+10) is the team timezone. Full details in `PROJECT.md`.

## Good first asks for Claude
"Read AI-HANDOFF.md and PROJECT.md, then [your change]." Examples: add min/max stints per
driver; export the stint plan; add a daily auto-refresh (WP cron); improve name matching;
make availability windows snap to the real session times.
