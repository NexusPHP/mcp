---
name: release-notes
description: Draft release notes from a list of merged changes. Use when the user asks for a changelog entry or a release summary.
license: MIT
metadata:
  version: "1.2.0"
---

# Release notes

Turn a list of merged changes into release notes a user of the project can read.

1. Read `references/style.md` before writing anything.
2. Group the changes under Added, Changed, and Fixed, dropping anything a user cannot observe.
3. Write each entry from `templates/entry.md`, one line per change.
