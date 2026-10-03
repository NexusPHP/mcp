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

use Nexus\Mcp\Client\Transport\StdioClientTransport;

use function Amp\delay;

require __DIR__.'/../../../../vendor/autoload.php';

$transport = new StdioClientTransport([\PHP_BINARY, __DIR__.'/echo-server.php']);
$transport->start();
delay(0.05);
