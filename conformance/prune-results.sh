#!/usr/bin/env bash
#
# Keeps only the newest result directory per scenario under conformance/results/,
# so repeated runs supersede their predecessors instead of accumulating one
# directory per invocation. The runners call this after every referee run.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESULTS_DIR="${1:-$SCRIPT_DIR/results}"

[ -d "$RESULTS_DIR" ] || exit 0

for mode_dir in "$RESULTS_DIR"/*/; do
    [ -d "$mode_dir" ] || continue

    # The referee names each directory `<scenario>-<ISO-8601 timestamp>`,
    # nesting namespaced scenarios (`auth/...`) one level deeper, so a
    # lexicographic sort puts the newest run last within each scenario group,
    # and every line with a same-scenario successor is stale.
    find "$mode_dir" -mindepth 1 -maxdepth 2 -type d \
        | sort \
        | awk '{
            full = $0
            name = $0
            sub(/-[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9TZ.-]+$/, "", name)
            # A directory without the timestamp suffix is a namespace, not a run.
            if (name == full) next
            if (name == prev) print prevfull
            prev = name
            prevfull = full
          }' \
        | while IFS= read -r stale; do
            rm -rf "$stale"
        done
done
