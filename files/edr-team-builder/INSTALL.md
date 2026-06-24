# EDR Team Builder — install guide

A WordPress plugin that pulls Garage 61 pace and iRacePlan availability, builds
Pro/Casual teams and stint plans for an event. Built for endurotechracing.com.

## 1. Install the plugin
1. In WordPress admin: **Plugins → Add New → Upload Plugin**.
2. Upload `edr-team-builder.zip`, then **Install** and **Activate**.

## 2. Enter the credentials (once)
1. Go to **Settings → EDR Team Builder**.
2. Paste:
   - **Garage 61 API token** — Garage 61 → My applications → API key (needs the
     `driving_data` permission).
   - **iRacePlan API key** — iRacePlan → Settings → API Keys.
   - **Garage 61 team slug** — leave as `edr-endurotech` unless it changes.
3. Save. These are stored on the site and used only server-side; they are never
   sent to visitors' browsers.

## 3. Add the tool to a page
1. Create a new page (suggested title: "Team Builder"). Make it **Private** or
   members-only so only your admins can open it.
2. Add a Shortcode block (or paste into a normal block):
   ```
   [edr_team_builder]
   ```
3. Publish. Open the page while logged in.

## 4. Use it (shared plan: admins edit, members view)
There is **one shared team plan** stored on the site. Everyone who opens the page sees
the same thing. Admins/editors can change it; everyone else sees it **read-only**
(no Setup tab, no controls) and just views Teams / Drivers / Stints.

As an admin, on the **Setup** tab: the **nearest event is auto-selected**; pick the
**track** (Garage 61) and press **Import / Refresh now**.
- Pace comes straight from Garage 61.
- If a team planning already exists for the event, availability comes from
  iRacePlan automatically.
- If it is still survey phase (no planning yet), use the availability grabber:

### Availability grabber (survey phase)
Two options, both run in **your own logged-in iRacePlan tab**:
- **Bookmarklet:** create a new browser bookmark, and paste the contents of
  `assets/bookmarklet-url.txt` as its URL. Open the iRacePlan survey page, click the
  bookmark, then paste the result into the Team Builder's **Availability** box and
  press **Merge availability**.
- **Or console:** open the survey page, open the browser console, paste the contents
  of `assets/bookmarklet.js`, run it, then paste the copied result into the tool.

Then use the **Teams** and **Stints** tabs as normal (weights, Pro %, per-car
sessions, drag-and-drop).

## Requirements / notes
- The host must allow outbound HTTP from PHP (`wp_remote_get`). This is standard;
  a few locked-down managed hosts disable it. If imports fail with a network error,
  ask the host to allow outbound requests to `garage61.net` and `iraceplan.com`.
- Only logged-in users who can edit posts can run an import (the data routes are
  capability-gated). The settings page is admin-only.
- Rotate the tokens if an admin leaves the team.
