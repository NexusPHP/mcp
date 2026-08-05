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

use Composer\XdebugHandler\XdebugHandler;

/**
 * Forces the production posture on a long-running script: xdebug dropped and
 * `assert()` not executing. Setting `{$envPrefix}_ALLOW_XDEBUG=1` skips the
 * restart for step-debugging.
 */
final class ProductionPosture
{
    /**
     * @param non-empty-string $envPrefix
     */
    public static function force(string $envPrefix): void
    {
        // xdebug's mode is fixed at process start, so forcing it off takes one
        // restart. The handler re-runs the script with the extension dropped
        // from the loaded ini, leaving the restarted process as a child.
        $handler = new XdebugHandler($envPrefix);
        $handler->check();

        // `-1` is only reachable at startup, so runtime lowering stops at
        // "compiled but not executed".
        if (ini_get('zend.assertions') !== '-1') {
            ini_set('zend.assertions', '0');
        }
    }
}
