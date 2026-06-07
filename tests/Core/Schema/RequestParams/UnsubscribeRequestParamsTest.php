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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ResourceRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\UnsubscribeRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnsubscribeRequestParams::class)]
#[CoversClass(ResourceRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class UnsubscribeRequestParamsTest extends TestCase
{
    public function testConstructionExposesUri(): void
    {
        $params = new UnsubscribeRequestParams('file:///x');

        self::assertSame('file:///x', $params->uri);
        self::assertSame([], $params->meta->toArray());
    }

    public function testToArrayEmitsUriOnly(): void
    {
        $params = new UnsubscribeRequestParams('file:///x');

        self::assertSame(['uri' => 'file:///x'], $params->toArray());
    }

    public function testToArrayIncludesMetaWhenPresent(): void
    {
        $params = new UnsubscribeRequestParams(
            'file:///x',
            new RequestMetaObject(null, ['vendor' => 'x']),
        );

        self::assertSame(
            ['_meta' => ['vendor' => 'x'], 'uri' => 'file:///x'],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new UnsubscribeRequestParams(
            'file:///x',
            new RequestMetaObject(null, ['k' => 'v']),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayParsesUri(): void
    {
        $params = UnsubscribeRequestParams::fromArray(['uri' => 'file:///x']);

        self::assertSame('file:///x', $params->uri);
        self::assertSame([], $params->meta->toArray());
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = UnsubscribeRequestParams::fromArray([
            'uri' => 'file:///x',
            '_meta' => ['vendor' => 'x'],
        ]);
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new UnsubscribeRequestParams(
            'file:///x',
            new RequestMetaObject(null, ['vendor' => 'x']),
        );

        $rebuilt = UnsubscribeRequestParams::fromArray($original->toArray());

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

        UnsubscribeRequestParams::fromArray($payload);
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
