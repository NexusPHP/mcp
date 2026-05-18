#!/usr/bin/env bash
# Flags newly-authored text containing em dashes (U+2014).
# Preference: use semicolon, period, colon, or parens instead.

set -uo pipefail

input=$(cat)
new=$(printf '%s' "$input" | jq -r '.tool_input.new_string // .tool_input.content // empty')
file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')

[ -z "$new" ] && exit 0

case "$file" in
    *.claude/*) exit 0 ;;
esac

violations=$(printf '%s\n' "$new" | grep -n '—' || true)
if [ -n "$violations" ]; then
    printf 'authored-text check: em dash detected; use semicolon, period, or colon:\n%s\n' "$violations" >&2
    exit 2
fi
exit 0
