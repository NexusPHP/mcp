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

namespace Nexus\Mcp\Core\Transport;

use Nexus\Mcp\Core\Schema\ProtocolVersion;

/**
 * Implemented by transports that need the negotiated MCP protocol version after the initialize handshake.
 */
interface ProtocolVersionAwareInterface
{
    public function setProtocolVersion(ProtocolVersion $version): void;

    /**
     * @param list<ProtocolVersion> $versions
     */
    public function setSupportedProtocolVersions(array $versions): void;
}
