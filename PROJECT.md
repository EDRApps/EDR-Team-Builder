# EDR Endurance Team Planning — Project Reference

A complete record of how the EDR team-planning system works, so any teammate (or a
future chat) can pick it up with full context. Keep this file in `EDR/Planning` (iCloud)
and uploaded to the project.

---

## 1. Goal

Organise the EDR squad into balanced **Pro** and **Casual** teams for special endurance
events, using **real performance data** to decide the split and **iRacePlan** to handle who
is available and which stints they run.

---

## 2. Architecture — two tools, clear jobs

| Piece | Job | Owns the data? |
|-------|-----|----------------|
| **Garage 61** | Performance: practice laps, pace, clean-lap % per driver/car | Yes (telemetry) |
| **Team Builder** (`EDR-Team-Builder.html`) | Turns that into a **Pro/Casual split, per car class** | No — you feed it |
| **iRacePlan** | Availability survey + lineup/stint scheduling | Yes (availability) |

**Key principle:** performance and availability are separate. The builder only knows who has
*practised*. It does **not** know who's *available* — that stays in iRacePlan (which can't
export it), so the actual scheduling is done inside iRacePlan.

---

## 3. The workflow for an event

1. **Pull performance** — run `garage61-pull.py` in Google Colab with the Garage 61 token.
   Produces a `roster.json` (each driver's laps, median clean lap, clean-% per car).
   **Back the roster up in this folder.**
2. **Build the split** — open `EDR-Team-Builder.html`, **Import** the roster, **Load**. It groups
   drivers by class (GTP / LMP2 / GT3) and tags each **Pro** or **Casual**. Tune the weight
   sliders + Pro% cutoff.
3. **Assign in iRacePlan** — carry the Pro/Casual labels into iRacePlan's lineup planner. Put
   Pro drivers in the pro car entries, Casual in the rest; iRacePlan shows availability and
   auto-builds the stint rotation.

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

## 5. Data access reference

### Garage 61
- **API base:** `https://garage61.net/api/v1` · **Auth:** `Authorization: Bearer <token>`
- **Team slug:** `edr-endurotech` (sharing for driving activity, telemetry, setups is ON)
- **Token:** Garage 61 → "My applications" → Request a new API key (needs `driving_data` permission).
  There's already a team app, "Dom's iRacing bot."
- **Laps endpoint:** `GET /laps` with `tracks`, `teams=edr-endurotech`, `sessionTypes=1` (practice),
  `unclean=true`, `group=none`, `age=-1` (current season). Each lap has `lapTime`, `clean`, and a
  `car` object. Track IDs come from `GET /tracks`.

### iRacePlan
- **API base:** `https://iraceplan.com/api/v1` · **Auth:** `Authorization: Bearer <key>` (Settings → API Keys)
- **Works:** `GET /surveys/:id` (config only), `/surveys`, `/plannings`, `/plannings/:id`, `/schedule`, `/series`, `/user/me`
- **Does NOT work:** reading individual availability responses (no endpoint; `/surveys/:id/responses`
  is 404, `?include=responses` ignored). No documented lineup-write endpoint. **This is why
  availability/scheduling stays inside iRacePlan.**

---

## 6. The Glen event reference (survey id 1525)

- **Event:** 6 Hours of the Glen · Watkins Glen International — **Boot** · 20–21 Jun 2026 · 6h (360 min)
- **Classes:** multiclass — GTP, LMP2, GT3
- **Pool:** 44 drivers, 8 EDR entries; ~17 responses (38.6%) at last check
- **5 candidate start windows** (UTC → Brisbane AEST, UTC+10):

  | # | Start (UTC) | Start (AEST) |
  |---|-------------|--------------|
  | 1 | 2026-06-19 22:00 | Sat 08:00 |
  | 2 | 2026-06-20 07:00 | Sat 17:00 |
  | 3 | 2026-06-20 12:00 | Sat 22:00 |
  | 4 | 2026-06-20 16:00 | Sun 02:00 |
  | 5 | 2026-06-21 00:00 | Sun 10:00 |

- **Team entries (iRacing IDs):** #1 363782 · #2 428593 · #3 430262 · #4 484715 · #5 484751 ·
  #6 484753 · #7 484754 · #8 484752
- **Cars seen in driver preferences:** GTP — Acura ARX-06 GTP, Cadillac V-Series.R GTP;
  LMP2 — Dallara P217; GT3 — Ferrari 296 GT3, BMW M4 GT3 EVO, Ford Mustang GT3,
  Mercedes-AMG GT3, Porsche 911 GT3 R (992), Aston Martin Vantage GT3 EVO.

---

## 7. Files in this folder

| File | What it is |
|------|------------|
| `EDR-Team-Builder.html` | The tool. Double-click → opens in a browser. Import roster, get the split. Saves data on the computer it's opened on. Shareable — send the file to a teammate. |
| `garage61-pull.py` | Garage 61 pull script (run in Google Colab). Produces `roster.json`. |
| `iraceplan-api-notes.md` | iRacePlan API findings, limits, and feature-request list. |
| `00-START-HERE.md` | One-page quick start. |
| `PROJECT.md` | This file — the full reference. |

---

## 8. Key decisions & why

- **Availability is iRacePlan's job.** Its API can't export survey responses and the visual
  timeline can't be scraped, but iRacePlan owns the data and has a lineup optimiser — so the
  builder does the Pro/Casual split and iRacePlan does the timing.
- **Class-only comparisons.** GTP/LMP2/GT3 pace differs hugely, so pace/prep/clean are always
  normalised within a class — never across.
- **Clean-lap ratio for "incidents."** Simpler and single-source vs. pulling iRacing incident points.
- **Standalone HTML for persistence.** In-chat tools reset; a downloaded file saves locally and
  survives. The files (here in iCloud) are the durable record — not anyone's memory.

---

## 9. To send iRacePlan (feature request, priority order)

1. Read survey responses via the API (the big unlock).
2. Export availability (CSV/XLSX).
3. Programmatic lineup/planning writes.
4. Surface Garage 61 performance metrics in the API/planner.
5. Scoped, read-only API keys.
