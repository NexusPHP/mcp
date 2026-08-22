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

namespace Nexus\Mcp\Tests\Server\Attribute;

use Nexus\Mcp\Server\Attribute\InputSchema;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(InputSchema::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class InputSchemaTest extends AbstractMcpTestCase
{
    public function testEmptyWhenNoKeywordsSet(): void
    {
        self::assertSame([], (new InputSchema())->toArray());
    }

    public function testAnEmptyDefinitionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('input schema "definition" must not be an empty array.');

        // @phpstan-ignore argument.type (deliberately empty to exercise the runtime guard)
        new InputSchema(definition: []);
    }

    public function testDefinitionShortCircuitsAndIgnoresOtherKeywords(): void
    {
        $schema = new InputSchema(
            definition: ['type' => 'string', 'const' => 'fixed'],
            type: 'integer',
            description: 'ignored',
        );

        self::assertSame(['type' => 'string', 'const' => 'fixed'], $schema->toArray());
    }

    public function testFalsyKeywordsAreKept(): void
    {
        $schema = new InputSchema(
            minimum: 0,
            uniqueItems: false,
            required: [],
            additionalProperties: false,
        );

        self::assertSame(
            ['minimum' => 0, 'uniqueItems' => false, 'required' => [], 'additionalProperties' => false],
            $schema->toArray(),
        );
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('provideEmitsSingleKeywordCases')]
    public function testEmitsSingleKeyword(InputSchema $schema, array $expected): void
    {
        self::assertSame($expected, $schema->toArray());
    }

    /**
     * @return iterable<string, array{InputSchema, array<string, mixed>}>
     */
    public static function provideEmitsSingleKeywordCases(): iterable
    {
        yield 'type' => [new InputSchema(type: 'string'), ['type' => 'string']];

        yield 'description' => [new InputSchema(description: 'A field.'), ['description' => 'A field.']];

        yield 'enum' => [new InputSchema(enum: ['a', 'b']), ['enum' => ['a', 'b']]];

        yield 'format' => [new InputSchema(format: 'email'), ['format' => 'email']];

        yield 'minLength' => [new InputSchema(minLength: 1), ['minLength' => 1]];

        yield 'maxLength' => [new InputSchema(maxLength: 9), ['maxLength' => 9]];

        yield 'pattern' => [new InputSchema(pattern: '^x'), ['pattern' => '^x']];

        yield 'minimum' => [new InputSchema(minimum: 2), ['minimum' => 2]];

        yield 'maximum' => [new InputSchema(maximum: 8), ['maximum' => 8]];

        yield 'exclusiveMinimum' => [new InputSchema(exclusiveMinimum: 1), ['exclusiveMinimum' => 1]];

        yield 'exclusiveMaximum' => [new InputSchema(exclusiveMaximum: 10), ['exclusiveMaximum' => 10]];

        yield 'multipleOf' => [new InputSchema(multipleOf: 2.5), ['multipleOf' => 2.5]];

        yield 'items' => [new InputSchema(items: ['type' => 'string']), ['items' => ['type' => 'string']]];

        yield 'minItems' => [new InputSchema(minItems: 1), ['minItems' => 1]];

        yield 'maxItems' => [new InputSchema(maxItems: 5), ['maxItems' => 5]];

        yield 'uniqueItems' => [new InputSchema(uniqueItems: true), ['uniqueItems' => true]];

        yield 'properties' => [
            new InputSchema(properties: ['a' => ['type' => 'string']]),
            ['properties' => ['a' => ['type' => 'string']]],
        ];

        yield 'required' => [new InputSchema(required: ['a']), ['required' => ['a']]];

        yield 'additionalProperties' => [new InputSchema(additionalProperties: true), ['additionalProperties' => true]];
    }
}
