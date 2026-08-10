#!/usr/bin/env bash
# Flags newly-authored text containing "wire" as a standalone word.
# Project preference: use "message" or "envelope" terminology instead.

set -uo pipefail

input=$(cat)
new=$(printf '%s' "$input" | jq -r '.tool_input.new_string // .tool_input.content // empty')
file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')

[ -z "$new" ] && exit 0

case "$file" in
    *.claude/*) exit 0 ;;
esac

# The conformance referee's own check ids and scenario names have to match byte for byte,
# so drop them before the check rather than exempting the files that cite them.
scrubbed=$(printf '%s\n' "$new" | sed -E 's/(wire-schema-(valid|harness-error)|tasks-wire-fields)//g')

violations=$(printf '%s\n' "$scrubbed" | grep -nEi '\bwir(e|es|ed|ing)\b' || true)
if [ -n "$violations" ]; then
    printf 'authored-text check: avoid "wire" in source/comments; prefer "message" or "envelope":\n%s\n' "$violations" >&2
    exit 2
fi
exit 0
