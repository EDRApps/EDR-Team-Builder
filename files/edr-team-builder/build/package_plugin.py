#!/usr/bin/env python3
"""Build the installable WordPress zip.

Rebuilds assets/builder.js + builder.css from the HTML first, then packages the plugin
directory the same way .github/workflows/ci.yml does, so a zip built here and a zip built
in CI hold the same files. The build tooling itself is left out of the zip.

    python3 files/edr-team-builder/build/package_plugin.py

Output defaults to "Admin Folder/edr-team-builder-V2.zip" — the copy the website admin
uploads, named to match the filename in Admin Folder/HANDOFF.md. Override with EDR_ZIP.
"""
import os
import shutil
import subprocess
import sys
import zipfile

_HERE = os.path.dirname(os.path.abspath(__file__))
PLUGIN = os.path.normpath(os.path.join(_HERE, ".."))                 # files/edr-team-builder
REPO = os.path.normpath(os.path.join(PLUGIN, "..", ".."))            # repo root
OUT = os.environ.get("EDR_ZIP", os.path.join(REPO, "Admin Folder", "edr-team-builder-V2.zip"))

# build/ is the assembler and this script; neither belongs in a plugin install
EXCLUDE_DIRS = {"build", "__pycache__"}
EXCLUDE_FILES = {".DS_Store"}

# Text going into the zip is normalised to LF, which is what the repo stores and what a zip
# built on Linux contains. Without this the artifact depends on the packager's git settings:
# a Windows clone with core.autocrlf=true has a CRLF working tree, so the zip picks up CRLF
# while CI's Linux checkout does not — and CI's version check then compares "2.4.37" against
# "2.4.37\r" and fails with the two looking identical in the log. Binary stays untouched.
TEXT_EXT = {".php", ".js", ".css", ".md", ".txt", ".json", ".html", ".po", ".pot"}


def plugin_version():
    """Read the version out of the plugin header — the single source of truth for it."""
    with open(os.path.join(PLUGIN, "edr-team-builder.php"), encoding="utf-8") as fh:
        for line in fh:
            if line.strip().startswith("* Version:"):
                return line.split(":", 1)[1].strip()
    raise SystemExit("could not read Version from edr-team-builder.php")


def main():
    # never package stale assets: the HTML is the source of truth for the front end
    subprocess.run([sys.executable, os.path.join(_HERE, "assemble_builder.py")], check=True)

    ver = plugin_version()
    out_dir = os.path.dirname(OUT)
    if out_dir:
        os.makedirs(out_dir, exist_ok=True)

    count = 0
    with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as z:
        for root, dirs, files in os.walk(PLUGIN):
            dirs[:] = sorted(d for d in dirs if d not in EXCLUDE_DIRS)
            for name in sorted(files):
                if name in EXCLUDE_FILES:
                    continue
                full = os.path.join(root, name)
                # entries are prefixed with edr-team-builder/ so WordPress unpacks a plugin folder
                rel = os.path.join("edr-team-builder", os.path.relpath(full, PLUGIN))
                arc = rel.replace(os.sep, "/")
                if os.path.splitext(name)[1].lower() in TEXT_EXT:
                    with open(full, "rb") as fh:
                        data = fh.read()
                    data = data.replace(b"\r\n", b"\n").replace(b"\r", b"\n")
                    # keep the on-disk mtime so the entry does not churn between builds
                    info = zipfile.ZipInfo.from_file(full, arc)
                    info.compress_type = zipfile.ZIP_DEFLATED
                    z.writestr(info, data)
                else:
                    z.write(full, arc)
                count += 1

    print("wrote %s (v%s, %d files, %d b)" % (OUT, ver, count, os.path.getsize(OUT)))

    # The site admin reads the install note beside the zip, but it also ships inside the zip.
    # Copy the plugin's copy over the one next to the zip so the two cannot drift: the plugin
    # directory is the source of truth, this one is a convenience copy.
    note = os.path.join(PLUGIN, "HANDOFF.md")
    beside = os.path.join(os.path.dirname(OUT), "HANDOFF.md") if out_dir else ""
    if beside and os.path.abspath(beside) != os.path.abspath(note) and os.path.exists(note):
        shutil.copyfile(note, beside)
        print("synced %s" % beside)


if __name__ == "__main__":
    main()
