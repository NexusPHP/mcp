#!/usr/bin/env bash
# Flags PHPDoc `@throws SomeException <descriptive prose>` patterns. Project
# preference: `@throws SomeException` only. Rationale and conditions belong in
# the method docblock summary or are inferable from the type itself.

set -uo pipefail

input=$(cat)
new=$(printf '%s' "$input" | jq -r '.tool_input.new_string // .tool_input.content // empty')
file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')

[ -z "$new" ] && exit 0

case "$file" in
    *.claude/*) exit 0 ;;
    *.md) exit 0 ;;
esac

# Match a `@throws` tag followed by whitespace, an exception name (FQCN with
# optional leading backslash, dotted segments, or just a class identifier),
# more whitespace, and then at least one non-whitespace character of trailing
# text. The exception name token is `[\\\\A-Za-z_][\\\\A-Za-z0-9_]*` repeated
# with optional namespace separators.
violations=$(printf '%s\n' "$new" | grep -nE '@throws[[:space:]]+\\?[A-Za-z_][A-Za-z0-9_\\]*[[:space:]]+\S' || true)

if [ -n "$violations" ]; then
    printf 'authored-text check: @throws should not carry trailing prose. Drop the description; the exception class name suffices.\n%s\n' "$violations" >&2
    exit 2
fi
exit 0
