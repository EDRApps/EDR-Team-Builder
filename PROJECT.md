# EDR Endurance Team Planning — Project Reference

A complete record of how the EDR team-planning system works, so any teammate (or a future
chat) can pick it up with full context.

> **Status:** current as of plugin **2.4.10**. Availability was brought in-house in 2.3.0 and
> iRacePlan was removed entirely — if you are reading an older note that says availability
> lives in iRacePlan, that note is out of date. See §9 for what changed.

---

## 1. Goal

Organise the EDR squad into balanced **Pro** and **Casual** teams for endurance events, using
**real performance data** to decide the split and **the team's own availability collection** to
decide who can drive when, then lay out a stint plan around both.

---

## 2. Architecture — one tool, two builds

The Team Builder exists twice, and one is **generated** from the other.

| Piece | Job |
|-------|-----|
| **`files/EDR-Team-Builder.html`** | The standalone single-file app, and the **source of truth for all front-end logic** — calendar, availability, scoring, Pro/Casual split, teams, stints, theme. Opens straight in a browser; state persists in `localStorage`. |
| **`files/edr-team-builder/`** | The same tool as a **WordPress plugin** for endurotechracing.com. `assets/builder.js` and `builder.css` are **built from the HTML** by `build/assemble_builder.py`. |

**Never hand-edit `assets/builder.js` or `builder.css`** — they are overwritten on the next
build. Change core behaviour in the HTML, or the WordPress glue in the `APPEND` / `APP_HTML`
string literals inside `build/assemble_builder.py`.

Data sources by job:

