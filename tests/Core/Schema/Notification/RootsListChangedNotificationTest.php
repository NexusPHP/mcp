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
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\RootsListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RootsListChangedNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RootsListChangedNotificationTest extends TestCase
{
    public function testDefaultsToBaseNotificationParamsWithNullMeta(): void
    {
        $notification = new RootsListChangedNotification();

        self::assertSame(EmptyNotificationParams::class, $notification->params::class);
        self::assertNull($notification->params->meta);
    }

    public function testToArrayOmitsParamsWhenEmpty(): void
    {
        $notification = new RootsListChangedNotification();

        self::assertSame(
            ['jsonrpc' => '2.0', 'method' => 'notifications/roots/list_changed'],
            $notification->toArray(),
        );
    }

    public function testToArrayIncludesParamsWithMeta(): void
    {
        $notification = new RootsListChangedNotification(new EmptyNotificationParams(new Meta(['vendor' => 'x'])));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/roots/list_changed',
                'params' => ['_meta' => ['vendor' => 'x']],
            ],
            $notification->toArray(),
        );
    }

    public function testToArrayPreservesKeyOrderStartingWithJsonRpc(): void
    {
        $notification = new RootsListChangedNotification(new EmptyNotificationParams(new Meta(['k' => 'v'])));

        self::assertSame(
            ['jsonrpc', 'method', 'params'],
            array_keys($notification->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new RootsListChangedNotification(new EmptyNotificationParams(new Meta(['k' => 'v'])));

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayParsesWithoutParams(): void
    {
        $notification = RootsListChangedNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/roots/list_changed',
        ]);

        self::assertSame(EmptyNotificationParams::class, $notification->params::class);
        self::assertNull($notification->params->meta);
    }

    public function testFromArrayParsesParamsMeta(): void
    {
        $notification = RootsListChangedNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/roots/list_changed',
            'params' => ['_meta' => ['vendor' => 'x']],
        ]);

        self::assertNotNull($notification->params->meta);
        self::assertSame(['vendor' => 'x'], $notification->params->meta->extras);
    }

    public function testFromArrayRejectsNonObjectParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RootsListChangedNotification wire "params" must be an object, string given.');

        RootsListChangedNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/roots/list_changed',
            'params' => 'bad',
        ]);
    }
}
