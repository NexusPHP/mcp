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
use Nexus\Mcp\Core\Schema\NotificationParams\ResourceUpdatedNotificationParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceUpdatedNotificationParams::class)]
#[CoversClass(NotificationParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceUpdatedNotificationParamsTest extends TestCase
{
    public function testConstructionExposesUri(): void
    {
        $params = new ResourceUpdatedNotificationParams('file:///x');

        self::assertSame('file:///x', $params->uri);
        self::assertSame([], $params->meta->toArray());
    }

    public function testToArrayEmitsUriOnly(): void
    {
        $params = new ResourceUpdatedNotificationParams('file:///x');

        self::assertSame(['uri' => 'file:///x'], $params->toArray());
    }

    public function testToArrayIncludesMetaWhenPresent(): void
    {
        $params = new ResourceUpdatedNotificationParams(
            'file:///x',
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'uri' => 'file:///x'],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new ResourceUpdatedNotificationParams(
            'file:///x',
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayParsesUri(): void
    {
        $params = ResourceUpdatedNotificationParams::fromArray(['uri' => 'file:///x']);

        self::assertSame('file:///x', $params->uri);
        self::assertSame([], $params->meta->toArray());
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = ResourceUpdatedNotificationParams::fromArray([
            'uri' => 'file:///x',
            '_meta' => ['vendor' => 'x'],
        ]);
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ResourceUpdatedNotificationParams(
            'file:///x',
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = ResourceUpdatedNotificationParams::fromArray($original->toArray());

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

        ResourceUpdatedNotificationParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing uri' => [
            [],
            'missing the required "uri" key.',
        ];

        yield 'uri not a string' => [
            ['uri' => 1],
            '"params.uri" must be a string, int given.',
        ];

        yield '_meta not an object' => [
            ['uri' => 'file:///x', '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['uri' => 'file:///x', '_meta' => ['x']],
            '"params._meta" must be a string-keyed object.',
        ];
    }
}
