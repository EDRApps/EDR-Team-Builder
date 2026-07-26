# EDR Team Builder — handoff (resume this project)

**Read this first**, then `PROJECT.md` for the full reference and `CLAUDE.md` for working
guidance. Current as of plugin **2.4.10**.

## What this project is

A planning system for **Endurotech Racing (EDR)**, a GT3/GTP iRacing endurance team. It turns
**pace** (Garage 61) and **availability** (collected in-house) into a Pro/Casual split per car
class, balanced car entries, and a stint plan that respects who can actually drive when.

## The one architectural fact that matters

The tool exists twice, and one is generated from the other:

- **`files/EDR-Team-Builder.html`** — standalone single-file app, and the **source of truth for
  all front-end logic**. Opens in a browser, state in `localStorage`.
- **`files/edr-team-builder/`** — the same tool as a **WordPress plugin**. Its
  `assets/builder.js` and `builder.css` are **built from the HTML** by
  `build/assemble_builder.py`.

**Never hand-edit `builder.js` / `builder.css`.** Change the HTML (core logic) or the
`APPEND` / `APP_HTML` strings in `assemble_builder.py` (WordPress glue), then rebuild.

```bash
python files/edr-team-builder/build/assemble_builder.py   # regenerate the assets
python files/edr-team-builder/build/package_plugin.py     # rebuild + write the uploadable zip
node --check files/edr-team-builder/assets/builder.js     # always parse-check after a rebuild
```

The assembler concatenates strings into one IIFE, so a new top-level `const` in the HTML that
collides with the WordPress layer is a SyntaxError that kills the app with no console output.
CI runs the parse check and fails on stale committed assets or a stale zip.

## Hard facts (do not relearn these the hard way)

1. **Garage 61 and iRacePlan both block direct browser calls (no CORS).** Every pull is
   server-side (the plugin) or a local script. The browser owns the merge and the scoring.
2. **Garage 61:** base `https://garage61.net/api/v1`, `Authorization: Bearer <token>` with the
   `driving_data` scope, team slug `edr-endurotech`. Pull `/laps` with `tracks`, `teams`,
   `unclean=true`, `group=none`, `age=-1`. The key must be **team-scoped** or the import returns
   only the key owner's laps. Driver names come from `firstName`+`lastName`; the `name` field is
   empty or a digit-suffixed iRacing name that never matches.
3. **iRacePlan is gone** (removed in 2.3.0). Its API could not export survey responses, which is
   why availability was brought in-house. `files/iraceplan-api-notes.md` is history only.
4. **iRacing data** comes through a teammate's proxy (GET-only, one shared account, cached).
   `invalid_grant: expired` on every call means the bot's session lapsed — the proxy owner has
   to re-authenticate it, nothing local will fix it.
5. **Availability is per event, in 2-hour blocks, and writes are advisory** — any device can
   edit any driver's blocks. The old hard per-device lock was removed in 2.4.2 because it locked
   drivers out of their own availability when they switched phone to PC.
6. **The event pool rule:** with an event selected, Drivers/Teams/Stints only include drivers
   with more than zero hours of availability for that event.

## Role model

Default is a read-only driver who can submit their own availability. Admin is a logged-in
WordPress user, or anyone entering the builder admin password (plugin Settings → `edit_pass`,
checked server-side via `POST /auth`, sent as `X-EDR-Pass` on writes). `GET /plan` and
`GET/POST /avail` are public by design — **the builder page itself must stay private or
password-protected**, that is the only fence.

## How to verify a change without a live WordPress

There is no PHP locally. For the front end, build a small harness: a static page that defines
`window.EDR_TB`, overrides `fetch` to return canned `/plan`, `/avail`, `/roster`, `/auth`,
`/tracks`, `/import`, `/iracing` responses, then loads `builder.js`. Serve it and drive the UI.
The standalone HTML can be opened directly. PHP changes are linted by CI (`php -l`); for real
runtime testing use `wp-env` or a Docker WordPress.

## Current state

- Plugin at **2.4.10**, deployed to endurotechracing.com via the zip in `Admin Folder/`.
- Availability, scoring, teams and stints all working; the calendar is date-aware.
- The standalone HTML still ships **Spa 24h** sample data (`KEY = 'edrTeamBuilder_spa_v15'`),
  which is a past event. Refresh it per the recipe in `CLAUDE.md` when it next matters.

## Asking for a change

"Read AI-HANDOFF.md and PROJECT.md, then [your change]." Good examples: add min/max stints per
driver, change the scoring weights, add an event to `CAL_EVENTS`, adjust the stint auto-fill.
