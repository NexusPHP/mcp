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

// Test fixture for StdioClientTransportTest. Exits on its own with the code given as argv[1],
// without waiting for stdin, so the client observes an exit it did not request.

exit((int) ($argv[1] ?? 0));
