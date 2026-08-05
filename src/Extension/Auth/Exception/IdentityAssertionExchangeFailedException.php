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

namespace Nexus\Mcp\Extension\Auth\Exception;

use Nexus\Mcp\Core\Exception\McpExceptionInterface;

/**
 * Thrown when the enterprise IdP refuses, or answers off-contract, the RFC 8693 token exchange that was to
 * produce the ID-JAG.
 */
final class IdentityAssertionExchangeFailedException extends \RuntimeException implements McpExceptionInterface
{
}
