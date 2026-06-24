# iRacePlan API — notes & limits

**Base URL:** `https://iraceplan.com/api/v1`
**Auth:** header `Authorization: Bearer <your_api_key>`
**Where keys come from:** iRacePlan → Settings → API Keys (a Postman collection is downloadable there).

## What works (read)

- `GET /surveys/:id` — survey **config only**: the teams, the candidate session start times,
  and response counts. (The Glen availability survey is id **1525**.)
- `GET /surveys` — list surveys
- `GET /plannings`, `GET /plannings/:id` — lineups / plannings
- `GET /schedule`, `GET /series`, `GET /user/me`

## ⭐ UPDATE 2026-06-14 — availability IS reachable (via plannings, not surveys)

Re-probed the live API. The survey endpoints still hide responses, **but team
plannings expose per-driver availability directly** — the unlock we'd been missing.

- `GET /plannings` — lists all plannings (paged, `limit`/`offset`). Filter by
  `type == "team"` and the team entry in `registrable`.
- `GET /plannings/:id` — for a **team** planning returns:
  - `roster` — the drivers on that car entry (`{iracing_id, name}`)
  - `driver_availabilities` — **per driver, a list of `periods`**:
    `{status: "available", start_time, end_time, notes}` ← the availability data
  - `strategies` — stint plans incl. each driver's avg lap time (dry/wet), fuel
    use and stint count
  - `result` — finishing data for past races
- Availability flows **survey → planning**: it only appears once a *team planning
  exists* for the event (and drivers have responded). Proven on a past event
  (Nürburgring planning 17947999: roster of 5 with availability windows).

**Implication for automation:** once the team plannings for an event are created
in iRacePlan, the whole "teams + availability" join is doable on the documented
API — no scraping. See the EDR automation pipeline.

**Still missing for the Glen (survey 1525):** no team planning exists yet (still
in survey phase, 43% responded), so the *current* survey responses are not yet
reachable by API. Options: (a) create the team plannings, then read availability
per the above; (b) read live survey responses from the web app via a logged-in
browser session — **done, see below**.

## ⭐ Survey-phase extraction (works now) — browser DOM scrape

iRacePlan is a server-rendered Rails app (Turbo/Stimulus), **not** a JSON API —
the survey responses are baked into the HTML of `https://iraceplan.com/surveys/<id>`
(the "Driver Availability Timeline"). There is no XHR/JSON to intercept. Method
(done 2026-06-14 for survey 1525, via the Claude-in-Chrome extension on a
logged-in session):

1. Open `/surveys/1525`. The timeline renders one row per responder.
2. Each row is a strip of cells: `bg-green-500` = available, `bg-red-500` = not.
   Cells are **merged into variable-width blocks**, so cell *count* ≠ time —
   map each block by its pixel x-position onto the window.
3. Window = **33h starting 2026-06-19T22:00Z** (= 20 Jun 08:00 AEST). 30-min
   resolution. `offset_min = (cell.left - strip.left) / strip.width * 1980`.
4. Car prefs + name come from the row header text.

Output saved to `glen-availability.json` (19 responders: hours, %, windows in
UTC+AEST, and availability vs each of the 5 candidate starts). Caveats: windows
are pixel-derived (snapped to 30 min); driver names carry iRacing suffixes
(e.g. "Chris Wilson6") that need matching to Garage 61 names.

## What does NOT work — the key limitation

- **You cannot read the individual availability responses via the API.** `GET /surveys/:id`
  returns counts but not who is available when. `GET /surveys/:id/responses` → 404, and
  `?include=responses` is ignored. So the actual availability answers stay locked inside
  iRacePlan — which is exactly why the scheduling has to be done inside iRacePlan itself.
- **No documented way to write lineups** via the API. Plannings appear read-only; the only
  writes are `POST /surveys` (create a survey) and `POST /surveys/:id/responses` (submit a response).

## Glen event facts (survey 1525)

- 8 team entries (EDR #1–#8), 44 drivers in the pool, 5 candidate start windows.

## Feature request to send iRacePlan (in priority order)

1. **Read survey responses via the API** — the single biggest unlock. An endpoint returning each
   response (driver, team, overall availability, the detailed availability times, car preferences).
2. **Export availability (CSV/XLSX)** — a no-code way to get the survey responses out.
3. **Programmatic lineup/planning writes** — so an external tool can push a finished lineup back in.
4. **Surface Garage 61 performance metrics** (laps / pace / clean-%) in the API and the planner,
   so Pro/Casual tiering can be automated instead of pulled separately.
5. **Scoped, read-only API keys** — so members can use their own limited key rather than an admin's.

The throughline: *the availability we collect through the surveys can't be read back out, by API
or export, which forces everything downstream to be manual. Read access to responses unlocks the rest.*
