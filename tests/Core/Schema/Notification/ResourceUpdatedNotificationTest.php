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
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\ResourceUpdatedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\ResourceUpdatedNotificationParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResourceUpdatedNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceUpdatedNotificationTest extends AbstractMcpTestCase
{
    public function testMethodIsResourcesUpdated(): void
    {
        self::assertSame('notifications/resources/updated', ResourceUpdatedNotification::getMethod());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $notification = new ResourceUpdatedNotification(
            params: new ResourceUpdatedNotificationParams(uri: 'file:///x'),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/resources/updated',
                'params' => ['uri' => 'file:///x'],
            ],
            $notification->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new ResourceUpdatedNotification(
            params: new ResourceUpdatedNotificationParams(uri: 'file:///x'),
        );

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ResourceUpdatedNotification(
            params: new ResourceUpdatedNotificationParams(uri: 'file:///x'),
        );

        $rebuilt = ResourceUpdatedNotification::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ResourceUpdatedNotification::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing params' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/resources/updated'],
            'missing the required "params" key.',
        ];

        yield 'params not an object' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/resources/updated', 'params' => 'bad'],
            '"params" must be an object, string given.',
        ];

        yield 'params list-keyed' => [
            ['jsonrpc' => '2.0', 'method' => 'notifications/resources/updated', 'params' => ['x']],
            '"params" must be a string-keyed object.',
        ];
    }
}
