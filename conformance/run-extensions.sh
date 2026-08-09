#!/usr/bin/env bash
#
# Runs every extension-tagged scenario, server-mode against conformance/server.php
# and client-mode against conformance/client.php.
#
# The referee keeps `[extension]` scenarios out of all of its suites and marks
# them inapplicable at the pinned spec version, so each runs targeted with
# `--force`. An optional first argument narrows the run to one mode, and extra
# arguments pass through to every run:
#
#     ./conformance/run-extensions.sh                 # both modes
#     ./conformance/run-extensions.sh server
#     ./conformance/run-extensions.sh client --verbose

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

MODE="both"
case "${1:-}" in
    server|client)
        MODE="$1"
        shift
        ;;
    ''|-*) ;;
    *)
        echo "Unknown mode \"$1\". Expected \"server\", \"client\", or no mode at all." >&2
        exit 1
        ;;
esac

SERVER_SCENARIOS=(
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

CLIENT_SCENARIOS=(
    auth/client-credentials-basic
    auth/client-credentials-jwt
    auth/enterprise-managed-authorization
)

if [[ "$MODE" != "client" ]]; then
    for scenario in "${SERVER_SCENARIOS[@]}"; do
        "$SCRIPT_DIR/run-server.sh" --scenario "$scenario" --force "$@"
    done
fi

if [[ "$MODE" != "server" ]]; then
    for scenario in "${CLIENT_SCENARIOS[@]}"; do
        "$SCRIPT_DIR/run-client.sh" --scenario "$scenario" --force "$@"
    done
fi
