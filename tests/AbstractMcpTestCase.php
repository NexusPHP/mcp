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

namespace Nexus\Mcp\Tests;

use PHPUnit\Framework\TestCase;

if (method_exists(TestCase::class, 'expectExceptionMessageIs')) {
    /**
     * Base class every test extends, so the suite runs on both PHPUnit majors the
     * supported PHP range resolves to.
     *
     * @internal
     */
    abstract class AbstractMcpTestCase extends TestCase
    {
    }
} else {
    /**
     * Base class every test extends, so the suite runs on both PHPUnit majors the
     * supported PHP range resolves to.
     *
     * @internal
     */
    abstract class AbstractMcpTestCase extends TestCase
    {
        protected function expectExceptionMessageIs(string $message): void
        {
            $this->expectExceptionMessageMatches('/^'.preg_quote($message, '/').'$/D');
        }
    }
}
