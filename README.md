# EDR Team Builder

Internal endurance team-planning system for **Endurotech Racing (EDR)**, a GT3/GTP iRacing
endurance team. It turns real performance and availability data into balanced Pro/Casual
team line-ups and stint rotations for events.

## What's here

- **`files/EDR-Team-Builder.html`** — the self-contained planning tool (open in a browser).
  Scores drivers on pace, clean-lap % and prep, splits each car class into Pro/Casual, builds
  balanced car entries, and lays out a stint plan that respects each driver's availability and
  the session start time. Currently loaded with **24 Hours of Spa** data.
- **`files/edr-team-builder/`** — the same tool packaged as a **WordPress plugin (V2)** for
  endurotechracing.com (`edr-team-builder-V2.zip`). REST routes pull data server-side; a
  shortcode renders the builder. Built and verified, not yet deployed live.
- **Deliverables (PDFs)** — race debriefs and car-selection briefs in the EDR brand
  (e.g. the Glen 6h debrief, the Spa car-selection indications).
- **Reference** — `PROJECT.md`, `AI-HANDOFF.md`, and `files/iraceplan-api-notes.md` document
  the data sources and how the pieces fit together.

## Data sources

- **Garage 61** — performance (practice/race laps to pace, lap count, clean-lap %, iRating).
- **iRacePlan** — driver availability surveys and the stint timeline.
- **iRacing** — official race results (via a logged-in browser session).

Pace data is pulled for the current iRacing season only (Season 3 onwards).

## Credentials

API keys are **never** committed. They are entered into the WordPress plugin settings
(server-side) for production, or supplied at run time for local pulls. See `.gitignore`.
Rotate keys in Garage 61 / iRacePlan if ever exposed.

## Building & releasing the plugin

The plugin's `assets/builder.js` and `builder.css` are **generated** from
`files/EDR-Team-Builder.html` by `files/edr-team-builder/build/assemble_builder.py`
(it strips the embedded sample data and wires in the Setup/import code). Build locally with:

```bash
python files/edr-team-builder/build/assemble_builder.py
```

GitHub Actions automate this:

- **Build plugin** (`.github/workflows/ci.yml`) — on every push/PR it rebuilds the assets,
  lints the PHP, and uploads the installable zip as a workflow artifact.
- **Release plugin** (`.github/workflows/release.yml`) — pushing a version tag builds the
  plugin and publishes a **GitHub Release** with the zip attached:

  ```bash
  git tag v2.0.1
  git push origin v2.0.1
  ```

  The Release zip (`edr-team-builder-<version>.zip`) is what you upload in
  WordPress → Plugins → Add New → Upload Plugin.
