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

namespace Nexus\Mcp\Core\Exception;

/**
 * Thrown when the same extension identifier is declared more than once.
 */
final class DuplicateExtensionException extends \LogicException implements McpExceptionInterface
{
    public function __construct(string $identifier)
    {
        parent::__construct(\sprintf('Extension "%s" is declared more than once.', $identifier));
    }
}
