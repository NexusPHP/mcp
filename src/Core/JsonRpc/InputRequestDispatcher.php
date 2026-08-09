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

namespace Nexus\Mcp\Core\JsonRpc;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Request\InputRequest;

/**
 * Discriminator for `InputRequest` payloads, keyed on the `method` field.
 *
 * @internal
 */
final class InputRequestDispatcher
{
    /**
     * @param array<string, mixed> $request
     */
    public static function decode(array $request): InputRequest
    {
        Assert::that($request)->hasOffset('method', 'each "result.inputRequests" entry is missing the required "method" key.');

        return match ($request['method']) {
            ElicitRequest::getMethod() => ElicitRequest::fromArray($request),
            default => throw new \InvalidArgumentException(\sprintf(
                'each "result.inputRequests" entry must use a supported input-request method, %s given.',
                SafeDisplay::sanitise(var_export($request['method'], true)),
            )),
        };
    }
}
