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
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CancelledNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CancelledNotificationTest extends AbstractMcpTestCase
{
    public function testMethodIsNotificationsCancelled(): void
    {
        self::assertSame('notifications/cancelled', CancelledNotification::getMethod());
    }

    public function testToArrayWithMinimalParams(): void
    {
        $notification = new CancelledNotification(params: new CancelledNotificationParams(requestId: new RequestId(id: 7)));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => 7],
            ],
            $notification->toArray(),
        );
    }

    public function testToArrayWithReasonAndMeta(): void
    {
        $notification = new CancelledNotification(params: new CancelledNotificationParams(
            requestId: new RequestId(id: 'req-1'),
            reason: 'user aborted',
            meta: new NotificationMetaObject(extras: ['vendor' => 'x']),
        ));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
                'params' => [
                    '_meta' => ['vendor' => 'x'],
                    'requestId' => 'req-1',
                    'reason' => 'user aborted',
                ],
            ],
            $notification->toArray(),
        );
    }

    public function testToArrayPreservesKeyOrderStartingWithJsonRpc(): void
    {
        $notification = new CancelledNotification(params: new CancelledNotificationParams(requestId: new RequestId(id: 1)));

        self::assertSame(
            ['jsonrpc', 'method', 'params'],
            array_keys($notification->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new CancelledNotification(params: new CancelledNotificationParams(
            requestId: new RequestId(id: 1),
            reason: 'because',
            meta: new NotificationMetaObject(extras: ['k' => 'v']),
        ));

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayFullRoundTripWithMinimalParams(): void
    {
        $original = new CancelledNotification(params: new CancelledNotificationParams(requestId: new RequestId(id: 9)));

        $rebuilt = CancelledNotification::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayFullRoundTripWithAllFields(): void
    {
        $original = new CancelledNotification(params: new CancelledNotificationParams(
            requestId: new RequestId(id: 'req-3'),
            reason: 'timeout',
            meta: new NotificationMetaObject(extras: ['vendor' => 'x']),
        ));

        $rebuilt = CancelledNotification::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^missing the required "params" key\.$/');

        CancelledNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
        ]);
    }

    public function testFromArrayRejectsNonObjectParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params" must be an object, string given.');

        CancelledNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => 'bad',
        ]);
    }

    public function testFromArrayRejectsListKeyedParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params" must be a string-keyed object.');

        CancelledNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['a', 'b'],
        ]);
    }
}
