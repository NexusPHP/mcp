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

namespace Nexus\Mcp\Tests\Core\Schema\Notification;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InitializedNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InitializedNotificationTest extends TestCase
{
    public function testDefaultsToBaseNotificationParamsWithNullMeta(): void
    {
        $notification = new InitializedNotification();

        self::assertSame(EmptyNotificationParams::class, $notification->params::class);
        self::assertSame([], $notification->params->meta->toArray());
    }

    public function testToArrayOmitsParamsWhenEmpty(): void
    {
        $notification = new InitializedNotification();

        self::assertSame(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $notification->toArray(),
        );
    }

    public function testToArrayIncludesParamsWithMeta(): void
    {
        $notification = new InitializedNotification(new EmptyNotificationParams(new MetaObject(['vendor' => 'x'])));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
                'params' => ['_meta' => ['vendor' => 'x']],
            ],
            $notification->toArray(),
        );
    }

    public function testToArrayPreservesKeyOrderStartingWithJsonRpc(): void
    {
        $notification = new InitializedNotification(new EmptyNotificationParams(new MetaObject(['k' => 'v'])));

        self::assertSame(
            ['jsonrpc', 'method', 'params'],
            array_keys($notification->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new InitializedNotification(new EmptyNotificationParams(new MetaObject(['k' => 'v'])));

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayParsesWithoutParams(): void
    {
        $notification = InitializedNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        self::assertSame(EmptyNotificationParams::class, $notification->params::class);
        self::assertSame([], $notification->params->meta->toArray());
    }

    public function testFromArrayParsesParamsMeta(): void
    {
        $notification = InitializedNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => ['_meta' => ['vendor' => 'x']],
        ]);
        self::assertSame(['vendor' => 'x'], $notification->params->meta->extras);
    }

    public function testFromArrayRejectsNonObjectParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('InitializedNotification "params" must be an object, string given.');

        InitializedNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => 'bad',
        ]);
    }
}
