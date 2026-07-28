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

/*
 * Shared bootstrap for the conformance harness. The examples' own bootstrap
 * already registers the autoloader, `ExampleLogger`, and an uncaught-exception
 * handler, and `PsrHttpAdapter` is the same PSR-15 to `amphp/http-server`
 * bridge the harness needs, so both are reused rather than duplicated.
 */

require __DIR__.'/../examples/bootstrap.php';
require __DIR__.'/../examples/PsrHttpAdapter.php';

/**
 * The command-line arguments, read from `$_SERVER` rather than `$argv` because
 * the latter exists only when `register_argc_argv` is on.
 *
 * @return list<string>
 */
function conformanceArguments(): array
{
    $arguments = $_SERVER['argv'] ?? [];

    if (! is_array($arguments)) {
        return [];
    }

    $strings = [];

    foreach ($arguments as $argument) {
        if (is_string($argument)) {
            $strings[] = $argument;
        }
    }

    return $strings;
}
