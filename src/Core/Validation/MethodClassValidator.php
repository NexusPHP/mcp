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

namespace Nexus\Mcp\Core\Validation;

use Nexus\Assert\Assert;
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;

/**
 * Enforces that an envelope class declares the method it is registered for.
 *
 * @internal
 */
final class MethodClassValidator
{
    /**
     * @param class-string<JsonRpcNotification<non-empty-string>>|class-string<JsonRpcRequest<non-empty-string>> $class
     * @param non-empty-string                                                                                   $method
     *
     * @throws ExpectationFailedException
     */
    public static function validate(string $class, string $method, bool $isNotification = false): void
    {
        Assert::that($class::getMethod())->isIdentical($method, \sprintf(
            '%s class "%s" must declare the method "%s" it is registered for, {value} declared.',
            $isNotification ? 'Notification' : 'Request',
            $class,
            $method,
        ));
    }
}
