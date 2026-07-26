# Note for whoever manages endurotechracing.com

Hi, could you please install a small WordPress plugin for the team? It is a private
tool for planning our endurance line-ups. About 10 minutes, no coding.

**1. Install it**
- WordPress admin: Plugins → Add New → Upload Plugin.
- Upload the file `edr-team-builder-V2.zip`, then Install, then Activate.

**2. Enter the settings (I will send the values separately)**
- Go to Settings → "EDR Team Builder".
- **Garage 61 API token** — this is what pulls our lap times. It has to be the **team** key I
  send you, not a personal one, or the tool will only ever find one driver.
- **Garage 61 team slug** — leave this as `edr-endurotech`.
- **Builder admin password** — pick anything and tell me what it is. It lets me edit the plan
  from the page itself without needing a WordPress account. Leave it blank and only logged-in
  WordPress users can edit.
- **iRacing proxy URL and key** — optional. They pull the official session start times. Leave
  them blank and the tool just uses our own timings.
- Save. These are stored on the site and used only server-side. They are never sent to people
  viewing the page.

**3. Make a page for it**
- Create a new page called "Team Builder".
- **Make it private or password-protected**, not fully public. This one matters: the page shows
  the whole team's plan and everyone's availability, and the page's own privacy is the only
  thing keeping that off the open web. Drivers do not need a WordPress account — anyone who can
  open the page can submit their own availability, which is deliberate.
- Add a Shortcode block containing exactly: `[edr_team_builder]`
- Publish.

**One technical thing to check:** the plugin makes outbound web requests from the server (to
garage61.net, and to the iRacing proxy if you set one up). Most hosts allow this by default. If
the tool ever says it cannot reach those sites, the host may be blocking outbound requests and
would need to allow them.

That is everything. I will handle the rest from the page itself. Thanks!

---

*Upgrading later: same steps, upload the newer zip over the top and WordPress replaces it. The
settings and the saved plan are kept.*
