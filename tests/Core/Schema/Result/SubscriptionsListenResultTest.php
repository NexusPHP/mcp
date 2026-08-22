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

namespace Nexus\Mcp\Tests\Core\Schema\Result;

use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\SubscriptionsListenResultMetaObject;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\SubscriptionsListenResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SubscriptionsListenResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SubscriptionsListenResultTest extends AbstractMcpTestCase
{
    public function testToArrayAlwaysCarriesMetaAndResultType(): void
    {
        $result = new SubscriptionsListenResult(
            new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId(42)),
        );

        self::assertSame([
            '_meta' => ['io.modelcontextprotocol/subscriptionId' => 42],
            'resultType' => 'complete',
        ], $result->toArray());
    }

    public function testFromArrayReadsTheSubscriptionIdOffMeta(): void
    {
        $result = SubscriptionsListenResult::fromArray([
            '_meta' => ['io.modelcontextprotocol/subscriptionId' => 'stream-1', 'vendor' => 'acme'],
            'resultType' => 'complete',
        ]);

        self::assertSame('stream-1', $result->meta->subscriptionId->id);
        self::assertSame(['vendor' => 'acme'], $result->meta->extras);
    }

    public function testFromArrayRejectsAMissingMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" is missing the required "_meta" key.');

        SubscriptionsListenResult::fromArray(['resultType' => 'complete']);
    }

    public function testFromArrayRejectsANonObjectMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result._meta" must be an object, string given.');

        SubscriptionsListenResult::fromArray(['_meta' => 'nope']);
    }

    public function testFromArrayRejectsAnIntKeyedMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result._meta" must be a string-keyed object.');

        SubscriptionsListenResult::fromArray(['_meta' => ['nope']]);
    }

    public function testRebuildingWithAPlainMetaKeepsTheSubscriptionId(): void
    {
        $result = new SubscriptionsListenResult(
            new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId(42), extras: ['dropped' => true]),
        );

        $rebuilt = $result->rebuildWithMeta(new GenericResultMetaObject(
            serverInfo: new Implementation(name: 'srv', version: '1.0.0'),
        ));

        self::assertSame([
            '_meta' => [
                'io.modelcontextprotocol/subscriptionId' => 42,
                'io.modelcontextprotocol/serverInfo' => ['name' => 'srv', 'version' => '1.0.0'],
            ],
            'resultType' => 'complete',
        ], $rebuilt->toArray());
    }

    public function testRebuildingWithANarrowMetaStillTakesThisResultsSubscriptionId(): void
    {
        $result = new SubscriptionsListenResult(
            new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId(42)),
        );

        $rebuilt = $result->rebuildWithMeta(
            new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId(99)),
        );

        self::assertSame(42, $rebuilt->meta->subscriptionId->id);
    }

    public function testRoundTrip(): void
    {
        $result = new SubscriptionsListenResult(
            new SubscriptionsListenResultMetaObject(
                subscriptionId: new RequestId('stream-1'),
                serverInfo: new Implementation(name: 'srv', version: '1.0.0'),
                extras: ['vendor' => 'acme'],
            ),
        );

        self::assertSame($result->toArray(), SubscriptionsListenResult::fromArray($result->toArray())->toArray());
    }

    public function testEncodingPathsAgree(): void
    {
        $result = new SubscriptionsListenResult(
            new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId(42)),
        );

        self::assertSame(json_encode($result), json_encode($result->toArray()));
    }
}
