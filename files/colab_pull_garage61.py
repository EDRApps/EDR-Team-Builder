# ============================================================
#  EDR - Endurotech  ·  Garage 61 lap puller (Google Colab version)
#  Watkins Glen (Boot) · current season · practice laps
#
#  HOW TO USE (no installing anything):
#   1. Go to  https://colab.research.google.com  and sign in with Google.
#   2. File > New notebook.
#   3. Paste this WHOLE script into the grey box.
#   4. Click the round ▶ (play) button on the left of the box.
#   5. When it asks, paste your Garage 61 token and press Enter.
#   6. Wait ~30s. It prints your roster at the bottom — copy everything
#      between the two ===== lines and paste it into the builder's Import box.
#
#  Your token is typed in at runtime (hidden), not saved in the notebook.
#  Keep this notebook private; you can delete it when you're done.
# ============================================================

import json
import statistics
from getpass import getpass
import requests

TEAM_SLUG = "edr-endurotech"
TRACK_NAME = "Watkins Glen"
TRACK_VARIANT = "Boot"
SEASON_AGE = -1          # current season (= S2 right now)
SESSION_TYPES = "1"      # 1 = Practice
BASE = "https://garage61.net/api/v1"

token = getpass("Paste your Garage 61 token and press Enter: ").strip()
S = requests.Session()
S.headers.update({"Authorization": f"Bearer {token}"})


def get_paged(path, params):
    items, offset = [], 0
    while True:
        r = S.get(f"{BASE}{path}", params=dict(params, limit=1000, offset=offset), timeout=30)
        r.raise_for_status()
        data = r.json()
        batch = data.get("items", [])
        items.extend(batch)
        total = data.get("total", len(items))
        offset += len(batch)
        if not batch or offset >= total:
            break
    return items


# 1. find the track
tracks = get_paged("/tracks", {})
match = next((t for t in tracks
             if TRACK_NAME.lower() in (t.get("name") or "").lower()
             and TRACK_VARIANT.lower() in (t.get("variant") or "").lower()), None)
if not match:
    raise SystemExit("Couldn't find Watkins Glen Boot — check your token has access.")
print(f"Track: {match.get('name')} - {match.get('variant')} (id={match['id']})")

# 2. pull the team's practice laps
laps = get_paged("/laps", {
    "tracks": str(match["id"]),
    "teams": TEAM_SLUG,
    "sessionTypes": SESSION_TYPES,
    "unclean": "true",
    "group": "none",
    "age": str(SEASON_AGE),
})
print(f"Pulled {len(laps)} laps\n")

# 3. tally per driver / per car
bucket = {}
for lap in laps:
    name = (lap.get("driver") or {}).get("name") or (lap.get("driver") or {}).get("slug") or "Unknown"
    car = (lap.get("car") or {}).get("name") or "Unknown car"
    d = bucket.setdefault(name, {}).setdefault(car, {"clean": [], "total": 0})
    d["total"] += 1
    if lap.get("clean") and lap.get("lapTime"):
        d["clean"].append(lap["lapTime"])

roster = []
for name, cars in sorted(bucket.items()):
    car_stats = {}
    for car, v in cars.items():
        median = round(statistics.median(v["clean"]), 3) if v["clean"] else None
        car_stats[car] = {"laps": v["total"], "medianLap": median,
                          "cleanPct": round(len(v["clean"]) / v["total"], 3) if v["total"] else 0}
    roster.append({"name": name, "cars": car_stats})

# 4. print it for copy-paste into the builder
print("===== COPY EVERYTHING BELOW THIS LINE =====")
print(json.dumps(roster, indent=2))
print("===== COPY EVERYTHING ABOVE THIS LINE =====")
