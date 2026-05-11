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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProgressNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ProgressNotificationTest extends TestCase
{
    public function testMethodIsNotificationsProgress(): void
    {
        self::assertSame('notifications/progress', ProgressNotification::method());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $notification = new ProgressNotification(
            new ProgressNotificationParams(new ProgressToken('p-1'), 0.5),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/progress',
                'params' => ['progressToken' => 'p-1', 'progress' => 0.5],
            ],
            $notification->toArray(),
        );
    }

    public function testToArrayWithFullParams(): void
    {
        $notification = new ProgressNotification(
            new ProgressNotificationParams(
                new ProgressToken(7),
                3.0,
                10.0,
                'fetching',
                new MetaObject(['vendor' => 'x']),
            ),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/progress',
                'params' => [
                    '_meta' => ['vendor' => 'x'],
                    'progressToken' => 7,
                    'progress' => 3.0,
                    'total' => 10.0,
                    'message' => 'fetching',
                ],
            ],
            $notification->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new ProgressNotification(
            new ProgressNotificationParams(new ProgressToken('p-1'), 0.5),
        );

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ProgressNotification(
            new ProgressNotificationParams(
                new ProgressToken('p-9'),
                7.0,
                14.0,
                'halfway',
                new MetaObject(['vendor' => 'x']),
            ),
        );

        $rebuilt = ProgressNotification::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ProgressNotification::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing params' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress'],
            'ProgressNotification wire data missing "params".',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => 'bad'],
            'ProgressNotification wire "params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['x']],
            'ProgressNotification wire "params" must be a string-keyed object.',
        ];
    }
}
