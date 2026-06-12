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

namespace Nexus\Mcp\Tests\Core\Schema\RequestParams;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\SubscriptionsListenRequestParams;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubscriptionsListenRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SubscriptionsListenRequestParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new SubscriptionsListenRequestParams(
            notifications: new SubscriptionFilter(toolsListChanged: true),
            meta: RequestMetaObjectFactory::create(),
        );

        self::assertTrue($params->notifications->toolsListChanged);
    }

    public function testToArrayWithMeta(): void
    {
        $params = new SubscriptionsListenRequestParams(
            notifications: new SubscriptionFilter(toolsListChanged: true),
            meta: RequestMetaObjectFactory::create(new ProgressToken(token: 'p-1')),
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(new ProgressToken(token: 'p-1')),
                'notifications' => ['toolsListChanged' => true],
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArrayWhenFilterNonEmpty(): void
    {
        $params = new SubscriptionsListenRequestParams(
            notifications: new SubscriptionFilter(resourceSubscriptions: ['file:///x']),
            meta: RequestMetaObjectFactory::create(),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testJsonSerializeEncodesEmptyFilterAsObject(): void
    {
        $params = new SubscriptionsListenRequestParams(
            notifications: new SubscriptionFilter(),
            meta: RequestMetaObjectFactory::create(),
        );

        self::assertStringContainsString('"notifications":{}', (string) json_encode($params));
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new SubscriptionsListenRequestParams(
            notifications: new SubscriptionFilter(
                toolsListChanged: true,
                promptsListChanged: true,
                resourcesListChanged: false,
                resourceSubscriptions: ['file:///x'],
            ),
            meta: RequestMetaObjectFactory::create(),
        );

        $rebuilt = SubscriptionsListenRequestParams::fromArray($original->toArray());

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

        SubscriptionsListenRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing notifications' => [
            ['_meta' => RequestMetaObjectFactory::shape()],
            'missing the required "notifications" key.',
        ];

        yield 'notifications not an object' => [
            ['notifications' => 'bad', '_meta' => RequestMetaObjectFactory::shape()],
            '"params.notifications" must be an object, string given.',
        ];

        yield 'notifications list-keyed' => [
            ['notifications' => ['x'], '_meta' => RequestMetaObjectFactory::shape()],
            '"params.notifications" must be a string-keyed object.',
        ];

        yield 'missing _meta' => [
            ['notifications' => ['toolsListChanged' => true]],
            '"params" missing the required "_meta" key.',
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
