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

namespace Nexus\Mcp\Extension\Skills\Client\Exception;

use Nexus\Mcp\Core\Exception\McpExceptionInterface;
use Nexus\Mcp\Core\SafeDisplay;

/**
 * Thrown when a skill file served by a server does not match its entry.
 */
final class SkillVerificationFailedException extends \RuntimeException implements McpExceptionInterface
{
    /**
     * @param non-empty-string $reason What the file did, as a clause that follows "it"
     */
    public function __construct(string $uri, string $reason)
    {
        parent::__construct(\sprintf('Skill file "%s" failed verification: it %s.', SafeDisplay::sanitiseCause($uri), $reason));
    }
}
