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
use Nexus\Mcp\Core\Schema\MetaObject\NotificationMetaObject;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ToolListChangedNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ToolListChangedNotificationTest extends AbstractMcpTestCase
{
    public function testDefaultsToBaseNotificationParamsWithNullMeta(): void
    {
        $notification = new ToolListChangedNotification();

        self::assertSame(EmptyNotificationParams::class, $notification->params::class);
        self::assertSame([], $notification->params->meta->toArray());
    }

    public function testToArrayOmitsParamsWhenEmpty(): void
    {
        $notification = new ToolListChangedNotification();

        self::assertSame(
            ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed'],
            $notification->toArray(),
        );
    }

    public function testToArrayIncludesParamsWithMeta(): void
    {
        $notification = new ToolListChangedNotification(params: new EmptyNotificationParams(meta: new NotificationMetaObject(extras: ['vendor' => 'x'])));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/tools/list_changed',
                'params' => ['_meta' => ['vendor' => 'x']],
            ],
            $notification->toArray(),
        );
    }

    public function testToArrayPreservesKeyOrderStartingWithJsonRpc(): void
    {
        $notification = new ToolListChangedNotification(params: new EmptyNotificationParams(meta: new NotificationMetaObject(extras: ['k' => 'v'])));

        self::assertSame(
            ['jsonrpc', 'method', 'params'],
            array_keys($notification->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new ToolListChangedNotification(params: new EmptyNotificationParams(meta: new NotificationMetaObject(extras: ['k' => 'v'])));

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayParsesWithoutParams(): void
    {
        $notification = ToolListChangedNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ]);

        self::assertSame(EmptyNotificationParams::class, $notification->params::class);
        self::assertSame([], $notification->params->meta->toArray());
    }

    public function testFromArrayParsesParamsMeta(): void
    {
        $notification = ToolListChangedNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
            'params' => ['_meta' => ['vendor' => 'x']],
        ]);
        self::assertSame(['vendor' => 'x'], $notification->params->meta->extras);
    }

    public function testFromArrayRejectsNonObjectParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params" must be an object, string given.');

        ToolListChangedNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
            'params' => 'bad',
        ]);
    }
}
