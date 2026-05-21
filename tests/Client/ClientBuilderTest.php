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

namespace Nexus\Mcp\Tests\Client;

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientBuilder::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientBuilderTest extends TestCase
{
    public function testBuildWithoutClientInfoThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Client info must be set before build() via setClientInfo().');

        new ClientBuilder()->build();
    }

    public function testSetClientInfoIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setClientInfo('demo', '1.0.0');

        self::assertSame($builder, $returned);
    }

    public function testSetLoggerIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->setLogger(new ArrayLogger());

        self::assertSame($builder, $returned);
    }

    public function testAddRequestHandlerIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->addRequestHandler('vendor/custom', new ClosureRequestHandler(static fn() => throw new \RuntimeException('not used')));

        self::assertSame($builder, $returned);
    }

    public function testAddNotificationHandlerIsFluent(): void
    {
        $builder = new ClientBuilder();

        $returned = $builder->addNotificationHandler(
            'notifications/cancelled',
            new ClosureNotificationHandler(static fn() => null),
        );

        self::assertSame($builder, $returned);
    }
}
