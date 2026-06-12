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
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\SubscriptionsAcknowledgedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\SubscriptionsAcknowledgedNotificationParams;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubscriptionsAcknowledgedNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SubscriptionsAcknowledgedNotificationTest extends TestCase
{
    public function testMethod(): void
    {
        self::assertSame('notifications/subscriptions/acknowledged', SubscriptionsAcknowledgedNotification::getMethod());
    }

    public function testToArray(): void
    {
        $notification = new SubscriptionsAcknowledgedNotification(
            params: new SubscriptionsAcknowledgedNotificationParams(notifications: new SubscriptionFilter(toolsListChanged: true)),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/subscriptions/acknowledged',
                'params' => ['notifications' => ['toolsListChanged' => true]],
            ],
            $notification->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArrayWhenFilterNonEmpty(): void
    {
        $notification = new SubscriptionsAcknowledgedNotification(
            params: new SubscriptionsAcknowledgedNotificationParams(notifications: new SubscriptionFilter(toolsListChanged: true)),
        );

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testJsonSerializeEncodesEmptyFilterAsObject(): void
    {
        $notification = new SubscriptionsAcknowledgedNotification(
            params: new SubscriptionsAcknowledgedNotificationParams(notifications: new SubscriptionFilter()),
        );

        self::assertSame(
            '{"jsonrpc":"2.0","method":"notifications\/subscriptions\/acknowledged","params":{"notifications":{}}}',
            json_encode($notification),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new SubscriptionsAcknowledgedNotification(
            params: new SubscriptionsAcknowledgedNotificationParams(
                notifications: new SubscriptionFilter(toolsListChanged: true, resourceSubscriptions: ['file:///x']),
            ),
        );

        $rebuilt = SubscriptionsAcknowledgedNotification::fromArray($original->toArray());

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

        SubscriptionsAcknowledgedNotification::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing params' => [
            [],
            'missing the required "params" key.',
        ];

        yield 'params not an object' => [
            ['params' => 'oops'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
