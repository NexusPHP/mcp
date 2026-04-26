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
        self::assertNull($caps->logging);
        self::assertNull($caps->prompts);
        self::assertNull($caps->resources);
        self::assertNull($caps->tasks);
        self::assertNull($caps->tools);
    }

    public function testFullConstructionExposesAllFields(): void
    {
        $caps = new ServerCapabilities(
            completions: ['custom' => 'value'],
            experimental: ['ext' => ['nested' => 'value']],
            logging: ['anything' => 'goes'],
            prompts: ['listChanged' => true],
            resources: ['listChanged' => true, 'subscribe' => false],
            tasks: [
                'cancel' => [],
                'list' => [],
                'requests' => ['tools' => ['call' => []]],
            ],
            tools: ['listChanged' => true],
        );

        self::assertSame(['custom' => 'value'], $caps->completions);
        self::assertSame(['ext' => ['nested' => 'value']], $caps->experimental);
        self::assertSame(['anything' => 'goes'], $caps->logging);
        self::assertSame(['listChanged' => true], $caps->prompts);
        self::assertSame(['listChanged' => true, 'subscribe' => false], $caps->resources);
        self::assertSame(
            [
                'cancel' => [],
                'list' => [],
                'requests' => ['tools' => ['call' => []]],
            ],
            $caps->tasks,
        );
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
            logging: ['l' => 1],
            prompts: ['listChanged' => true],
            resources: ['subscribe' => true],
            tasks: ['cancel' => []],
            tools: ['listChanged' => false],
        );

        self::assertSame(
            [
                'completions' => ['c' => 1],
                'experimental' => ['x' => []],
                'logging' => ['l' => 1],
                'prompts' => ['listChanged' => true],
                'resources' => ['subscribe' => true],
                'tasks' => ['cancel' => []],
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

    public function testJsonEncodeEmitsEmptyObjectsForEmptyCapabilitySlots(): void
    {
        $caps = new ServerCapabilities(
            completions: [],
            experimental: ['ext' => []],
            logging: [],
            prompts: [],
            resources: [],
            tasks: [
                'cancel' => [],
                'list' => [],
                'requests' => ['tools' => ['call' => []]],
            ],
            tools: [],
        );

        $json = json_encode($caps);

        self::assertIsString($json);
        self::assertStringContainsString('"completions":{}', $json);
        self::assertStringContainsString('"experimental":{"ext":{}}', $json);
        self::assertStringContainsString('"logging":{}', $json);
        self::assertStringContainsString('"prompts":{}', $json);
        self::assertStringContainsString('"resources":{}', $json);
        self::assertStringContainsString('"cancel":{}', $json);
        self::assertStringContainsString('"list":{}', $json);
        self::assertStringContainsString('"call":{}', $json);
        self::assertStringContainsString('"tools":{}', $json);
        self::assertStringNotContainsString('[]', $json);
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
        self::assertNull($caps->logging);
        self::assertNull($caps->prompts);
        self::assertNull($caps->resources);
        self::assertNull($caps->tasks);
        self::assertNull($caps->tools);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ServerCapabilities(
            completions: ['custom' => 'value'],
            experimental: ['ext' => ['nested' => 'value']],
            logging: ['anything' => 'goes'],
            prompts: ['listChanged' => true],
            resources: ['listChanged' => true, 'subscribe' => false],
            tasks: [
                'cancel' => [],
                'list' => [],
                'requests' => ['tools' => ['call' => []]],
            ],
            tools: ['listChanged' => true],
        );

        $rebuilt = ServerCapabilities::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayPreservesEmptyStructuredFields(): void
    {
        $caps = ServerCapabilities::fromArray([
            'completions' => [],
            'logging' => [],
            'prompts' => [],
            'resources' => [],
            'tasks' => [],
            'tools' => [],
        ]);

        self::assertSame([], $caps->completions);
        self::assertSame([], $caps->logging);
        self::assertSame([], $caps->prompts);
        self::assertSame([], $caps->resources);
        self::assertSame([], $caps->tasks);
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

    public function testFromArrayTasksRequestsWithoutInnerKeysIsEmpty(): void
    {
        $caps = ServerCapabilities::fromArray(['tasks' => ['requests' => []]]);

        self::assertSame(['requests' => []], $caps->tasks);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ServerCapabilities::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'completions not an object' => [
            ['completions' => 'oops'],
            'ServerCapabilities wire "completions" must be an object, string given.',
        ];

        yield 'completions list-keyed' => [
            ['completions' => ['x']],
            'ServerCapabilities wire "completions" must be a string-keyed object.',
        ];

        yield 'experimental not an object' => [
            ['experimental' => 'oops'],
            'ServerCapabilities wire "experimental" must be an object, string given.',
        ];

        yield 'experimental list-keyed' => [
            ['experimental' => ['x']],
            'ServerCapabilities wire "experimental" must be a string-keyed object.',
        ];

        yield 'experimental value not an object' => [
            ['experimental' => ['ext' => 'oops']],
            'ServerCapabilities wire "experimental.ext" must be an object, string given.',
        ];

        yield 'experimental value list-keyed' => [
            ['experimental' => ['ext' => ['x']]],
            'ServerCapabilities wire "experimental.ext" must be a string-keyed object.',
        ];

        yield 'logging not an object' => [
            ['logging' => 1],
            'ServerCapabilities wire "logging" must be an object, int given.',
        ];

        yield 'prompts not an object' => [
            ['prompts' => 'oops'],
            'ServerCapabilities wire "prompts" must be an object, string given.',
        ];

        yield 'prompts list-keyed' => [
            ['prompts' => ['x']],
            'ServerCapabilities wire "prompts" must be a string-keyed object.',
        ];

        yield 'prompts.listChanged not a boolean' => [
            ['prompts' => ['listChanged' => 'true']],
            'ServerCapabilities wire "prompts.listChanged" must be a boolean, string given.',
        ];

        yield 'resources not an object' => [
            ['resources' => 'oops'],
            'ServerCapabilities wire "resources" must be an object, string given.',
        ];

        yield 'resources.listChanged not a boolean' => [
            ['resources' => ['listChanged' => 1]],
            'ServerCapabilities wire "resources.listChanged" must be a boolean, int given.',
        ];

        yield 'resources.subscribe not a boolean' => [
            ['resources' => ['subscribe' => 'yes']],
            'ServerCapabilities wire "resources.subscribe" must be a boolean, string given.',
        ];

        yield 'tasks not an object' => [
            ['tasks' => 'oops'],
            'ServerCapabilities wire "tasks" must be an object, string given.',
        ];

        yield 'tasks.cancel not an object' => [
            ['tasks' => ['cancel' => 'oops']],
            'ServerCapabilities wire "tasks.cancel" must be an object, string given.',
        ];

        yield 'tasks.list not an object' => [
            ['tasks' => ['list' => 'oops']],
            'ServerCapabilities wire "tasks.list" must be an object, string given.',
        ];

        yield 'tasks.requests not an object' => [
            ['tasks' => ['requests' => 'oops']],
            'ServerCapabilities wire "tasks.requests" must be an object, string given.',
        ];

        yield 'tasks.requests.tools not an object' => [
            ['tasks' => ['requests' => ['tools' => 'oops']]],
            'ServerCapabilities wire "tasks.requests.tools" must be an object, string given.',
        ];

        yield 'tasks.requests.tools.call not an object' => [
            ['tasks' => ['requests' => ['tools' => ['call' => 'oops']]]],
            'ServerCapabilities wire "tasks.requests.tools.call" must be an object, string given.',
        ];

        yield 'tools not an object' => [
            ['tools' => 'oops'],
            'ServerCapabilities wire "tools" must be an object, string given.',
        ];

        yield 'tools.listChanged not a boolean' => [
            ['tools' => ['listChanged' => 'true']],
            'ServerCapabilities wire "tools.listChanged" must be a boolean, string given.',
        ];
    }
}
