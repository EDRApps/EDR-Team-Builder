#!/usr/bin/env bash
# Live public, read-only GETs from the Team Builder site into tests/smoke/fixtures/ (gitignored:
# the repo is public, so team data is fetched at test time, never committed).
set -u
d="$(cd "$(dirname "$0")" && pwd)/fixtures"; mkdir -p "$d"
api=https://endurotechracing.com/wp-json/edr/v1
for n in plan avail roster; do curl -fsS --max-time 20 "$api/$n" -o "$d/$n.json" || rm -f "$d/$n.json"; done
curl -fsS --max-time 20 "$api/pace?tracks=52,59,65,444,445,446" -o "$d/pace.json" || rm -f "$d/pace.json"
ls "$d"
