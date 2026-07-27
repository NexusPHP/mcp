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

namespace Nexus\Mcp\Tests\Client\Exception;

use Nexus\Mcp\Client\Exception\MalformedAuthorizationResponseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MalformedAuthorizationResponseException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class MalformedAuthorizationResponseExceptionTest extends TestCase
{
    public function testItNamesTheEndpointThatAnswered(): void
    {
        self::assertSame(
            'The token endpoint answered with a payload that is not a JSON object.',
            new MalformedAuthorizationResponseException('token endpoint')->getMessage(),
        );
    }

    public function testItKeepsTheDecodingFailureAsItsCause(): void
    {
        $cause = new \JsonException('Syntax error');

        self::assertSame($cause, new MalformedAuthorizationResponseException('token endpoint', $cause)->getPrevious());
    }
}
