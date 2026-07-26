# EDR Endurance Team Planning — start here

The goal: split the squad into **Pro** and **Casual** teams using real performance data, and
build a stint plan around when people can actually drive.

## How it fits together

- **Garage 61** gives us pace per driver, per car (median clean lap, lap count, clean-lap %).
- **Drivers tell us their availability** in the tool itself, in 2-hour blocks, per event.
- **The Team Builder** merges the two into a Pro/Casual split per car class, car entries, and a
  stint rotation.

Availability used to live in iRacePlan. It does not any more — iRacePlan could never export
survey responses, so collection was brought in-house in 2.3.0.

## Where the tool lives

| Thing | What it is |
|-------|------------|
| `EDR-Team-Builder.html` | The standalone tool. Open it in a browser. This is also the **source of truth for all front-end logic**. |
| `edr-team-builder/` | The same tool as a WordPress plugin for endurotechracing.com. Its assets are **generated** from the HTML — never hand-edit them. |
| `../Admin Folder/` | The zip to upload to WordPress, plus the note for whoever manages the site. |
| `colab_pull_garage61.py` | Reference Garage 61 pull (Google Colab). The plugin does this server-side now. |
| `iraceplan-api-notes.md` | Historical iRacePlan API notes. Kept for reference only. |

## To plan an event

Work left to right through the tabs: **Instructions → Event → Availability → Drivers → Teams →
Stints**.

1. **Event** — pick it from the calendar. That sets the availability window and race length.
2. **Availability** — drivers tick the 2-hour blocks they can race and hit Submit. Anyone with
   more than zero hours is in the pool for that event.
3. **Setup** (admin, WordPress build) — import Garage 61 pace for the event's track, and pull
   official iRacing session times if you want the real start times rather than derived ones.
4. **Drivers / Teams / Stints** — check the ranking, arrange the cars, then auto-fill and adjust
   the stint plan. Lock teams or stints once they are final so they stop being regenerated.

## Worth remembering

- **Pace is only ever compared within a class** — a GTP lap is never ranked against a GT3 lap.
- Garage 61 team slug: **edr-endurotech**. Pace queries use `age=-1` (current season).
- Fuller detail: `../PROJECT.md`. Working guidance for AI assistants: `../CLAUDE.md`.
