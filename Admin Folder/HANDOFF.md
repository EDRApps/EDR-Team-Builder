# Note for whoever manages endurotechracing.com

Hi, could you please install a small WordPress plugin for the team? It is a private
tool for planning our endurance line-ups. About 10 minutes, no coding.

**1. Install it**
- WordPress admin: Plugins → Add New → Upload Plugin.
- Upload the file `edr-team-builder-V2.zip`, then Install, then Activate.

**2. Enter two keys (I will send these separately)**
- Go to Settings → "EDR Team Builder".
- Paste the Garage 61 token and the iRacePlan API key I send you, and Save.
- (These are stored on the site and only used server-side. They are never shown to
  people viewing the page.)

**3. Make a page for it**
- Create a new page called "Team Builder".
- Make it visible to logged-in team members (members-only / behind login), not fully
  public. The tool itself handles the rest: admins can edit the plan, and everyone
  else who can log in sees the same plan read-only.
- Add a Shortcode block containing exactly: [edr_team_builder]
- Publish.

**One technical thing to check:** the plugin makes outbound web requests from the
server (to garage61.net and iraceplan.com). Most hosts allow this by default. If the
tool ever says it cannot reach those sites, the host may be blocking outbound
requests and would need to allow them.

That is everything. I will handle the rest from the page itself. Thanks!
