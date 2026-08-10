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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AuthorizationDiscoveryFailedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationDiscoveryFailedExceptionTest extends AbstractMcpTestCase
{
    public function testMessageListsEveryProbedUrl(): void
    {
        self::assertSame(
            'No protected resource metadata was served for "https://mcp.example.com". Probed: https://a.example, https://b.example.',
            (new AuthorizationDiscoveryFailedException(
                'protected resource metadata',
                'https://mcp.example.com',
                ['https://a.example', 'https://b.example'],
            ))->getMessage(),
        );
    }

    public function testBoundsAndEscapesAHostileSubjectAndProbeList(): void
    {
        $subject = str_repeat('s', 253).'...';
        $probed = str_repeat('p', 253).'...';

        self::assertSame(
            \sprintf('No protected resource metadata was served for "%s". Probed: %s.', $subject, $probed),
            (new AuthorizationDiscoveryFailedException(
                'protected resource metadata',
                str_repeat('s', 300)."\x1b",
                [str_repeat('p', 300)."\x07"],
            ))->getMessage(),
        );
    }

    public function testEachProbedUrlIsBoundedSeparatelySoTheLastOneSurvives(): void
    {
        $probed = [str_repeat('a', 300), str_repeat('b', 300), 'https://c.example'];

        self::assertSame(
            \sprintf(
                'No protected resource metadata was served for "s". Probed: %s..., %s..., https://c.example.',
                str_repeat('a', 253),
                str_repeat('b', 253),
            ),
            (new AuthorizationDiscoveryFailedException('protected resource metadata', 's', $probed))->getMessage(),
        );
    }
}
