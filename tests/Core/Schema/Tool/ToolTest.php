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
use Nexus\Mcp\Core\Schema\Enum\TaskSupport;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Core\Schema\Tool\ToolAnnotations;
use Nexus\Mcp\Core\Schema\Tool\ToolExecution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Tool::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ToolTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $tool = new Tool('read-file', ['type' => 'object']);

        self::assertSame('read-file', $tool->name);
        self::assertNull($tool->title);
        self::assertNull($tool->description);
        self::assertSame(['type' => 'object'], $tool->inputSchema);
        self::assertNull($tool->outputSchema);
        self::assertNull($tool->annotations);
        self::assertNull($tool->execution);
        self::assertNull($tool->icons);
        self::assertSame([], $tool->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $tool = new Tool('read-file', ['type' => 'object']);

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
            execution: new ToolExecution(TaskSupport::Optional),
            icons: [new Icon('https://example.com/icon.png')],
            meta: new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                'name' => 'read-file',
                'inputSchema' => self::objectSchema(),
                'title' => 'Read File',
                'description' => 'Reads contents.',
                'outputSchema' => ['type' => 'object', 'properties' => ['content' => ['type' => 'string']]],
                'annotations' => ['readOnlyHint' => true],
                'execution' => ['taskSupport' => 'optional'],
                'icons' => [['src' => 'https://example.com/icon.png']],
                '_meta' => ['vendor' => 'x'],
            ],
            $tool->toArray(),
        );
    }

    public function testToArrayOmitsEmptyAnnotations(): void
    {
        $tool = new Tool('read-file', ['type' => 'object'], annotations: new ToolAnnotations());

        self::assertSame(
            ['name' => 'read-file', 'inputSchema' => ['type' => 'object']],
            $tool->toArray(),
        );
    }

    public function testToArrayOmitsEmptyExecution(): void
    {
        $tool = new Tool('read-file', ['type' => 'object'], execution: new ToolExecution());

        self::assertSame(
            ['name' => 'read-file', 'inputSchema' => ['type' => 'object']],
            $tool->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $tool = new Tool('read-file', self::objectSchema());

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
            'execution' => ['taskSupport' => 'required'],
            'icons' => [['src' => 'https://example.com/icon.png']],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame('Read File', $tool->title);
        self::assertSame('Reads contents.', $tool->description);
        self::assertSame(['type' => 'object'], $tool->outputSchema);
        self::assertNotNull($tool->annotations);
        self::assertTrue($tool->annotations->readOnlyHint);
        self::assertNotNull($tool->execution);
        self::assertSame(TaskSupport::Required, $tool->execution->taskSupport);
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
            execution: new ToolExecution(TaskSupport::Optional),
            icons: [new Icon('https://example.com/icon.png')],
            meta: new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = Tool::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\ATool name must be 1-128 characters/');

        new Tool('bad name', ['type' => 'object']);
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Tool description must be a non-empty string or null.');

        new Tool('read-file', ['type' => 'object'], description: '');
    }

    public function testConstructorRejectsNonIconEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new Tool('read-file', ['type' => 'object'], icons: [42]);
    }

    public function testConstructorProjectsInputSchemaToCanonicalShape(): void
    {
        $tool = new Tool('read-file', [
            'required' => ['path'],
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
        ]);

        self::assertSame(
            ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            $tool->inputSchema,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('provideConstructorRejectsInvalidSchemaEnvelopeCases')]
    public function testConstructorRejectsInvalidSchemaEnvelope(array $schema, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        new Tool('read-file', $schema);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideConstructorRejectsInvalidSchemaEnvelopeCases(): iterable
    {
        yield 'missing type' => [
            [],
            'Tool inputSchema missing "type".',
        ];

        yield 'type not "object"' => [
            ['type' => 'array'],
            'Tool inputSchema "type" must be "object", \'array\' given.',
        ];

        yield '$schema not non-empty string' => [
            ['type' => 'object', '$schema' => ''],
            'Tool inputSchema "$schema" must be a non-empty string, string given.',
        ];

        yield 'properties not an object' => [
            ['type' => 'object', 'properties' => 'oops'],
            'Tool inputSchema "properties" must be an object, string given.',
        ];

        yield 'properties list-keyed' => [
            ['type' => 'object', 'properties' => ['x']],
            'Tool inputSchema "properties" must be a string-keyed object.',
        ];

        yield 'property entry not an object' => [
            ['type' => 'object', 'properties' => ['name' => 'oops']],
            'Tool inputSchema property entry must be an object, string given.',
        ];

        yield 'required not a list' => [
            ['type' => 'object', 'required' => 'oops'],
            'Tool inputSchema "required" must be a list, got non-list array.',
        ];

        yield 'required entry not a string' => [
            ['type' => 'object', 'required' => [1]],
            'Tool inputSchema "required" entries must be strings, int given.',
        ];
    }

    public function testConstructorRejectsInvalidOutputSchema(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Tool outputSchema "type" must be "object", \'array\' given.');

        new Tool('read-file', ['type' => 'object'], outputSchema: ['type' => 'array']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

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
            'Tool "name" must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['name' => 'read-file', 'title' => 1, 'inputSchema' => ['type' => 'object']],
            'Tool "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['name' => 'read-file', 'description' => 1, 'inputSchema' => ['type' => 'object']],
            'Tool "description" must be a string or null, int given.',
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

        yield 'execution not an object' => [
            ['name' => 'read-file', 'inputSchema' => ['type' => 'object'], 'execution' => 'oops'],
            'Tool "execution" must be an object, string given.',
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
