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
 * Thrown when an extension method collides with a method another party already owns.
 */
final class ExtensionMethodCollisionException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param non-empty-string $claimant Describes who is claiming the method (e.g. `Extension "acme/other"`,
     *                                   `A builder-registered handler`)
     * @param non-empty-string $owner    Describes the current owner (e.g. `the MCP specification`,
     *                                   `extension "acme/other"`)
     */
    public function __construct(string $claimant, string $method, string $owner, bool $isNotification = false)
    {
        parent::__construct(\sprintf(
            '%s cannot claim the %s method "%s" already owned by %s.',
            $claimant,
            $isNotification ? 'notification' : 'request',
            $method,
            $owner,
        ));
    }
}
