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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SubscriptionFilter::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SubscriptionFilterTest extends AbstractMcpTestCase
{
    public function testDefaultsToNullValues(): void
    {
        $filter = new SubscriptionFilter();

        self::assertNull($filter->toolsListChanged);
        self::assertNull($filter->promptsListChanged);
        self::assertNull($filter->resourcesListChanged);
        self::assertNull($filter->resourceSubscriptions);
    }

    public function testConstructorRejectsNonListResourceSubscriptions(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"notifications.resourceSubscriptions" must be a list, non-list array given.');

        new SubscriptionFilter(resourceSubscriptions: ['a' => 'file:///x']); // @phpstan-ignore argument.type
    }

    public function testConstructorRejectsNonStringResourceSubscriptionEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "notifications.resourceSubscriptions" must be a string, int given.');

        new SubscriptionFilter(resourceSubscriptions: [42]); // @phpstan-ignore argument.type
    }

    public function testToArrayOmitsNullProperties(): void
    {
        $filter = new SubscriptionFilter();

        self::assertSame([], $filter->toArray());
    }

    public function testToArrayContainsAllSetProperties(): void
    {
        $filter = new SubscriptionFilter(
            toolsListChanged: true,
            promptsListChanged: false,
            resourcesListChanged: true,
            resourceSubscriptions: ['file:///x', 'file:///y'],
        );

        self::assertSame([
            'toolsListChanged' => true,
            'promptsListChanged' => false,
            'resourcesListChanged' => true,
            'resourceSubscriptions' => ['file:///x', 'file:///y'],
        ], $filter->toArray());
    }

    public function testJsonSerializeMatchesToArrayWhenNonEmpty(): void
    {
        $filter = new SubscriptionFilter(toolsListChanged: true);

        self::assertSame($filter->toArray(), $filter->jsonSerialize());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        self::assertInstanceOf(\stdClass::class, (new SubscriptionFilter())->jsonSerialize());
        self::assertSame('{}', json_encode(new SubscriptionFilter()));
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new SubscriptionFilter(
            toolsListChanged: true,
            promptsListChanged: false,
            resourcesListChanged: true,
            resourceSubscriptions: ['file:///x'],
        );

        $rebuilt = SubscriptionFilter::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayDefaultsMissingFieldsToNull(): void
    {
        $filter = SubscriptionFilter::fromArray([]);

        self::assertNull($filter->toolsListChanged);
        self::assertNull($filter->promptsListChanged);
        self::assertNull($filter->resourcesListChanged);
        self::assertNull($filter->resourceSubscriptions);
    }

    public function testFromArrayPreservesFalseOptIns(): void
    {
        $filter = SubscriptionFilter::fromArray(['toolsListChanged' => false]);

        self::assertFalse($filter->toolsListChanged);
        self::assertSame(['toolsListChanged' => false], $filter->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        SubscriptionFilter::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'toolsListChanged not a bool' => [
            ['toolsListChanged' => 1],
            '"notifications.toolsListChanged" must be a bool, int given.',
        ];

        yield 'promptsListChanged not a bool' => [
            ['promptsListChanged' => 'yes'],
            '"notifications.promptsListChanged" must be a bool, string given.',
        ];

        yield 'resourcesListChanged not a bool' => [
            ['resourcesListChanged' => 0.5],
            '"notifications.resourcesListChanged" must be a bool, float given.',
        ];

        yield 'resourceSubscriptions not a list' => [
            ['resourceSubscriptions' => 'file:///x'],
            '"notifications.resourceSubscriptions" must be a list, string given.',
        ];

        yield 'resourceSubscriptions non-list array' => [
            ['resourceSubscriptions' => ['a' => 'file:///x']],
            '"notifications.resourceSubscriptions" must be a list, array given.',
        ];

        yield 'resourceSubscriptions non-string entry' => [
            ['resourceSubscriptions' => [42]],
            'each "notifications.resourceSubscriptions" must be a string, int given.',
        ];
    }
}
