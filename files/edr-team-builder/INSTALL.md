# EDR Team Builder — install guide

A WordPress plugin that pulls Garage 61 pace, collects driver availability itself, and
builds Pro/Casual teams and stint plans for an event. Built for endurotechracing.com.

## 1. Install the plugin
1. In WordPress admin: **Plugins → Add New → Upload Plugin**.
2. Upload `edr-team-builder.zip`, then **Install** and **Activate**.

## 2. Enter the credentials (once)
1. Go to **Settings → EDR Team Builder**.
2. Paste:
   - **Garage 61 API token** — Garage 61 → My applications → API key (needs the
     `driving_data` permission).
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

## 4. Use it (shared plan: any logged-in member can edit)
There is **one shared team plan** stored on the site. Everyone who opens the page sees
the same thing, and **any logged-in member can edit it** (Setup tab, controls, save) —
the admin gate has been removed. Only the **Settings page** (which holds the API keys)
stays admin-only.

As an admin, on the **Setup** tab: the **nearest event is auto-selected**; pick the
**track** (Garage 61) and press **Import / Refresh now**.
- Pace comes straight from Garage 61.
- Availability comes from the drivers themselves: they open the page, enter their iRacing
  ID, pick the race and tick the 2-hour blocks they can drive.

Then use the **Teams** and **Stints** tabs as normal (weights, Pro %, per-car
sessions, drag-and-drop).

## Requirements / notes
- The host must allow outbound HTTP from PHP (`wp_remote_get`). This is standard;
  a few locked-down managed hosts disable it. If imports fail with a network error,
  ask the host to allow outbound requests to `garage61.net` and `discord.com`.
- Only logged-in users who can edit posts can run an import (the data routes are
  capability-gated). The settings page is admin-only.
- Rotate the tokens if an admin leaves the team.
