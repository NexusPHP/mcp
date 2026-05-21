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

// Test fixture for StdioClientTransportTest. Echoes each stdin line back on stdout.

fwrite(\STDERR, "echo-server fixture ready\n");

while (true) {
    $line = fgets(\STDIN);

    if (false === $line) {
        break;
    }

    fwrite(\STDOUT, $line);
    fflush(\STDOUT);
}
