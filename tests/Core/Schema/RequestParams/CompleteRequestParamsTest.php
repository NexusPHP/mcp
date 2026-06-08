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
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\CompleteRequestParams;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplateReference;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CompleteRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CompleteRequestParamsTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new CompleteRequestParams(
            new PromptReference('code-review'),
            ['name' => 'topic', 'value' => 'auth'],
            RequestMetaObjectFactory::create(),
        );

        self::assertInstanceOf(PromptReference::class, $params->ref);
        self::assertSame(['name' => 'topic', 'value' => 'auth'], $params->argument);
        self::assertNull($params->context);
    }

    public function testConstructionWithAllFields(): void
    {
        $meta = RequestMetaObjectFactory::create(new ProgressToken('p-1'), ['vendor.brand' => 'acme']);
        $params = new CompleteRequestParams(
            new ResourceTemplateReference('file:///{path}'),
            ['name' => 'path', 'value' => 'src/'],
            $meta,
            ['arguments' => ['path' => 'src/']],
        );

        self::assertInstanceOf(ResourceTemplateReference::class, $params->ref);
        self::assertSame(['arguments' => ['path' => 'src/']], $params->context);
        self::assertSame($meta, $params->meta);
    }

    public function testConstructionAcceptsEmptyContext(): void
    {
        $params = new CompleteRequestParams(
            new PromptReference('code-review'),
            ['name' => 'topic', 'value' => 'auth'],
            RequestMetaObjectFactory::create(),
            [],
        );

        self::assertSame([], $params->context);
    }

    public function testToArrayMinimal(): void
    {
        $params = new CompleteRequestParams(
            new PromptReference('code-review'),
            ['name' => 'topic', 'value' => 'auth'],
            RequestMetaObjectFactory::create(),
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(),
                'ref' => ['name' => 'code-review', 'type' => 'ref/prompt'],
                'argument' => ['name' => 'topic', 'value' => 'auth'],
            ],
            $params->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $params = new CompleteRequestParams(
            new ResourceTemplateReference('file:///{path}'),
            ['name' => 'path', 'value' => 'src/'],
            RequestMetaObjectFactory::create(null, ['vendor.brand' => 'acme']),
            ['arguments' => ['path' => 'src/']],
        );

        self::assertSame(
            [
                '_meta' => RequestMetaObjectFactory::shape(null, ['vendor.brand' => 'acme']),
                'ref' => ['type' => 'ref/resource', 'uri' => 'file:///{path}'],
                'argument' => ['name' => 'path', 'value' => 'src/'],
                'context' => ['arguments' => ['path' => 'src/']],
            ],
            $params->toArray(),
        );
    }

    public function testToArrayOmitsEmptyContext(): void
    {
        $params = new CompleteRequestParams(
            new PromptReference('code-review'),
            ['name' => 'topic', 'value' => 'auth'],
            RequestMetaObjectFactory::create(),
            [],
        );

        self::assertArrayNotHasKey('context', $params->toArray());
    }

    public function testToArrayOmitsContextWhoseArgumentsAreEmpty(): void
    {
        $params = new CompleteRequestParams(
            new PromptReference('code-review'),
            ['name' => 'topic', 'value' => 'auth'],
            RequestMetaObjectFactory::create(),
            ['arguments' => []],
        );

        self::assertArrayNotHasKey('context', $params->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new CompleteRequestParams(
            new PromptReference('code-review'),
            ['name' => 'topic', 'value' => 'auth'],
            RequestMetaObjectFactory::create(),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayDispatchesPromptReference(): void
    {
        $params = CompleteRequestParams::fromArray([
            'ref' => ['type' => 'ref/prompt', 'name' => 'code-review'],
            'argument' => ['name' => 'topic', 'value' => 'auth'],
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertInstanceOf(PromptReference::class, $params->ref);
    }

    public function testFromArrayDispatchesResourceTemplateReference(): void
    {
        $params = CompleteRequestParams::fromArray([
            'ref' => ['type' => 'ref/resource', 'uri' => 'file:///{path}'],
            'argument' => ['name' => 'path', 'value' => 'src/'],
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertInstanceOf(ResourceTemplateReference::class, $params->ref);
    }

    public function testFromArrayParsesContext(): void
    {
        $params = CompleteRequestParams::fromArray([
            'ref' => ['type' => 'ref/prompt', 'name' => 'code-review'],
            'argument' => ['name' => 'topic', 'value' => 'auth'],
            'context' => ['arguments' => ['topic' => 'auth']],
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertSame(['arguments' => ['topic' => 'auth']], $params->context);
    }

    public function testFromArrayAcceptsContextWithoutArguments(): void
    {
        $params = CompleteRequestParams::fromArray([
            'ref' => ['type' => 'ref/prompt', 'name' => 'code-review'],
            'argument' => ['name' => 'topic', 'value' => 'auth'],
            'context' => [],
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertSame([], $params->context);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CompleteRequestParams(
            new PromptReference('code-review'),
            ['name' => 'topic', 'value' => 'auth'],
            RequestMetaObjectFactory::create(null, ['vendor.brand' => 'acme']),
            ['arguments' => ['topic' => 'auth']],
        );

        $rebuilt = CompleteRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonStringArgumentName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.argument.name" must be a string, int given.');

        new CompleteRequestParams(
            new PromptReference('code-review'),
            // @phpstan-ignore argument.type
            ['name' => 1, 'value' => 'auth'],
            RequestMetaObjectFactory::create(),
        );
    }

    public function testConstructorRejectsNonStringArgumentValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.argument.value" must be a string, int given.');

        new CompleteRequestParams(
            new PromptReference('code-review'),
            // @phpstan-ignore argument.type
            ['name' => 'topic', 'value' => 1],
            RequestMetaObjectFactory::create(),
        );
    }

    public function testConstructorRejectsNonMapContextArguments(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.context.arguments" must be a string-keyed object.');

        new CompleteRequestParams(
            new PromptReference('code-review'),
            ['name' => 'topic', 'value' => 'auth'],
            RequestMetaObjectFactory::create(),
            // @phpstan-ignore argument.type
            ['arguments' => ['v']],
        );
    }

    public function testConstructorRejectsNonStringContextArgumentValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "params.context.arguments" must be a string, int given.');

        new CompleteRequestParams(
            new PromptReference('code-review'),
            ['name' => 'topic', 'value' => 'auth'],
            RequestMetaObjectFactory::create(),
            // @phpstan-ignore argument.type
            ['arguments' => ['k' => 1]],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        CompleteRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing ref' => [
            ['argument' => ['name' => 'topic', 'value' => 'auth']],
            'missing the required "ref" key.',
        ];

        yield 'ref not an object' => [
            ['ref' => 'oops', 'argument' => ['name' => 'topic', 'value' => 'auth']],
            '"params.ref" must be an object, string given.',
        ];

        yield 'ref list-keyed' => [
            ['ref' => ['x'], 'argument' => ['name' => 'topic', 'value' => 'auth']],
            '"params.ref" must be a string-keyed object.',
        ];

        yield 'ref missing type' => [
            ['ref' => ['name' => 'code-review'], 'argument' => ['name' => 'topic', 'value' => 'auth'], '_meta' => RequestMetaObjectFactory::shape()],
            '"params.ref" data missing "type".',
        ];

        yield 'ref type not a string' => [
            ['ref' => ['type' => 1], 'argument' => ['name' => 'topic', 'value' => 'auth'], '_meta' => RequestMetaObjectFactory::shape()],
            '"params.ref" "type" must be a string, int given.',
        ];

        yield 'ref type unknown' => [
            ['ref' => ['type' => 'unknown'], 'argument' => ['name' => 'topic', 'value' => 'auth'], '_meta' => RequestMetaObjectFactory::shape()],
            '"params.ref" "type" must be one of "ref/prompt", "ref/resource", \'unknown\' given.',
        ];

        yield 'missing argument' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review']],
            'missing the required "argument" key.',
        ];

        yield 'argument not an object' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => 'oops'],
            '"params.argument" must be an object, string given.',
        ];

        yield 'argument list-keyed' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['x']],
            '"params.argument" must be a string-keyed object.',
        ];

        yield 'argument missing name' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['value' => 'auth']],
            'missing the required "argument.name" key.',
        ];

        yield 'argument name not a string' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 1, 'value' => 'auth']],
            '"params.argument.name" must be a string, int given.',
        ];

        yield 'argument missing value' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 'topic']],
            'missing the required "argument.value" key.',
        ];

        yield 'argument value not a string' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 'topic', 'value' => 1]],
            '"params.argument.value" must be a string, int given.',
        ];

        yield 'context not an object' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 'topic', 'value' => 'auth'], 'context' => 'oops'],
            '"params.context" must be an object, string given.',
        ];

        yield 'context list-keyed' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 'topic', 'value' => 'auth'], 'context' => ['x']],
            '"params.context" must be a string-keyed object.',
        ];

        yield 'context arguments not an object' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 'topic', 'value' => 'auth'], 'context' => ['arguments' => 'oops']],
            '"params.context.arguments" must be an object, string given.',
        ];

        yield 'context arguments list-keyed' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 'topic', 'value' => 'auth'], 'context' => ['arguments' => ['v']]],
            '"params.context.arguments" must be a string-keyed object.',
        ];

        yield 'context argument value not a string' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 'topic', 'value' => 'auth'], 'context' => ['arguments' => ['k' => 1]]],
            'each "params.context.arguments" must be a string, int given.',
        ];

        yield 'missing _meta' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 'topic', 'value' => 'auth']],
            '"params" missing the required "_meta" key.',
        ];

        yield '_meta not an object' => [
            ['ref' => ['type' => 'ref/prompt', 'name' => 'code-review'], 'argument' => ['name' => 'topic', 'value' => 'auth'], '_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];
    }
}
