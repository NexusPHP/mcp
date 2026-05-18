#!/usr/bin/env bash
# Flags semicolons used as prose connectors in PHPDoc / line comments and in
# string-literal prose (exception messages, sprintf templates, log text).
# Comment-line detection is high confidence. String-literal detection is a
# heuristic. Quoted text on a line of code may legitimately carry a `;` for
# non-prose reasons (regex, SQL, CSS, shell, env-var lists). Review each
# flag, replace with a period if it really is prose, ignore otherwise.

set -uo pipefail

input=$(cat)
new=$(printf '%s' "$input" | jq -r '.tool_input.new_string // .tool_input.content // empty')
file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')

[ -z "$new" ] && exit 0

case "$file" in
    *.claude/*) exit 0 ;;
esac

# Comment-line prose: `; ` + lowercase letter on a PHPDoc continuation line
# (`^ * `), opening line (`/** `), or `// ` comment.
comment_violations=$(printf '%s\n' "$new" | grep -nE '^[[:space:]]*(/\*\*|\*|//)[[:space:]].*;[[:space:]]+[a-z]' || true)

# String-literal prose: `; ` + lowercase letter inside a quoted run on the
# same line. Heuristic. The opening quote anchors the match to text inside a
# string instead of bare code.
string_violations=$(printf '%s\n' "$new" | grep -nE "['\"][^'\"]*;[[:space:]]+[a-z]" || true)

if [ -n "$comment_violations" ] || [ -n "$string_violations" ]; then
    printf 'authored-text check: possible prose semicolon. Please review. Replace with a period if the text is prose. Leave it alone if the semicolon is intentional (regex, SQL, CSS, shell, etc.).\n' >&2
    [ -n "$comment_violations" ] && printf '%s\n' "$comment_violations" >&2
    [ -n "$string_violations" ] && printf '%s\n' "$string_violations" >&2
    exit 2
fi
exit 0
