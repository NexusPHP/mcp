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
use Nexus\Mcp\Core\Schema\RequestParams\GetPromptRequestParams;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GetPromptRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class GetPromptRequestParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new GetPromptRequestParams('code-review', RequestMetaObjectFactory::create());

        self::assertSame('code-review', $params->name);
        self::assertNull($params->arguments);
    }

    public function testConstructionWithAllFields(): void
    {
        $meta = RequestMetaObjectFactory::create(new ProgressToken('p-1'), ['vendor.brand' => 'acme']);
        $params = new GetPromptRequestParams('code-review', $meta, ['topic' => 'auth']);

        self::assertSame(['topic' => 'auth'], $params->arguments);
        self::assertSame($meta, $params->meta);
    }

    public function testToArrayMinimal(): void
    {
        $params = new GetPromptRequestParams('code-review', RequestMetaObjectFactory::create());

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'code-review'],
            $params->toArray(),
        );
    }

    public function testToArrayWithArguments(): void
    {
        $params = new GetPromptRequestParams('code-review', RequestMetaObjectFactory::create(), ['topic' => 'auth']);

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'code-review', 'arguments' => ['topic' => 'auth']],
            $params->toArray(),
        );
    }

    public function testToArrayOmitsEmptyArguments(): void
    {
        $params = new GetPromptRequestParams('code-review', RequestMetaObjectFactory::create(), []);

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'code-review'],
            $params->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $meta = RequestMetaObjectFactory::create(null, ['vendor.brand' => 'acme']);
        $params = new GetPromptRequestParams('code-review', $meta);

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(null, ['vendor.brand' => 'acme']), 'name' => 'code-review'],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new GetPromptRequestParams('code-review', RequestMetaObjectFactory::create(), ['topic' => 'auth']);

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $params = GetPromptRequestParams::fromArray([
            'name' => 'code-review',
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertSame('code-review', $params->name);
        self::assertNull($params->arguments);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $params = GetPromptRequestParams::fromArray([
            'name' => 'code-review',
            'arguments' => ['topic' => 'auth'],
            '_meta' => RequestMetaObjectFactory::shape(null, ['vendor.brand' => 'acme']),
        ]);

        self::assertSame(['topic' => 'auth'], $params->arguments);
        self::assertSame(['vendor.brand' => 'acme'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new GetPromptRequestParams(
            'code-review',
            RequestMetaObjectFactory::create(null, ['vendor.brand' => 'acme']),
            ['topic' => 'auth'],
        );

        $rebuilt = GetPromptRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\A"params.name" must be 1-128 characters/');

        new GetPromptRequestParams('bad name', RequestMetaObjectFactory::create());
    }

    public function testConstructorRejectsListKeyedArguments(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.arguments" must be a string-keyed map.');

        // @phpstan-ignore argument.type
        new GetPromptRequestParams('topic', RequestMetaObjectFactory::create(), ['v1', 'v2']);
    }

    public function testConstructorRejectsNonStringArgumentValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.arguments" values must all be strings, int given.');

        // @phpstan-ignore argument.type
        new GetPromptRequestParams('topic', RequestMetaObjectFactory::create(), ['k' => 1]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        GetPromptRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing name' => [
            [],
            'missing the required "name" key.',
        ];

        yield 'name not a string' => [
            ['name' => 1],
            '"params.name" must be a string, int given.',
        ];

        yield 'arguments not an object' => [
            ['name' => 'topic', 'arguments' => 'oops'],
            '"params.arguments" must be an object, string given.',
        ];

        yield 'arguments list-keyed' => [
            ['name' => 'topic', 'arguments' => ['v']],
            '"params.arguments" must be a string-keyed object.',
        ];

        yield 'argument value not a string' => [
            ['name' => 'topic', 'arguments' => ['k' => 1]],
            '"params.arguments" value must be a string, int given.',
        ];

        yield 'missing _meta' => [
            ['name' => 'topic'],
            '"params" missing the required "_meta" key.',
        ];

        yield '_meta not an object' => [
            ['name' => 'topic', '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['name' => 'topic', '_meta' => ['v']],
            '"params._meta" must be a string-keyed object.',
        ];
    }
}
