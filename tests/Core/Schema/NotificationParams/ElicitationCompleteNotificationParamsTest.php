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
use Nexus\Mcp\Core\Schema\NotificationParams\ElicitationCompleteNotificationParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ElicitationCompleteNotificationParams::class)]
#[CoversClass(NotificationParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitationCompleteNotificationParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new ElicitationCompleteNotificationParams('elicit-1');

        self::assertSame('elicit-1', $params->elicitationId);
        self::assertNull($params->meta);
    }

    public function testToArrayMinimal(): void
    {
        $params = new ElicitationCompleteNotificationParams('elicit-1');

        self::assertSame(['elicitationId' => 'elicit-1'], $params->toArray());
    }

    public function testToArrayWithMeta(): void
    {
        $params = new ElicitationCompleteNotificationParams('elicit-1', new MetaObject(['vendor' => 'x']));

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'elicitationId' => 'elicit-1'],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new ElicitationCompleteNotificationParams('elicit-1', new MetaObject(['k' => 'v']));

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitationCompleteNotificationParams('elicit-1', new MetaObject(['vendor' => 'x']));

        $rebuilt = ElicitationCompleteNotificationParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsEmptyElicitationId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitationCompleteNotificationParams elicitationId must be a non-empty string.');

        new ElicitationCompleteNotificationParams('');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ElicitationCompleteNotificationParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing elicitationId' => [
            [],
            'ElicitationCompleteNotificationParams wire data missing "elicitationId".',
        ];

        yield 'elicitationId not a string' => [
            ['elicitationId' => 1],
            'ElicitationCompleteNotificationParams wire "elicitationId" must be a string, int given.',
        ];
    }
}
