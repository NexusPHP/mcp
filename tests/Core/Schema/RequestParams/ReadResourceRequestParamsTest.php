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
use Nexus\Mcp\Core\Schema\RequestParams\ReadResourceRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ResourceRequestParams;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReadResourceRequestParams::class)]
#[CoversClass(ResourceRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ReadResourceRequestParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new ReadResourceRequestParams(uri: 'file:///x', meta: RequestMetaObjectFactory::create());

        self::assertSame('file:///x', $params->uri);
    }

    public function testConstructionWithMeta(): void
    {
        $params = new ReadResourceRequestParams(
            uri: 'file:///x',
            meta: RequestMetaObjectFactory::create(new ProgressToken(token: 'p-1'), ['vendor' => 'x']),
        );
        self::assertNotNull($params->meta->progressToken);
        self::assertSame('p-1', $params->meta->progressToken->token);
    }

    public function testToArrayWithMeta(): void
    {
        $params = new ReadResourceRequestParams(
            uri: 'file:///x',
            meta: RequestMetaObjectFactory::create(new ProgressToken(token: 'p-1')),
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(new ProgressToken(token: 'p-1')),
                'uri' => 'file:///x',
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new ReadResourceRequestParams(uri: 'file:///x', meta: RequestMetaObjectFactory::create());

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $params = ReadResourceRequestParams::fromArray([
            'uri' => 'file:///x',
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertSame('file:///x', $params->uri);
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = ReadResourceRequestParams::fromArray([
            'uri' => 'file:///x',
            '_meta' => RequestMetaObjectFactory::shape(new ProgressToken(token: 'p-1'), ['vendor' => 'x']),
        ]);
        self::assertNotNull($params->meta->progressToken);
        self::assertSame('p-1', $params->meta->progressToken->token);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new ReadResourceRequestParams(
            uri: 'file:///x',
            meta: RequestMetaObjectFactory::create(new ProgressToken(token: 'p-1'), ['vendor' => 'x']),
        );

        self::assertSame($original->toArray(), ReadResourceRequestParams::fromArray($original->toArray())->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ReadResourceRequestParams::fromArray($payload);
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

        yield 'missing _meta' => [
            ['uri' => 'file:///x'],
            '"params" missing the required "_meta" key.',
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
