#!/usr/bin/env bash
# Flags semicolons used as prose connectors in PHPDoc / line comments.
# Prefer a period (separate sentences), a colon (introducing a definition),
# or a comma where natural.

set -uo pipefail

input=$(cat)
new=$(printf '%s' "$input" | jq -r '.tool_input.new_string // .tool_input.content // empty')
file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')

[ -z "$new" ] && exit 0

case "$file" in
    *.claude/*) exit 0 ;;
esac

# Match `; ` followed by a lowercase letter on PHPDoc continuation lines
# (`^ * `), opening lines (`/** `), or line comments (`// `).
violations=$(printf '%s\n' "$new" | grep -nE '^[[:space:]]*(/\*\*|\*|//)[[:space:]].*;[[:space:]]+[a-z]' || true)
if [ -n "$violations" ]; then
    printf 'authored-text check: prose semicolon detected. Consider a period instead.\n%s\n' "$violations" >&2
    exit 2
fi
exit 0
