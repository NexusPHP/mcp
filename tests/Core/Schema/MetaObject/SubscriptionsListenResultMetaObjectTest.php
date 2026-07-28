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
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\SubscriptionsListenResultMetaObject;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubscriptionsListenResultMetaObject::class)]
#[CoversClass(ResultMetaObject::class)]
#[CoversClass(MetaObject::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SubscriptionsListenResultMetaObjectTest extends TestCase
{
    public function testToArrayLeadsWithTheSubscriptionId(): void
    {
        $meta = new SubscriptionsListenResultMetaObject(
            subscriptionId: new RequestId(42),
            serverInfo: new Implementation(name: 'srv', version: '1.0.0'),
            extras: ['vendor' => 'acme'],
        );

        self::assertSame([
            'io.modelcontextprotocol/subscriptionId' => 42,
            'io.modelcontextprotocol/serverInfo' => ['name' => 'srv', 'version' => '1.0.0'],
            'vendor' => 'acme',
        ], $meta->toArray());
    }

    public function testToArrayCarriesOnlyTheSubscriptionIdWhenNothingElseIsSet(): void
    {
        $meta = new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId('stream-1'));

        self::assertSame(['io.modelcontextprotocol/subscriptionId' => 'stream-1'], $meta->toArray());
    }

    public function testJsonSerializeNeverCollapsesToAnEmptyObject(): void
    {
        $meta = new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId(7));

        self::assertSame('{"io.modelcontextprotocol\/subscriptionId":7}', json_encode($meta));
    }

    public function testFromArraySplitsEveryTypedSlotOutOfTheExtras(): void
    {
        $meta = SubscriptionsListenResultMetaObject::fromArray([
            'io.modelcontextprotocol/subscriptionId' => 42,
            'io.modelcontextprotocol/serverInfo' => ['name' => 'srv', 'version' => '1.0.0'],
            'vendor' => 'acme',
        ]);

        self::assertSame(42, $meta->subscriptionId->id);
        self::assertSame('srv', $meta->serverInfo?->name);
        self::assertSame(['vendor' => 'acme'], $meta->extras);
    }

    public function testFromArrayRejectsAMissingSubscriptionId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result._meta" is missing the required "io.modelcontextprotocol/subscriptionId" key.');

        SubscriptionsListenResultMetaObject::fromArray(['vendor' => 'acme']);
    }

    #[DataProvider('provideFromArrayRejectsANonScalarSubscriptionIdCases')]
    public function testFromArrayRejectsANonScalarSubscriptionId(mixed $value): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^"_meta\.io\.modelcontextprotocol\/subscriptionId" must be an int or a string, /');

        SubscriptionsListenResultMetaObject::fromArray(['io.modelcontextprotocol/subscriptionId' => $value]);
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

    public function testDeclaresServerInfoTracksTheTypedSlot(): void
    {
        $without = new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId(1));
        $with = new SubscriptionsListenResultMetaObject(
            subscriptionId: new RequestId(1),
            serverInfo: new Implementation(name: 'srv', version: '1.0.0'),
        );

        self::assertFalse($without->declaresServerInfo());
        self::assertTrue($with->declaresServerInfo());
    }
}
