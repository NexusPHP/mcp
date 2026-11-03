<?php

declare(strict_types=1);

/**
 * This file is part of the Nexus MCP SDK package.
 *
 * (c) 2026 John Paul E. Balandan, CPA <paulbalandan@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

/**
 * The client driven by the conformance referee, best run through `./conformance/run-client.sh`,
 * which sets up its contract:
 *
 * - the server URL arrives as the last argv entry
 * - `MCP_CONFORMANCE_SCENARIO` names the scenario, and is the routing key
 * - `MCP_CONFORMANCE_CONTEXT` carries scenario JSON when there is any
 * - `MCP_CONFORMANCE_PROTOCOL_VERSION` carries the resolved spec version
 * - exit 0 passes, non-zero fails, except where a scenario expects the failure
 */

require __DIR__.'/bootstrap.php';

$conformance = new ConformanceClient(new PsrLogger(), ScenarioContext::fromEnvironment());

$arguments = conformanceArguments();
$scenario = getenv('MCP_CONFORMANCE_SCENARIO');
$serverUrl = count($arguments) > 1 ? $arguments[count($arguments) - 1] : '';

if (! is_string($scenario) || '' === $scenario || '' === $serverUrl) {
    fwrite(\STDERR, "Usage: MCP_CONFORMANCE_SCENARIO=<scenario> php conformance/client.php <server-url>\n");
    fwrite(\STDERR, "The conformance runner sets that variable and appends the URL.\n\nRegistered scenarios:\n");
    fwrite(\STDERR, $conformance->describeScenarios());

    exit(1);
}

exit($conformance->run($scenario, $serverUrl));
