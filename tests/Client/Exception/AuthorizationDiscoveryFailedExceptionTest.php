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

use Nexus\Mcp\Client\Exception\AuthorizationDiscoveryFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuthorizationDiscoveryFailedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationDiscoveryFailedExceptionTest extends TestCase
{
    public function testMessageListsEveryProbedUrl(): void
    {
        self::assertSame(
            'No protected resource metadata was served for "https://mcp.example.com". Probed: https://a.example, https://b.example.',
            new AuthorizationDiscoveryFailedException(
                'protected resource metadata',
                'https://mcp.example.com',
                ['https://a.example', 'https://b.example'],
            )->getMessage(),
        );
    }
}
