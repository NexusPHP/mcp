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

namespace Nexus\Mcp\Tests\Core\Schema\MetaObject;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\NotificationMetaObject;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(NotificationMetaObject::class)]
#[CoversClass(MetaObject::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class NotificationMetaObjectTest extends AbstractMcpTestCase
{
    public function testDefaultsToNoSubscriptionAndNoExtras(): void
    {
        $meta = new NotificationMetaObject();

        self::assertNull($meta->subscriptionId);
        self::assertSame([], $meta->extras);
        self::assertSame([], $meta->toArray());
    }

    public function testToArrayLeadsWithTheSubscriptionId(): void
    {
        $meta = new NotificationMetaObject(subscriptionId: new RequestId(42), extras: ['vendor' => 'acme']);

        self::assertSame([
            'io.modelcontextprotocol/subscriptionId' => 42,
            'vendor' => 'acme',
        ], $meta->toArray());
    }

    public function testToArrayOmitsTheSubscriptionIdWhenAbsent(): void
    {
        $meta = new NotificationMetaObject(extras: ['vendor' => 'acme']);

        self::assertSame(['vendor' => 'acme'], $meta->toArray());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        self::assertSame('{}', json_encode(new NotificationMetaObject()));
    }

    public function testFromArrayLiftsTheSubscriptionIdOutOfTheExtras(): void
    {
        $meta = NotificationMetaObject::fromArray([
            'io.modelcontextprotocol/subscriptionId' => 'stream-1',
            'vendor' => 'acme',
        ]);

        self::assertSame('stream-1', $meta->subscriptionId?->id);
        self::assertSame(['vendor' => 'acme'], $meta->extras);
    }

    public function testFromArrayLeavesTheSubscriptionIdNullWhenAbsent(): void
    {
        $meta = NotificationMetaObject::fromArray(['vendor' => 'acme']);

        self::assertNull($meta->subscriptionId);
        self::assertSame(['vendor' => 'acme'], $meta->extras);
    }

    #[DataProvider('provideFromArrayRejectsANonScalarSubscriptionIdCases')]
    public function testFromArrayRejectsANonScalarSubscriptionId(mixed $value): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^"_meta\.io\.modelcontextprotocol\/subscriptionId" must be an int or a non-empty string, /');

        NotificationMetaObject::fromArray(['io.modelcontextprotocol/subscriptionId' => $value]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideFromArrayRejectsANonScalarSubscriptionIdCases(): iterable
    {
        yield 'null' => [null];

        yield 'bool' => [true];

        yield 'float' => [1.5];

        yield 'array' => [[]];
    }

    public function testRoundTrip(): void
    {
        $meta = new NotificationMetaObject(
            subscriptionId: new RequestId(7),
            extras: ['vendor' => 'acme', 'trace' => 'abc-123'],
        );

        self::assertSame($meta->toArray(), NotificationMetaObject::fromArray($meta->toArray())->toArray());
    }
}
