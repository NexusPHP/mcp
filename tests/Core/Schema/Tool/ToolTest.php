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

namespace Nexus\Mcp\Tests\Core\Schema\Tool;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Schema\Tool\ToolAnnotations;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Tool::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ToolTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $tool = new Tool(name: 'read-file', inputSchema: ['type' => 'object']);

        self::assertSame('read-file', $tool->name);
        self::assertNull($tool->title);
        self::assertNull($tool->description);
        self::assertSame(['type' => 'object'], $tool->inputSchema);
        self::assertNull($tool->outputSchema);
        self::assertSame([], $tool->annotations->toArray());
        self::assertNull($tool->icons);
        self::assertSame([], $tool->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $tool = new Tool(name: 'read-file', inputSchema: ['type' => 'object']);

        self::assertSame(
            [
                'name' => 'read-file',
                'inputSchema' => ['type' => 'object'],
            ],
            $tool->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $tool = new Tool(
            name: 'read-file',
            inputSchema: self::objectSchema(),
            title: 'Read File',
            description: 'Reads contents.',
            outputSchema: ['type' => 'object', 'properties' => ['content' => ['type' => 'string']]],
            annotations: new ToolAnnotations(readOnlyHint: true),
            icons: [new Icon(src: 'https://example.com/icon.png')],
            meta: new PayloadMetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                'name' => 'read-file',
                'inputSchema' => self::objectSchema(),
                'title' => 'Read File',
                'description' => 'Reads contents.',
                'outputSchema' => ['type' => 'object', 'properties' => ['content' => ['type' => 'string']]],
                'annotations' => ['readOnlyHint' => true],
                'icons' => [['src' => 'https://example.com/icon.png']],
                '_meta' => ['vendor' => 'x'],
            ],
            $tool->toArray(),
        );
    }

    public function testToArrayOmitsEmptyAnnotations(): void
    {
        $tool = new Tool(name: 'read-file', inputSchema: ['type' => 'object']);

        self::assertSame(
            ['name' => 'read-file', 'inputSchema' => ['type' => 'object']],
            $tool->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $tool = new Tool(name: 'read-file', inputSchema: self::objectSchema());

        self::assertSame($tool->toArray(), $tool->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $tool = Tool::fromArray(['name' => 'read-file', 'inputSchema' => ['type' => 'object']]);

        self::assertSame('read-file', $tool->name);
        self::assertSame(['type' => 'object'], $tool->inputSchema);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $tool = Tool::fromArray([
            'name' => 'read-file',
            'inputSchema' => self::objectSchema(),
            'title' => 'Read File',
            'description' => 'Reads contents.',
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
            'icons' => [['src' => 'https://example.com/icon.png']],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('Read File', $tool->title);
        self::assertSame('Reads contents.', $tool->description);
        self::assertSame(['type' => 'object'], $tool->outputSchema);
        self::assertTrue($tool->annotations->readOnlyHint);
        self::assertNotNull($tool->icons);
        self::assertCount(1, $tool->icons);
        self::assertSame(['vendor' => 'x'], $tool->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new Tool(
            name: 'read-file',
            inputSchema: self::objectSchema(),
            title: 'Read File',
            description: 'Reads contents.',
            outputSchema: ['type' => 'object', 'properties' => ['content' => ['type' => 'string']]],
            annotations: new ToolAnnotations(title: 'Read File', readOnlyHint: true),
            icons: [new Icon(src: 'https://example.com/icon.png')],
            meta: new PayloadMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = Tool::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorAcceptsANameOutsideTheSdksPreferredCharset(): void
    {
        $tool = new Tool(name: 'Project Files', inputSchema: ['type' => 'object']);

        self::assertSame('Project Files', $tool->name);
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Tool description must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new Tool(name: 'read-file', inputSchema: ['type' => 'object'], description: '');
    }

    public function testConstructorRejectsNonIconEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new Tool(name: 'read-file', inputSchema: ['type' => 'object'], icons: [42]);
    }

    public function testConstructorPreservesArbitraryInputSchemaKeywords(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
            'required' => ['path'],
            'additionalProperties' => false,
            '$defs' => ['nonEmpty' => ['type' => 'string', 'minLength' => 1]],
            'allOf' => [['required' => ['path']]],
        ];

        $tool = new Tool(name: 'read-file', inputSchema: $schema);

        self::assertSame($schema, $tool->inputSchema);
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('provideConstructorRejectsInvalidSchemaEnvelopeCases')]
    public function testConstructorRejectsInvalidSchemaEnvelope(array $schema, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        new Tool(name: 'read-file', inputSchema: $schema);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideConstructorRejectsInvalidSchemaEnvelopeCases(): iterable
    {
        yield 'missing type' => [
            [],
            'tool "inputSchema" missing "type".',
        ];

        yield 'type not "object"' => [
            ['type' => 'array'],
            'tool "inputSchema" "type" must be \'object\', \'array\' given.',
        ];

        yield '$schema not non-empty string' => [
            ['type' => 'object', '$schema' => ''],
            'tool "inputSchema" "$schema" must be a non-empty string, string given.',
        ];

        yield 'properties not an object' => [
            ['type' => 'object', 'properties' => 'oops'],
            'tool "inputSchema" "properties" must be an object, string given.',
        ];

        yield 'properties entry not an object or boolean' => [
            ['type' => 'object', 'properties' => ['x']],
            'tool "inputSchema" property entry must be an object or boolean, string given.',
        ];

        yield 'property entry not an object' => [
            ['type' => 'object', 'properties' => ['name' => 'oops']],
            'tool "inputSchema" property entry must be an object or boolean, string given.',
        ];

        yield 'property entry list-keyed' => [
            ['type' => 'object', 'properties' => ['name' => ['oops']]],
            'tool "inputSchema" property entry must be a string-keyed object.',
        ];

        yield 'property entry invalid behind a boolean one' => [
            ['type' => 'object', 'properties' => ['fine' => true, 'name' => 'oops']],
            'tool "inputSchema" property entry must be an object or boolean, string given.',
        ];

        yield 'required not a list' => [
            ['type' => 'object', 'required' => 'oops'],
            'tool "inputSchema" "required" must be a list, got non-list array.',
        ];

        yield 'required entry not a string' => [
            ['type' => 'object', 'required' => [1]],
            'tool "inputSchema" "required" entry must be a string, int given.',
        ];
    }

    #[DataProvider('provideConstructorAcceptsABooleanSubSchemaCases')]
    public function testConstructorAcceptsABooleanSubSchema(bool $entry): void
    {
        $schema = ['type' => 'object', 'properties' => ['extra' => $entry]];

        $tool = new Tool(name: 'peer-tool', inputSchema: $schema, outputSchema: $schema);

        self::assertSame($schema, $tool->inputSchema);
        self::assertSame($schema, $tool->outputSchema);
        self::assertStringContainsString(
            \sprintf('"properties":{"extra":%s}', $entry ? 'true' : 'false'),
            json_encode($tool, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return iterable<string, array{0: bool}>
     */
    public static function provideConstructorAcceptsABooleanSubSchemaCases(): iterable
    {
        yield 'always-valid schema' => [true];

        yield 'always-invalid schema' => [false];
    }

    public function testAToolsListPageSurvivesAPeerDeclaringABooleanSubSchema(): void
    {
        $schema = ['type' => 'object', 'properties' => ['extra' => true]];

        $tool = Tool::fromArray(['name' => 'peer-tool', 'inputSchema' => $schema]);

        self::assertSame($schema, $tool->inputSchema);
    }

    public function testConstructorAcceptsNonObjectOutputSchemaRoot(): void
    {
        $tool = new Tool(
            name: 'read-file',
            inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'array', 'items' => ['type' => 'string']],
        );

        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $tool->outputSchema);
    }

    public function testConstructorAcceptsOutputSchemaWithoutType(): void
    {
        $tool = new Tool(
            name: 'read-file',
            inputSchema: ['type' => 'object'],
            outputSchema: ['oneOf' => [['type' => 'object'], ['type' => 'array']]],
        );

        self::assertSame(['oneOf' => [['type' => 'object'], ['type' => 'array']]], $tool->outputSchema);
    }

    public function testConstructorValidatesOutputSchemaKnownKeywords(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('tool "outputSchema" "properties" must be an object, string given.');

        new Tool(name: 'read-file', inputSchema: ['type' => 'object'], outputSchema: ['properties' => 'oops']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        Tool::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing name' => [
            [],
            'Tool data missing "name".',
        ];

        yield 'name not a string' => [
            ['name' => 1],
            'Tool "name" must be a non-empty string, int given.',
        ];

        yield 'title not a string' => [
            ['name' => 'read-file', 'title' => 1, 'inputSchema' => ['type' => 'object']],
            'Tool "title" must be a non-empty string or null, int given.',
        ];

        yield 'description not a string' => [
            ['name' => 'read-file', 'description' => 1, 'inputSchema' => ['type' => 'object']],
            'Tool "description" must be a non-empty string or null, int given.',
        ];

        yield 'missing inputSchema' => [
            ['name' => 'read-file'],
            'Tool data missing "inputSchema".',
        ];

        yield 'inputSchema not an object' => [
            ['name' => 'read-file', 'inputSchema' => 'oops'],
            'Tool "inputSchema" must be an object, string given.',
        ];

        yield 'inputSchema list-keyed' => [
            ['name' => 'read-file', 'inputSchema' => ['x']],
            'Tool "inputSchema" must be a string-keyed object.',
        ];

        yield 'outputSchema not an object' => [
            ['name' => 'read-file', 'inputSchema' => ['type' => 'object'], 'outputSchema' => 'oops'],
            'Tool "outputSchema" must be an object, string given.',
        ];

        yield 'annotations not an object' => [
            ['name' => 'read-file', 'inputSchema' => ['type' => 'object'], 'annotations' => 'oops'],
            'Tool "annotations" must be an object, string given.',
        ];

        yield 'icons not an array' => [
            ['name' => 'read-file', 'inputSchema' => ['type' => 'object'], 'icons' => 'oops'],
            'Tool "icons" must be a list, string given.',
        ];

        yield 'icon entry not an object' => [
            ['name' => 'read-file', 'inputSchema' => ['type' => 'object'], 'icons' => ['oops']],
            'Tool icon entry must be an object, string given.',
        ];

        yield '_meta not an object' => [
            ['name' => 'read-file', 'inputSchema' => ['type' => 'object'], '_meta' => 'oops'],
            'Tool "_meta" must be an object, string given.',
        ];
    }

    public function testDisplayNameReturnsTitleWhenSet(): void
    {
        $tool = new Tool(
            name: 'read-file',
            inputSchema: ['type' => 'object'],
            title: 'Read File',
            annotations: new ToolAnnotations(title: 'Reader'),
        );

        self::assertSame('Read File', $tool->getDisplayName());
    }

    public function testDisplayNameFallsBackToAnnotationsTitleWhenTopLevelTitleNull(): void
    {
        $tool = new Tool(
            name: 'read-file',
            inputSchema: ['type' => 'object'],
            annotations: new ToolAnnotations(title: 'Reader'),
        );

        self::assertSame('Reader', $tool->getDisplayName());
    }

    public function testDisplayNameFallsBackToNameWhenAllOtherFieldsNull(): void
    {
        $tool = new Tool(name: 'read-file', inputSchema: ['type' => 'object']);

        self::assertSame('read-file', $tool->getDisplayName());
    }

    public function testJsonSerializeEmitsAnEmptySubSchemaAsAnObject(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ]);

        self::assertSame(
            '{"name":"ping","inputSchema":{"type":"object","properties":{},"required":[]}}',
            json_encode($tool),
        );
    }

    public function testJsonSerializeEmitsAnEmptySubSchemaValueAsAnObject(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            'properties' => ['anything' => []],
            'items' => [],
        ]);

        self::assertSame(
            '{"name":"ping","inputSchema":{"type":"object","properties":{"anything":{}},"items":{}}}',
            json_encode($tool),
        );
    }

    public function testJsonSerializeRecursesThroughEverySubSchemaKeyword(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            '$defs' => ['X' => ['type' => 'object', 'properties' => []]],
            'properties' => ['a' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]]],
            'oneOf' => [['type' => 'object', 'properties' => []]],
        ]);

        self::assertSame(3, substr_count((string) json_encode($tool), '"properties":{}'));
    }

    public function testJsonSerializeEmitsAnEmptyOutputSchemaSlotAsAnObject(): void
    {
        $tool = new Tool(
            name: 'ping',
            inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'object', 'properties' => []],
        );

        self::assertStringContainsString('"outputSchema":{"type":"object","properties":{}}', (string) json_encode($tool));
    }

    public function testJsonSerializeEmitsAWhollyEmptyOutputSchemaAsAnObject(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: ['type' => 'object'], outputSchema: []);

        self::assertSame('{"name":"ping","inputSchema":{"type":"object"},"outputSchema":{}}', json_encode($tool));
    }

    public function testJsonSerializeRecursesThroughContentSchema(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            'properties' => ['a' => ['contentSchema' => ['type' => 'object', 'properties' => []]]],
            'contentSchema' => [],
        ]);

        self::assertSame(2, substr_count((string) json_encode($tool), '"contentSchema":'));
        self::assertStringContainsString('"contentSchema":{"type":"object","properties":{}}', (string) json_encode($tool));
        self::assertStringContainsString('"contentSchema":{}', (string) json_encode($tool));
    }

    public function testJsonSerializeEmitsAnEmptyObjectValuedKeywordAsAnObjectWithoutRecursing(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            'dependentRequired' => [],
            '$vocabulary' => [],
        ]);

        self::assertStringContainsString('"dependentRequired":{},"$vocabulary":{}', (string) json_encode($tool));
    }

    public function testJsonSerializeKeepsDependentRequiredEntriesAsLists(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            'dependentRequired' => ['a' => []],
        ]);

        self::assertStringContainsString('"dependentRequired":{"a":[]}', (string) json_encode($tool));
    }

    public function testJsonSerializeKeepsADraft07TupleItemsAsAList(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            'items' => [['type' => 'string'], ['type' => 'object', 'properties' => []]],
        ]);

        self::assertSame(
            '{"name":"ping","inputSchema":{"type":"object","items":[{"type":"string"},{"type":"object","properties":{}}]}}',
            json_encode($tool),
        );
    }

    public function testJsonSerializeEmitsAPropertyNameThatIsAllDigitsAsAnObject(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            'properties' => ['0' => ['type' => 'string']],
        ]);

        self::assertSame(
            '{"name":"ping","inputSchema":{"type":"object","properties":{"0":{"type":"string"}}}}',
            json_encode($tool),
        );
    }

    public function testJsonSerializePassesABooleanSubSchemaThrough(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['a' => true],
        ]);

        self::assertSame(
            '{"name":"ping","inputSchema":{"type":"object","additionalProperties":false,"properties":{"a":true}}}',
            json_encode($tool),
        );
    }

    public function testJsonSerializeEmitsADigitNamedSingleSubSchemaAsAnObject(): void
    {
        $tool = new Tool(name: 'ping', inputSchema: [
            'type' => 'object',
            'additionalProperties' => ['0' => ['type' => 'string']],
        ]);

        self::assertSame(
            '{"name":"ping","inputSchema":{"type":"object","additionalProperties":{"0":{"type":"string"}}}}',
            json_encode($tool),
        );
    }

    /**
     * @return array{type: 'object', '$schema'?: non-empty-string, properties?: array<string, array<string, mixed>>, required?: list<string>}
     */
    private static function objectSchema(): array
    {
        return [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'path' => ['type' => 'string'],
            ],
            'required' => ['path'],
        ];
    }
}
