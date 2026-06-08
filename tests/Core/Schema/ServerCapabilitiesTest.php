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
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ServerCapabilities::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ServerCapabilitiesTest extends TestCase
{
    public function testMinimalConstructionAllFieldsNull(): void
    {
        $caps = new ServerCapabilities();

        self::assertNull($caps->completions);
        self::assertNull($caps->experimental);
        self::assertNull($caps->extensions);
        self::assertNull($caps->logging);
        self::assertNull($caps->prompts);
        self::assertNull($caps->resources);
        self::assertNull($caps->tools);
    }

    public function testFullConstructionExposesAllFields(): void
    {
        $caps = new ServerCapabilities(
            completions: ['custom' => 'value'],
            experimental: ['ext' => ['nested' => 'value']],
            extensions: ['io.modelcontextprotocol/tasks' => ['version' => '1']],
            logging: ['anything' => 'goes'],
            prompts: ['listChanged' => true],
            resources: ['listChanged' => true, 'subscribe' => false],
            tools: ['listChanged' => true],
        );

        self::assertSame(['custom' => 'value'], $caps->completions);
        self::assertSame(['ext' => ['nested' => 'value']], $caps->experimental);
        self::assertSame(['io.modelcontextprotocol/tasks' => ['version' => '1']], $caps->extensions);
        self::assertSame(['anything' => 'goes'], $caps->logging);
        self::assertSame(['listChanged' => true], $caps->prompts);
        self::assertSame(['listChanged' => true, 'subscribe' => false], $caps->resources);
        self::assertSame(['listChanged' => true], $caps->tools);
    }

    public function testToArrayMinimalIsEmpty(): void
    {
        self::assertSame([], new ServerCapabilities()->toArray());
    }

    public function testToArrayOmitsNullFields(): void
    {
        $caps = new ServerCapabilities(prompts: ['listChanged' => false]);

        self::assertSame(['prompts' => ['listChanged' => false]], $caps->toArray());
    }

    public function testToArrayFull(): void
    {
        $caps = new ServerCapabilities(
            completions: ['c' => 1],
            experimental: ['x' => []],
            extensions: ['io.example/ext' => []],
            logging: ['l' => 1],
            prompts: ['listChanged' => true],
            resources: ['subscribe' => true],
            tools: ['listChanged' => false],
        );

        self::assertSame(
            [
                'completions' => ['c' => 1],
                'experimental' => ['x' => []],
                'extensions' => ['io.example/ext' => []],
                'logging' => ['l' => 1],
                'prompts' => ['listChanged' => true],
                'resources' => ['subscribe' => true],
                'tools' => ['listChanged' => false],
            ],
            $caps->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArrayWhenNoEmptyObjectSlots(): void
    {
        $caps = new ServerCapabilities(prompts: ['listChanged' => true]);

        self::assertSame($caps->toArray(), $caps->jsonSerialize());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $caps = new ServerCapabilities();

        self::assertInstanceOf(\stdClass::class, $caps->jsonSerialize());
        self::assertSame('{}', json_encode($caps));
    }

    public function testJsonEncodeEmitsEmptyObjectsForEmptyCapabilitySlots(): void
    {
        $caps = new ServerCapabilities(
            completions: [],
            experimental: ['ext' => []],
            extensions: ['acme.ext' => []],
            logging: [],
            prompts: [],
            resources: [],
            tools: [],
        );

        $json = json_encode($caps);

        self::assertIsString($json);
        self::assertStringContainsString('"completions":{}', $json);
        self::assertStringContainsString('"experimental":{"ext":{}}', $json);
        self::assertStringContainsString('"extensions":{"acme.ext":{}}', $json);
        self::assertStringContainsString('"logging":{}', $json);
        self::assertStringContainsString('"prompts":{}', $json);
        self::assertStringContainsString('"resources":{}', $json);
        self::assertStringContainsString('"tools":{}', $json);
        self::assertStringNotContainsString('[]', $json);
    }

    public function testJsonEncodePreservesEveryKeyInAMultiKeyCapabilitySlot(): void
    {
        $caps = new ServerCapabilities(resources: ['subscribe' => true, 'listChanged' => false]);

        self::assertSame('{"resources":{"subscribe":true,"listChanged":false}}', json_encode($caps));
    }

    public function testToArrayKeepsPureArraysForEmptyObjectSlots(): void
    {
        $caps = new ServerCapabilities(prompts: [], resources: []);

        self::assertSame(['prompts' => [], 'resources' => []], $caps->toArray());
    }

    public function testFromArrayEmptyIsAllNull(): void
    {
        $caps = ServerCapabilities::fromArray([]);

        self::assertNull($caps->completions);
        self::assertNull($caps->experimental);
        self::assertNull($caps->extensions);
        self::assertNull($caps->logging);
        self::assertNull($caps->prompts);
        self::assertNull($caps->resources);
        self::assertNull($caps->tools);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ServerCapabilities(
            completions: ['custom' => 'value'],
            experimental: ['ext' => ['nested' => 'value']],
            extensions: ['io.example/ext' => ['nested' => 'value']],
            logging: ['anything' => 'goes'],
            prompts: ['listChanged' => true],
            resources: ['listChanged' => true, 'subscribe' => false],
            tools: ['listChanged' => true],
        );

        $rebuilt = ServerCapabilities::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayPreservesEmptyStructuredFields(): void
    {
        $caps = ServerCapabilities::fromArray([
            'completions' => [],
            'extensions' => [],
            'logging' => [],
            'prompts' => [],
            'resources' => [],
            'tools' => [],
        ]);

        self::assertSame([], $caps->completions);
        self::assertSame([], $caps->extensions);
        self::assertSame([], $caps->logging);
        self::assertSame([], $caps->prompts);
        self::assertSame([], $caps->resources);
        self::assertSame([], $caps->tools);
    }

    public function testFromArrayIgnoresUnknownNestedKeys(): void
    {
        $caps = ServerCapabilities::fromArray([
            'prompts' => ['listChanged' => true, 'extra' => 'ignored'],
            'resources' => ['subscribe' => true, 'unknown' => 1],
        ]);

        self::assertSame(['listChanged' => true], $caps->prompts);
        self::assertSame(['subscribe' => true], $caps->resources);
    }

    public function testFromArrayResourcesPartialFields(): void
    {
        $caps = ServerCapabilities::fromArray(['resources' => ['listChanged' => false]]);

        self::assertSame(['listChanged' => false], $caps->resources);
    }

    public function testFromArrayExtensionsPreservesSettings(): void
    {
        $caps = ServerCapabilities::fromArray(['extensions' => ['io.example/ext' => ['setting' => 'value']]]);

        self::assertSame(['io.example/ext' => ['setting' => 'value']], $caps->extensions);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ServerCapabilities::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'completions not an object' => [
            ['completions' => 'oops'],
            '"capabilities.completions" must be an object, string given.',
        ];

        yield 'completions list-keyed' => [
            ['completions' => ['x']],
            '"capabilities.completions" must be a string-keyed object.',
        ];

        yield 'experimental not an object' => [
            ['experimental' => 'oops'],
            '"capabilities.experimental" must be an object, string given.',
        ];

        yield 'experimental list-keyed' => [
            ['experimental' => ['x']],
            '"capabilities.experimental" must be a string-keyed object.',
        ];

        yield 'experimental value not an object' => [
            ['experimental' => ['ext' => 'oops']],
            '"capabilities.experimental.ext" must be an object, string given.',
        ];

        yield 'experimental value list-keyed' => [
            ['experimental' => ['ext' => ['x']]],
            '"capabilities.experimental.ext" must be a string-keyed object.',
        ];

        yield 'logging not an object' => [
            ['logging' => 1],
            '"capabilities.logging" must be an object, int given.',
        ];

        yield 'prompts not an object' => [
            ['prompts' => 'oops'],
            '"capabilities.prompts" must be an object, string given.',
        ];

        yield 'prompts list-keyed' => [
            ['prompts' => ['x']],
            '"capabilities.prompts" must be a string-keyed object.',
        ];

        yield 'prompts.listChanged not a boolean' => [
            ['prompts' => ['listChanged' => 'true']],
            '"capabilities.prompts.listChanged" must be a boolean, string given.',
        ];

        yield 'resources not an object' => [
            ['resources' => 'oops'],
            '"capabilities.resources" must be an object, string given.',
        ];

        yield 'resources.listChanged not a boolean' => [
            ['resources' => ['listChanged' => 1]],
            '"capabilities.resources.listChanged" must be a boolean, int given.',
        ];

        yield 'resources.subscribe not a boolean' => [
            ['resources' => ['subscribe' => 'yes']],
            '"capabilities.resources.subscribe" must be a boolean, string given.',
        ];

        yield 'extensions not an object' => [
            ['extensions' => 'oops'],
            '"capabilities.extensions" must be an object, string given.',
        ];

        yield 'extensions list-keyed' => [
            ['extensions' => ['x']],
            '"capabilities.extensions" must be a string-keyed object.',
        ];

        yield 'extensions value not an object' => [
            ['extensions' => ['ext' => 'oops']],
            '"capabilities.extensions.ext" must be an object, string given.',
        ];

        yield 'extensions value list-keyed' => [
            ['extensions' => ['ext' => ['x']]],
            '"capabilities.extensions.ext" must be a string-keyed object.',
        ];

        yield 'tools not an object' => [
            ['tools' => 'oops'],
            '"capabilities.tools" must be an object, string given.',
        ];

        yield 'tools.listChanged not a boolean' => [
            ['tools' => ['listChanged' => 'true']],
            '"capabilities.tools.listChanged" must be a boolean, string given.',
        ];
    }
}
