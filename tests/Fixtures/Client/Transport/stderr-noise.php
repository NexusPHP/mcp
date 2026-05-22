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

// Test fixture for StdioClientTransportTest. Emits non-printable bytes on stderr,
// then blocks on stdin so the transport can capture the line before close.

fwrite(\STDERR, "noise\x07\x1b[31m\n");

fgets(\STDIN);
