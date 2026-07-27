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

use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UntrustedAuthorizationMetadataException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class UntrustedAuthorizationMetadataExceptionTest extends TestCase
{
    public function testMessageAppendsTheReason(): void
    {
        self::assertSame(
            'The authorization metadata cannot be trusted because the issuer does not match.',
            new UntrustedAuthorizationMetadataException('the issuer does not match.')->getMessage(),
        );
    }
}
