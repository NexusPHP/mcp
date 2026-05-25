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
use Nexus\Mcp\Core\Schema\Notification\ElicitationCompleteNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\ElicitationCompleteNotificationParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ElicitationCompleteNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitationCompleteNotificationTest extends TestCase
{
    public function testMethod(): void
    {
        self::assertSame('notifications/elicitation/complete', ElicitationCompleteNotification::getMethod());
    }

    public function testToArray(): void
    {
        $notification = new ElicitationCompleteNotification(
            new ElicitationCompleteNotificationParams('elicit-1'),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/elicitation/complete',
                'params' => ['elicitationId' => 'elicit-1'],
            ],
            $notification->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new ElicitationCompleteNotification(
            new ElicitationCompleteNotificationParams('elicit-1'),
        );

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitationCompleteNotification(
            new ElicitationCompleteNotificationParams('elicit-1'),
        );

        $rebuilt = ElicitationCompleteNotification::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ElicitationCompleteNotification::fromArray($payload);
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
