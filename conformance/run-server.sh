#!/usr/bin/env bash
#
# Runs the MCP conformance suite in server mode against conformance/server.php.
#
# Boots the fixture, waits for it to answer, runs the referee, then tears the
# fixture down. Every argument is passed through to the referee, so a single
# scenario is:
#
#     ./conformance/run-server.sh --scenario tools-list --verbose
#
# Results land in conformance/results/ (gitignored). Score them with
# `composer conformance:score`.

set -euo pipefail

# The referee version, overridable by the weekly drift job.
#
# Bump it and reconcile expected-failures.yaml in the same change: a new release
# routinely adds scenarios and checks.
#
# npm's `latest` tag is the older 0.1.x line. The 2026-07-28 scenarios ship under `alpha`.
CONFORMANCE_VERSION="${CONFORMANCE_VERSION:-0.2.0-alpha.10}"

# The SDK targets MCP 2026-07-28 only. The referee filters scenarios by the
# `removedIn` field, so this is also what drops the 2025-era scenarios for
# features this SDK does not implement (initialize, logging, sampling,
# resources/subscribe) rather than failing them.
SPEC_VERSION="2026-07-28"

HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-3000}"
SERVER_URL="http://${HOST}:${PORT}/mcp"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$REPO_ROOT"

# Refuse to start when the port is already taken. The readiness probe below
# cannot tell our fixture from a stale one, so a leftover listener would mean
# silently scoring old code.
if (: > "/dev/tcp/${HOST}/${PORT}") 2>/dev/null; then
    echo "Error: port ${PORT} is already in use." >&2
    echo "Stop the stale process (lsof -ti:${PORT} -sTCP:LISTEN | xargs kill) or set PORT to a free port." >&2
    exit 1
fi

# Spawn PHP directly rather than through a wrapper, so SERVER_PID is the listener
# itself. Killing a wrapper leaves the real server running and squatting the port.
echo "Starting conformance fixture on ${HOST}:${PORT}..."
HOST="$HOST" PORT="$PORT" php conformance/server.php &
SERVER_PID=$!

cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT

# --max-time keeps a wedged listener from hanging the loop, and a dead child
# fails fast instead of burning the full retry budget.
echo "Waiting for the fixture to answer..."
for _ in $(seq 1 30); do
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        echo "Error: the fixture exited before it became ready." >&2
        exit 1
    fi

    if curl -s --max-time 2 -o /dev/null "$SERVER_URL"; then
        break
    fi

    sleep 0.5
done

if ! curl -s --max-time 2 -o /dev/null "$SERVER_URL"; then
    echo "Error: the fixture did not become ready in time." >&2
    exit 1
fi

echo "Running the conformance suite (referee ${CONFORMANCE_VERSION}, spec ${SPEC_VERSION})..."
# `-o` is what persists per-scenario checks.json files. Without it the referee
# only prints, and there is nothing for score.php to read.
npx -y "@modelcontextprotocol/conformance@${CONFORMANCE_VERSION}" server \
    --url "$SERVER_URL" \
    --spec-version "$SPEC_VERSION" \
    --expected-failures ./conformance/expected-failures.yaml \
    --output-dir ./conformance/results/server \
    "$@"