| Source | Job | Owns the data? |
|--------|-----|----------------|
| **Garage 61** | Pace: practice/race laps to median clean lap, lap count, clean-lap %, iRating | Yes (telemetry) |
| **The Availability tab** | Who can drive which 2-hour blocks of the event window | Yes (stored in WordPress) |
| **iRacing** (via a teammate's proxy) | Official session start times and race lengths | Yes (official) |

**Key principle:** pace and availability are separate signals. The builder knows who has
*practised* from Garage 61 and who is *available* from the drivers themselves, and merges them
in the browser. The PHP backend is deliberately thin.

---

## 3. The workflow for an event

Tab order is the flow: **Instructions → Event → Availability → Drivers → Teams → Stints**
(the WordPress build adds an admin-only **Setup** tab in front).

1. **Event** — pick the event from the season calendar (`CAL_EVENTS` in the HTML). Selecting it
   sets the availability window, race length and start options, and resets the stint plan.
2. **Availability** — drivers tick the 2-hour blocks they can race and hit Submit. Stored per
   event, server-side. Anyone with availability of more than zero hours is in the event pool.
3. **Setup / import** (admin) — pull Garage 61 pace for the event's track(s), and optionally
   pull official iRacing session times to override the calendar's derived timing.
4. **Drivers** — the ranking the split is built from, scored within each car class.
5. **Teams** — Pro and Casual entries per class; can be locked so manual line-ups stick.
6. **Stints** — per-car rotation over the race, respecting availability; drag to adjust, and
   lock when final.

---

## 4. How the split is calculated

For each driver, three signals (from Garage 61), each scored **0–1 within their car class only**:

- **Pace** — median clean lap time (faster = higher).
- **Prep** — number of practice laps (more = higher).
- **Clean** — clean-lap % (tidier = higher).

`score = pace×Wpace + clean×Wclean + prep×Wprep` (weights are relative; default **Pace 50 /
Clean 30 / Prep 20**). Within each class, drivers are ranked by score and the **top X%** become
Pro (default **40%**), the rest Casual.

> Cleanliness uses Garage 61's **clean-lap ratio** (no off-track / wall contact), **not** iRacing
> incident points — those would need the iRacing API and aren't worth the extra source.

**Class detection** (from the car name): contains `GTP`/`HYBRID`/`LMDH` → GTP · `LMP2`/`P217`/`LMP`
→ LMP2 · `GT4` → GT4 · `GT3` → GT3 · otherwise → "Other" (rename/reassign the car if that happens).

---

## 5. Availability model

- **Strictly per event.** Blocks are stored under `state.availStore[evKey][driverName]`, where
  `evKey` is `"<event name>|<YYYY-MM-DD>"`. Switching events does not carry availability over.
- **2-hour blocks** (`AV_BLOCK`, 120 min) across the event window. `slotsToAvail()` and
  `windowsToSlots()` convert between block ticks and the `{hours, pct, starts, windows}` shape
  that scoring and stints consume.
- **The event pool rule:** with an event selected, Drivers/Teams/Stints only include drivers
  with `avail.hours > 0` for that event. Submitting availability is what puts you in the pool
  (with no pace until the next Garage 61 import).
- **Writes are advisory** (since 2.4.2). Any device may edit any driver's blocks; the team runs
  on trust. The earlier hard "first device to submit owns the name" lock was dropped because it
  kept locking drivers out of their own availability when they moved from phone to PC — with no
  accounts, the server cannot tell "same person, new device" from "someone else".
- Drivers identify themselves by **iRacing customer ID** where the roster provides one
  (`GET /roster?lookup=`), falling back to picking their name from the Garage 61 membership list.

---

## 6. Roles and access

- **Driver (default, no account):** browse the calendar, pick an event, submit their own
  availability. Everything else is read-only.
- **Admin:** a logged-in WordPress user, or anyone who enters the **builder admin password**
  (plugin Settings → `edit_pass`, verified server-side via `POST /auth`, sent as `X-EDR-Pass`
  on writes). Admins edit Drivers/Teams/Stints and run imports.
- The unlock is an **inline header password field**, never `window.prompt` — that is blocked in
  embedded and iframe views, and the Admin button would silently do nothing.
- The standalone HTML has its own `ADMIN_HASH` check. That hash sits in the file and the check
  runs in the browser, so it is a convenience gate, not a security boundary — anyone with the
  file can read it. Real enforcement is server-side in the WordPress build.
- `GET /plan` and `GET/POST /avail` are **public by design**. The builder page itself must stay
  private or password-protected; that is the only thing keeping team data off the open web.

### REST surface (`/wp-json/edr/v1/`)

| Route | Access | Purpose |
|-------|--------|---------|
| `GET /plan` | public | the single shared plan (`edr_tb_plan` option) |
| `POST /plan` | edit | save the plan; optimistic concurrency via `baseRev`, 409 on a stale write |
| `GET/POST /avail` | public | per-driver availability per event (`edr_tb_avail`) |
| `GET /roster` | public | Garage 61 membership names + iRacing IDs, cached 6h |
| `POST /auth` | public | verify the admin password (rate-limited per IP) |
| `GET /tracks` | edit | Garage 61 track list, cached 24h |
| `POST /import` | edit | Garage 61 pace pull for the chosen track(s) |
| `GET /iracing` | edit | official session times via the proxy, cached 12h |

---

## 7. Data access reference

### Garage 61
- **API base:** `https://garage61.net/api/v1` · **Auth:** `Authorization: Bearer <token>`
- **Team slug:** `edr-endurotech` (sharing for driving activity, telemetry, setups is ON)
- **Token:** Garage 61 → "My applications" → Request a new API key (needs `driving_data`).
  The key must be **team-scoped** — a personal key returns only the owner's laps, which shows up
  as "the import only found one driver".
- **Laps endpoint:** `GET /laps` with `tracks`, `teams=edr-endurotech`, `unclean=true`,
  `group=none`, `age=-1` (current season). Track IDs come from `GET /tracks`.
- **Driver names** are built from `firstName`+`lastName`. The API's `name` field is empty or a
  digit-suffixed iRacing name ("Sam Millar2") that never matches the roster.
- `tracks` is required and takes about 10 IDs per request; `limit` maxes out at 1000; there is
  no aggregate endpoint and no per-driver filter, so paginate and tally client-side.

### iRacing (official results, schedules, session times)
- Reached through **a teammate's proxy**, which runs off their iRacing account. Base URL and
  bearer key go in plugin Settings (server-side only) or `~/.config/edr/secrets.env` for local
  scripts. Prefix any members-ng `/data/...` path with the proxy URL.
- **GET-only, one shared account** — back off on 429 and cache. Never put the key in
  browser-side code.
- If every call returns `invalid_grant: expired`, the bot's own iRacing session has lapsed.
  Nothing local is wrong; the proxy owner must re-authenticate it.

### iRacePlan (historical — no longer used)
Removed from the plugin in 2.3.0. Its API could never export individual survey responses, which
is exactly why availability was brought in-house. `files/iraceplan-api-notes.md` is kept only as
a record for old local scripts.

### CORS
Both Garage 61 and iRacePlan block direct browser calls, so every pull happens server-side (the
plugin) or in a local script. The browser owns the merge and the scoring.

---

## 8. Build and release

```bash
python files/edr-team-builder/build/assemble_builder.py   # regenerate builder.js / builder.css
python files/edr-team-builder/build/package_plugin.py     # rebuild, then write the uploadable zip
```

- Paths are env-overridable: `EDR_SRC` / `EDR_OUT` for the assembler, `EDR_ZIP` for the packager.
- **Always parse-check the generated bundle after a rebuild** (`node --check
  files/edr-team-builder/assets/builder.js`). The assembler is string concatenation inside one
  IIFE, so a new top-level `const` in the HTML that collides with the WordPress layer is a
  SyntaxError that kills the app with no console output. CI does this on every push.
- **CI** (`.github/workflows/ci.yml`) rebuilds the assets, fails if the committed assets or the
  committed zip are stale, parse-checks the bundle, lints the PHP and uploads the zip artifact.
- **Release** (`.github/workflows/release.yml`) — bump the version in `edr-team-builder.php`,
  then push a matching tag to build and publish a GitHub Release with the zip attached:

  ```bash
  git tag v2.4.10 && git push origin v2.4.10
  ```

- The zip the website admin uploads lives at `Admin Folder/edr-team-builder-V2.zip`, next to the
  install instructions that name it. Regenerate it with `package_plugin.py`; CI fails if its
  version does not match the plugin source.

There is no test suite, linter or package manifest for the repo as a whole.

---

## 9. Key decisions and why

- **Availability is ours, not iRacePlan's.** iRacePlan's API cannot export survey responses and
  the visual timeline cannot be scraped, so the data was never reachable. Collecting 2-hour
  blocks in-house made availability a first-class input to scoring and stints instead of
  something living in another tool. iRacePlan was removed in 2.3.0.
- **Advisory availability writes, not hard locks.** See §5 — the lock hurt real drivers more
  than it stopped anything.
- **Class-only comparisons.** GTP/LMP2/GT3 pace differs hugely, so pace, prep and clean are
  always normalised within a class, never across.
- **Clean-lap ratio for "incidents."** Simpler and single-source versus pulling iRacing incident
  points.
- **One shared plan, page privacy as the fence.** Reading the plan is public because the page it
  lives on is private. Keep it that way.
- **The HTML is the source of truth.** Two builds, one brain. Anything else drifts.

---

## 10. Reference docs

| File | What it is |
|------|------------|
| `README.md` | Overview and release flow |
| `CLAUDE.md` | Working guidance for this repo (root) and the plugin (`files/edr-team-builder/`) |
| `AI-HANDOFF.md` | Short resume brief |
| `files/edr-team-builder/INSTALL.md` | Plugin install and settings |
| `Admin Folder/HANDOFF.md` | The note for whoever manages the website, plus the zip to upload |
| `files/iraceplan-api-notes.md` | Historical iRacePlan API limits |
