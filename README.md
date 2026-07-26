# EDR Team Builder

Internal endurance team-planning system for **Endurotech Racing (EDR)**, a GT3/GTP iRacing
endurance team. It turns real performance and availability data into balanced Pro/Casual
team line-ups and stint rotations for events.

## What's here

- **`files/EDR-Team-Builder.html`** — the self-contained planning tool (open in a browser), and
  the **source of truth for all front-end logic**. Scores drivers on pace, clean-lap % and prep,
  splits each car class into Pro/Casual, builds balanced car entries, and lays out a stint plan
  that respects each driver's availability and the session start time. Ships with **24 Hours of
  Spa** sample data embedded.
- **`files/edr-team-builder/`** — the same tool packaged as a **WordPress plugin** for
  endurotechracing.com. REST routes pull data server-side; a shortcode renders the builder.
  Its front-end assets are **generated** from the HTML above.
- **`Admin Folder/`** — what the website admin needs: `edr-team-builder-V2.zip` to upload, and
  `HANDOFF.md` with the install steps. Both are produced by `build/package_plugin.py`.
- **Deliverables (PDFs)** — race debriefs and car-selection briefs in the EDR brand
  (e.g. the Glen 6h debrief, the Spa car-selection indications).
- **Reference** — `PROJECT.md` (full architecture), `AI-HANDOFF.md` (resume brief), and
  `files/iraceplan-api-notes.md` (historical).

## Data sources

- **Garage 61** — performance (practice/race laps to pace, lap count, clean-lap %, iRating).
- **Availability** — collected in the tool itself, in 2-hour blocks, per event. iRacePlan was
  removed in 2.3.0: its API could never export survey responses.
- **iRacing** — official session times and results, via a teammate's Data API proxy.

Pace data is pulled for the current iRacing season only (Season 3 onwards).

## Credentials

API keys are **never** committed. They are entered into the WordPress plugin settings
(server-side) for production, or supplied at run time for local pulls. See `.gitignore`.
Rotate keys in Garage 61 / iRacePlan if ever exposed.

## Building & releasing the plugin

The plugin's `assets/builder.js` and `builder.css` are **generated** from
`files/EDR-Team-Builder.html` by `files/edr-team-builder/build/assemble_builder.py`
(it strips the embedded sample data and wires in the Setup/import code). Never hand-edit them.

```bash
python files/edr-team-builder/build/assemble_builder.py   # regenerate the assets
python files/edr-team-builder/build/package_plugin.py     # rebuild + refresh the uploadable zip
node --check files/edr-team-builder/assets/builder.js     # parse-check after any rebuild
```

GitHub Actions automate this:

- **Build plugin** (`.github/workflows/ci.yml`) — on every push/PR it rebuilds the assets and
  **fails if the committed assets or the committed zip are out of date**, parse-checks the
  bundle, lints the PHP, and uploads the installable zip as a workflow artifact.
- **Release plugin** (`.github/workflows/release.yml`) — bump the version in
  `files/edr-team-builder/edr-team-builder.php`, then push a matching tag to publish a
  **GitHub Release** with the zip attached:

  ```bash
  git tag v2.4.10
  git push origin v2.4.10
  ```

## Installing on WordPress

Upload a zip in WordPress → Plugins → Add New → Upload Plugin. Either works:

- `Admin Folder/edr-team-builder-V2.zip` — the copy kept in the repo, alongside the install
  note. Regenerate it with `package_plugin.py` whenever the version changes; CI checks it.
- `edr-team-builder-<version>.zip` from the GitHub Release — built fresh from the tag.
