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

namespace Nexus\Mcp\Tests\Core\Schema\NotificationParams;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\SubscriptionsAcknowledgedNotificationParams;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubscriptionsAcknowledgedNotificationParams::class)]
#[CoversClass(NotificationParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SubscriptionsAcknowledgedNotificationParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new SubscriptionsAcknowledgedNotificationParams(notifications: new SubscriptionFilter(toolsListChanged: true));

        self::assertTrue($params->notifications->toolsListChanged);
        self::assertSame([], $params->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $params = new SubscriptionsAcknowledgedNotificationParams(notifications: new SubscriptionFilter(toolsListChanged: true));

        self::assertSame(['notifications' => ['toolsListChanged' => true]], $params->toArray());
    }

    public function testToArrayWithMeta(): void
    {
        $params = new SubscriptionsAcknowledgedNotificationParams(
            notifications: new SubscriptionFilter(toolsListChanged: true),
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'notifications' => ['toolsListChanged' => true]],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArrayWhenFilterNonEmpty(): void
    {
        $params = new SubscriptionsAcknowledgedNotificationParams(
            notifications: new SubscriptionFilter(toolsListChanged: true),
            meta: new MetaObject(extras: ['k' => 'v']),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testJsonSerializeEncodesEmptyFilterAsObject(): void
    {
        $params = new SubscriptionsAcknowledgedNotificationParams(notifications: new SubscriptionFilter());

        self::assertSame('{"notifications":{}}', json_encode($params));
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new SubscriptionsAcknowledgedNotificationParams(
            notifications: new SubscriptionFilter(toolsListChanged: true, resourceSubscriptions: ['file:///x']),
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = SubscriptionsAcknowledgedNotificationParams::fromArray($original->toArray());

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

        SubscriptionsAcknowledgedNotificationParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing notifications' => [
            [],
            '"params" is missing the required "notifications" key.',
        ];

        yield 'notifications not an object' => [
            ['notifications' => 'bad'],
            '"params.notifications" must be an object, string given.',
        ];

        yield 'notifications list-keyed' => [
            ['notifications' => ['x']],
            '"params.notifications" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['notifications' => ['toolsListChanged' => true], '_meta' => 'bad'],
            '"params._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['notifications' => ['toolsListChanged' => true], '_meta' => ['x']],
            '"params._meta" must be a string-keyed object.',
        ];
    }
}
