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

use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Transport\StdioServerTransport;

require __DIR__.'/../../../../vendor/autoload.php';

(new ServerBuilder())
    ->setServerInfo(name: 'stdio-fixture', version: '1.0.0')
    ->build()
    ->run(new StdioServerTransport())
;
