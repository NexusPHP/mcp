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

CONFORMANCE_VERSION="${CONFORMANCE_VERSION:-0.2.0-alpha.10}"
SPEC_VERSION="2026-07-28"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-3000}"
URL_HOST="${URL_HOST:-$HOST}"
SERVER_URL="http://${URL_HOST}:${PORT}/mcp"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$REPO_ROOT"

# Refuse to start when the port is already taken.
if (: > "/dev/tcp/${HOST}/${PORT}") 2>/dev/null; then
    echo "Error: port ${PORT} is already in use." >&2
    echo "Stop the stale process (lsof -ti:${PORT} -sTCP:LISTEN | xargs kill) or set PORT to a free port." >&2
    exit 1
fi

echo "Starting conformance fixture on ${HOST}:${PORT}..."
set -m
HOST="$HOST" PORT="$PORT" php conformance/server.php &
SERVER_PID=$!
set +m

cleanup() {
    kill -- "-$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT

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

echo "Running the conformance suite (referee conformance@${CONFORMANCE_VERSION}, spec ${SPEC_VERSION})..."

REFEREE_STATUS=0
npx -y -q "@modelcontextprotocol/conformance@${CONFORMANCE_VERSION}" server \
    --url "$SERVER_URL" \
    --spec-version "$SPEC_VERSION" \
    --expected-failures ./conformance/expected-failures.yaml \
    --output-dir ./conformance/results/server \
    "$@" || REFEREE_STATUS=$?

./conformance/prune-results.sh

exit "$REFEREE_STATUS"
