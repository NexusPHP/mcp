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
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\LoggingMessageNotificationParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(LoggingMessageNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class LoggingMessageNotificationTest extends TestCase
{
    public function testMethodIsNotificationsMessage(): void
    {
        self::assertSame('notifications/message', LoggingMessageNotification::getMethod());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $notification = new LoggingMessageNotification(
            params: new LoggingMessageNotificationParams(level: LoggingLevel::Warning, data: 'msg'),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/message',
                'params' => ['level' => 'warning', 'data' => 'msg'],
            ],
            $notification->toArray(),
        );
    }

    public function testToArrayWithFullParams(): void
    {
        $notification = new LoggingMessageNotification(
            params: new LoggingMessageNotificationParams(
                level: LoggingLevel::Error,
                data: ['kind' => 'oom'],
                logger: 'app',
                meta: new MetaObject(extras: ['vendor' => 'x']),
            ),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/message',
                'params' => [
                    '_meta' => ['vendor' => 'x'],
                    'level' => 'error',
                    'data' => ['kind' => 'oom'],
                    'logger' => 'app',
                ],
            ],
            $notification->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new LoggingMessageNotification(
            params: new LoggingMessageNotificationParams(level: LoggingLevel::Info, data: 'x', logger: 'app'),
        );

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new LoggingMessageNotification(
            params: new LoggingMessageNotificationParams(
                level: LoggingLevel::Notice,
                data: ['k' => 'v'],
                logger: 'app.db',
                meta: new MetaObject(extras: ['vendor' => 'x']),
            ),
        );

        $rebuilt = LoggingMessageNotification::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        LoggingMessageNotification::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing params' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/message'],
            'missing the required "params" key.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => 'bad'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
