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
use Nexus\Mcp\Core\Schema\MetaObject\NotificationMetaObject;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ProgressNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ProgressNotificationTest extends AbstractMcpTestCase
{
    public function testMethodIsNotificationsProgress(): void
    {
        self::assertSame('notifications/progress', ProgressNotification::getMethod());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $notification = new ProgressNotification(
            params: new ProgressNotificationParams(progressToken: new ProgressToken(token: 'p-1'), progress: 0.5),
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
            params: new ProgressNotificationParams(
                progressToken: new ProgressToken(token: 7),
                progress: 3.0,
                total: 10.0,
                message: 'fetching',
                meta: new NotificationMetaObject(extras: ['vendor' => 'x']),
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
            params: new ProgressNotificationParams(progressToken: new ProgressToken(token: 'p-1'), progress: 0.5),
        );

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ProgressNotification(
            params: new ProgressNotificationParams(
                progressToken: new ProgressToken(token: 'p-9'),
                progress: 7.0,
                total: 14.0,
                message: 'halfway',
                meta: new NotificationMetaObject(extras: ['vendor' => 'x']),
            ),
        );

        $rebuilt = ProgressNotification::fromArray($original->toArray());

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

        ProgressNotification::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing params' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress'],
            'missing the required "params" key.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => 'bad'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
