#!/usr/bin/env bash
#
# Runs the MCP conformance suite in client mode against conformance/client.php.
#
# The referee is the server here: it stands up a mock per scenario and spawns the
# client once per scenario. Nothing needs starting first. Every argument is
# passed through, so a single scenario is:
#
#     ./conformance/run-client.sh --scenario tools_call --verbose
#
# Results land in conformance/results/ (gitignored). Score them with
# `composer conformance:score`.

set -euo pipefail

CONFORMANCE_VERSION="${CONFORMANCE_VERSION:-0.2.0-alpha.10}"
SPEC_VERSION="2026-07-28"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$REPO_ROOT"

echo "Running the conformance suite in client mode (referee conformance@${CONFORMANCE_VERSION}, spec ${SPEC_VERSION})..."
REFEREE_STATUS=0
npx -y -q "@modelcontextprotocol/conformance@${CONFORMANCE_VERSION}" client \
    --command "php conformance/client.php" \
    --spec-version "$SPEC_VERSION" \
    --expected-failures ./conformance/expected-failures.yaml \
    --output-dir ./conformance/results/client \
    "$@" || REFEREE_STATUS=$?

./conformance/prune-results.sh

exit "$REFEREE_STATUS"
