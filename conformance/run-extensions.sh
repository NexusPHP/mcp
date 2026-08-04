#!/usr/bin/env bash
#
# Runs every extension-tagged scenario against conformance/server.php.
#
# The referee keeps `[extension]` scenarios out of all of its suites and marks
# them inapplicable at the pinned spec version, so each runs targeted with
# `--force`. Extra arguments pass through to every run:
#
#     ./conformance/run-extensions.sh --verbose

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

SCENARIOS=(
    tasks-capability-negotiation
    tasks-dispatch-and-envelope
    tasks-lifecycle
    tasks-mrtr-composition
    tasks-mrtr-input
    tasks-request-headers
    tasks-request-state-removal
    tasks-required-task-error
    tasks-status-notifications
    tasks-wire-fields
)

for scenario in "${SCENARIOS[@]}"; do
    "$SCRIPT_DIR/run-server.sh" --scenario "$scenario" --force "$@"
done
