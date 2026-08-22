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

namespace Nexus\Mcp\Tests\Core\Http;

use Nexus\Mcp\Core\Http\ParameterHeaderBinding;
use Nexus\Mcp\Core\Http\ParameterHeaderScanner;
use Nexus\Mcp\Core\Http\ParameterHeaderScanResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ParameterHeaderScanner::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ParameterHeaderScannerTest extends AbstractMcpTestCase
{
    /**
     * @param array<string, mixed>                      $schema
     * @param list<array{list<string>, string, string}> $expected
     */
    #[DataProvider('provideScanCollectsBindingsCases')]
    public function testScanCollectsBindings(array $schema, array $expected): void
    {
        $result = ParameterHeaderScanner::scan($schema);

        self::assertTrue($result->valid);
        self::assertNull($result->reason);
        self::assertSame($expected, $this->toTuples($result));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array{list<string>, string, string}>}>
     */
    public static function provideScanCollectsBindingsCases(): iterable
    {
        yield 'empty schema' => [[], []];

        yield 'schema without any binding' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            [],
        ];

        yield 'a non-array property value is ignored' => [
            ['properties' => ['x' => 'not-a-schema']],
            [],
        ];

        yield 'top-level string property' => [
            ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]],
            [[['region'], 'Region', 'string']],
        ];

        yield 'integer property' => [
            ['properties' => ['count' => ['type' => 'integer', 'x-mcp-header' => 'Count']]],
            [[['count'], 'Count', 'integer']],
        ];

        yield 'boolean property' => [
            ['properties' => ['flag' => ['type' => 'boolean', 'x-mcp-header' => 'Flag']]],
            [[['flag'], 'Flag', 'boolean']],
        ];

        yield 'nested property under a properties chain' => [
            ['properties' => ['a' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string', 'x-mcp-header' => 'B']]]]],
            [[['a', 'b'], 'B', 'string']],
        ];

        yield 'multiple unique bindings' => [
            ['properties' => [
                'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                'zone' => ['type' => 'string', 'x-mcp-header' => 'Zone'],
            ]],
            [[['region'], 'Region', 'string'], [['zone'], 'Zone', 'string']],
        ];

        yield 'a property literally named items is reachable via properties' => [
            ['properties' => ['items' => ['type' => 'string', 'x-mcp-header' => 'Items']]],
            [[['items'], 'Items', 'string']],
        ];

        yield 'additionalProperties false does not disturb a valid property' => [
            ['properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']], 'additionalProperties' => false],
            [[['region'], 'Region', 'string']],
        ];

        yield 'header name with allowed token punctuation' => [
            ['properties' => ['x' => ['type' => 'string', 'x-mcp-header' => 'X-Custom_Header.1~test']]],
            [[['x'], 'X-Custom_Header.1~test', 'string']],
        ];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('provideScanRejectsSchemaCases')]
    public function testScanRejectsSchema(array $schema): void
    {
        $result = ParameterHeaderScanner::scan($schema);

        self::assertFalse($result->valid);
        self::assertSame([], $result->bindings);
        self::assertNotNull($result->reason);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideScanRejectsSchemaCases(): iterable
    {
        yield 'binding at the schema root' => [
            ['type' => 'object', 'x-mcp-header' => 'Root'],
        ];

        yield 'number type is not permitted' => [
            ['properties' => ['n' => ['type' => 'number', 'x-mcp-header' => 'N']]],
        ];

        yield 'absent type' => [
            ['properties' => ['x' => ['x-mcp-header' => 'X']]],
        ];

        yield 'empty header name' => [
            ['properties' => ['x' => ['type' => 'string', 'x-mcp-header' => '']]],
        ];

        yield 'non-string header name' => [
            ['properties' => ['x' => ['type' => 'string', 'x-mcp-header' => true]]],
        ];

        yield 'header name with a space is not a token' => [
            ['properties' => ['x' => ['type' => 'string', 'x-mcp-header' => 'my header']]],
        ];

        yield 'header name with a delimiter is not a token' => [
            ['properties' => ['x' => ['type' => 'string', 'x-mcp-header' => 'a:b']]],
        ];

        yield 'case-insensitive duplicate header names' => [
            ['properties' => [
                'a' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                'b' => ['type' => 'string', 'x-mcp-header' => 'region'],
            ]],
        ];

        yield 'binding under items as a single subschema' => [
            ['properties' => ['list' => ['type' => 'array', 'items' => ['type' => 'string', 'x-mcp-header' => 'Item']]]],
        ];

        yield 'binding under items as a tuple' => [
            ['items' => [['type' => 'string', 'x-mcp-header' => 'Item']]],
        ];

        yield 'binding under oneOf in a later branch' => [
            ['oneOf' => [['type' => 'string'], ['type' => 'string', 'x-mcp-header' => 'One']]],
        ];

        yield 'binding under not' => [
            ['not' => ['properties' => ['x' => ['type' => 'string', 'x-mcp-header' => 'X']]]],
        ];

        yield 'binding under additionalProperties subschema' => [
            ['additionalProperties' => ['type' => 'string', 'x-mcp-header' => 'X']],
        ];

        yield 'binding under patternProperties' => [
            ['patternProperties' => ['^x' => ['type' => 'string', 'x-mcp-header' => 'X']]],
        ];

        yield 'binding under $defs' => [
            ['$defs' => ['D' => ['type' => 'string', 'x-mcp-header' => 'X']]],
        ];

        yield 'binding reachable then passing through oneOf' => [
            ['properties' => ['a' => ['oneOf' => [['type' => 'string', 'x-mcp-header' => 'X']]]]],
        ];
    }

    public function testRejectionReasonNamesANestedLocation(): void
    {
        $result = ParameterHeaderScanner::scan([
            'properties' => [
                'a' => ['properties' => ['b' => ['type' => 'number', 'x-mcp-header' => 'B']]],
            ],
        ]);

        self::assertFalse($result->valid);
        self::assertNotNull($result->reason);
        self::assertStringContainsString('a.b:', $result->reason);
    }

    public function testRejectionReasonNamesTheRoot(): void
    {
        $result = ParameterHeaderScanner::scan(['x-mcp-header' => 'Root']);

        self::assertFalse($result->valid);
        self::assertNotNull($result->reason);
        self::assertStringContainsString('<root>:', $result->reason);
    }

    public function testRejectionReasonNamesANonReachableLocationWithItsParentPath(): void
    {
        $result = ParameterHeaderScanner::scan([
            'properties' => ['a' => ['oneOf' => [['type' => 'string', 'x-mcp-header' => 'X']]]],
        ]);

        self::assertFalse($result->valid);
        self::assertNotNull($result->reason);
        self::assertStringContainsString('a.<oneOf>:', $result->reason);
    }

    /**
     * @return list<array{list<string>, string, string}>
     */
    private function toTuples(ParameterHeaderScanResult $result): array
    {
        return array_map(
            static fn(ParameterHeaderBinding $binding): array => [$binding->path, $binding->headerName, $binding->type],
            $result->bindings,
        );
    }
}
