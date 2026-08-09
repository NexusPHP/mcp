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

final class ProductionPosture
{
    /**
     * @param non-empty-string $envPrefix
     */
    public static function force(string $envPrefix): void
    {
        $handler = new XdebugHandler($envPrefix);
        $handler->check();

        if (ini_get('zend.assertions') !== '-1') {
            ini_set('zend.assertions', '0');
        }
    }
}
