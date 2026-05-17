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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CancelledNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CancelledNotificationTest extends TestCase
{
    public function testMethodIsNotificationsCancelled(): void
    {
        self::assertSame('notifications/cancelled', CancelledNotification::method());
    }

    public function testToArrayWithMinimalParams(): void
    {
        $notification = new CancelledNotification(new CancelledNotificationParams(new RequestId(7)));

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
        $notification = new CancelledNotification(new CancelledNotificationParams(
            new RequestId('req-1'),
            'user aborted',
            new MetaObject(['vendor' => 'x']),
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
        $notification = new CancelledNotification(new CancelledNotificationParams(new RequestId(1)));

        self::assertSame(
            ['jsonrpc', 'method', 'params'],
            array_keys($notification->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new CancelledNotification(new CancelledNotificationParams(
            new RequestId(1),
            'because',
            new MetaObject(['k' => 'v']),
        ));

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayFullRoundTripWithMinimalParams(): void
    {
        $original = new CancelledNotification(new CancelledNotificationParams(new RequestId(9)));

        $rebuilt = CancelledNotification::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayFullRoundTripWithAllFields(): void
    {
        $original = new CancelledNotification(new CancelledNotificationParams(
            new RequestId('req-3'),
            'timeout',
            new MetaObject(['vendor' => 'x']),
        ));

        $rebuilt = CancelledNotification::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^CancelledNotification data missing "params"\.$/');

        CancelledNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
        ]);
    }

    public function testFromArrayRejectsNonObjectParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CancelledNotification "params" must be an object, string given.');

        CancelledNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => 'bad',
        ]);
    }

    public function testFromArrayRejectsListKeyedParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CancelledNotification "params" must be a string-keyed object.');

        CancelledNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['a', 'b'],
        ]);
    }

    public function testToArrayOmitsParamsKeyWhenEmpty(): void
    {
        $notification = new CancelledNotification(new CancelledNotificationParams());

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
            ],
            $notification->toArray(),
        );
    }

    public function testJsonSerializeSubstitutesStdClassForEmptyParams(): void
    {
        $notification = new CancelledNotification(new CancelledNotificationParams());

        $serialized = $notification->jsonSerialize();

        self::assertArrayHasKey('params', $serialized);
        self::assertInstanceOf(\stdClass::class, $serialized['params']);
        self::assertSame('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{}}', json_encode($notification, \JSON_UNESCAPED_SLASHES));
    }

    public function testEmptyParamsRoundTripsThroughEnvelope(): void
    {
        $original = new CancelledNotification(new CancelledNotificationParams());

        $envelope = json_encode($original);
        self::assertIsString($envelope);

        $decoded = json_decode($envelope, true);
        Assert::that($decoded)
            ->isArray()
            ->isMap()
        ;

        $rebuilt = CancelledNotification::fromArray($decoded);

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }
}
