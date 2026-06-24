# EDR Endurance Team Planning — how it all works

The goal: split the squad into **Pro** and **Casual** teams using real performance
data, and let iRacePlan handle who's available and when. Two tools, clear jobs.

## The setup

- **Garage 61** → performance data (practice laps, pace, clean-lap %) per driver, per car.
- **Team Builder** (the HTML file) → turns that into a **Pro/Casual split, per car class**.
- **iRacePlan** → the availability survey + the lineup/stint scheduling. It owns that data.

Performance and availability are deliberately separate: the builder only knows who has
**practised** (from Garage 61). It does **not** know who's **available** — that lives in
iRacePlan and can't be exported (see the iRacePlan notes).

## Files in this folder

| File | What it is |
|------|------------|
| `EDR-Team-Builder.html` | The tool. Double-click to open in a browser. Import your roster, get the split. Saves your data on this computer. Send it to a teammate to share. |
| `garage61-pull.py` | The script that pulls practice data from Garage 61 (run it in Google Colab). Produces the roster you paste into the builder. |
| `iraceplan-api-notes.md` | What we learned about iRacePlan's API and its limits, plus a feature-request summary. |
| `00-START-HERE.md` | This file. |

## To plan an event (e.g. 6 Hours of the Glen)

1. **Pull the data.** Open Google Colab, paste in `garage61-pull.py`, run it, and paste your
   Garage 61 token when asked. It's pre-set for team `edr-endurotech`, Watkins Glen (Boot),
   current season, practice laps. Copy the roster it prints — **and back it up in this folder.**
2. **Build the split.** Open `EDR-Team-Builder.html`, click **Import**, paste the roster, hit
   **Load**. It groups drivers by class (GTP / LMP2 / GT3) and tags each **Pro** or **Casual**
   from pace + practice laps + clean-lap %. Use the weight sliders and the Pro % cutoff to taste.
3. **Assign in iRacePlan.** Take the Pro/Casual labels into iRacePlan's lineup planner. Put your
   Pro drivers in the pro car entries, Casual in the rest. iRacePlan shows each driver's
   availability and auto-builds the stint rotation across the race.

## Worth remembering

- **Pace is only ever compared within a class** — a GTP lap is never ranked against a GT3 lap.
- The reliable memory is **these files**, not any chat. Keep them here in iCloud so they sync
  and never vanish.
- Garage 61 team slug: **edr-endurotech**.  ·  Glen availability survey id: **1525**.
