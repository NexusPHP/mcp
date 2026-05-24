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

// Test fixture for StdioClientTransportTest. Reports its own environment as a
// JSON-RPC notification, then idles until stdin closes.

$report = json_encode([
    'jsonrpc' => '2.0',
    'method' => 'env/report',
    'params' => ['env' => getenv()],
], \JSON_THROW_ON_ERROR);

fwrite(\STDOUT, $report."\n");
fflush(\STDOUT);

while (true) {
    $line = fgets(\STDIN);

    if (false === $line) {
        break;
    }
}
