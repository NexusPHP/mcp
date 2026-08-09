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

require __DIR__.'/../examples/bootstrap.php';
require __DIR__.'/../examples/PsrHttpAdapter.php';

/**
 * `$argv` exists only when `register_argc_argv` is on, so the arguments come from `$_SERVER`.
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
