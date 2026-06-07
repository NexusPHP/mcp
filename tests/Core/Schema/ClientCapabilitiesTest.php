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
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientCapabilities::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ClientCapabilitiesTest extends TestCase
{
    public function testMinimalConstructionAllFieldsNull(): void
    {
        $caps = new ClientCapabilities();

        self::assertNull($caps->elicitation);
        self::assertNull($caps->experimental);
        self::assertNull($caps->roots);
        self::assertNull($caps->sampling);
        self::assertNull($caps->tasks);
    }

    public function testFullConstructionExposesAllFields(): void
    {
        $caps = new ClientCapabilities(
            elicitation: ['form' => [], 'url' => []],
            experimental: ['custom' => ['flag' => true]],
            roots: ['listChanged' => true],
            sampling: ['context' => [], 'tools' => []],
            tasks: [
                'cancel' => [],
                'list' => [],
                'requests' => [
                    'elicitation' => ['create' => []],
                    'sampling' => ['createMessage' => []],
                ],
            ],
        );

        self::assertSame(['form' => [], 'url' => []], $caps->elicitation);
        self::assertSame(['custom' => ['flag' => true]], $caps->experimental);
        self::assertSame(['listChanged' => true], $caps->roots);
        self::assertSame(['context' => [], 'tools' => []], $caps->sampling);
        self::assertSame(
            [
                'cancel' => [],
                'list' => [],
                'requests' => [
                    'elicitation' => ['create' => []],
                    'sampling' => ['createMessage' => []],
                ],
            ],
            $caps->tasks,
        );
    }

    public function testToArrayMinimalIsEmpty(): void
    {
        self::assertSame([], new ClientCapabilities()->toArray());
    }

    public function testToArrayOmitsNullFields(): void
    {
        $caps = new ClientCapabilities(roots: ['listChanged' => false]);

        self::assertSame(['roots' => ['listChanged' => false]], $caps->toArray());
    }

    public function testToArrayFull(): void
    {
        $caps = new ClientCapabilities(
            elicitation: ['form' => []],
            experimental: ['x' => []],
            roots: ['listChanged' => true],
            sampling: ['tools' => []],
            tasks: ['cancel' => []],
        );

        self::assertSame(
            [
                'elicitation' => ['form' => []],
                'experimental' => ['x' => []],
                'roots' => ['listChanged' => true],
                'sampling' => ['tools' => []],
                'tasks' => ['cancel' => []],
            ],
            $caps->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArrayWhenNoEmptyObjectSlots(): void
    {
        $caps = new ClientCapabilities(roots: ['listChanged' => true]);

        self::assertSame($caps->toArray(), $caps->jsonSerialize());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $caps = new ClientCapabilities();

        self::assertInstanceOf(\stdClass::class, $caps->jsonSerialize());
        self::assertSame('{}', json_encode($caps));
    }

    public function testJsonEncodeEmitsEmptyObjectsForEmptyCapabilitySlots(): void
    {
        $caps = new ClientCapabilities(
            elicitation: ['form' => [], 'url' => []],
            experimental: ['ext' => []],
            roots: [],
            sampling: ['context' => [], 'tools' => []],
            tasks: [
                'cancel' => [],
                'list' => [],
                'requests' => [
                    'elicitation' => ['create' => []],
                    'sampling' => ['createMessage' => []],
                ],
            ],
        );

        $json = json_encode($caps);

        self::assertIsString($json);
        self::assertStringContainsString('"roots":{}', $json);
        self::assertStringContainsString('"form":{}', $json);
        self::assertStringContainsString('"url":{}', $json);
        self::assertStringContainsString('"experimental":{"ext":{}}', $json);
        self::assertStringContainsString('"context":{}', $json);
        self::assertStringContainsString('"tools":{}', $json);
        self::assertStringContainsString('"cancel":{}', $json);
        self::assertStringContainsString('"list":{}', $json);
        self::assertStringContainsString('"create":{}', $json);
        self::assertStringContainsString('"createMessage":{}', $json);
        self::assertStringNotContainsString('[]', $json);
    }

    public function testToArrayKeepsPureArraysForEmptyObjectSlots(): void
    {
        $caps = new ClientCapabilities(roots: [], sampling: []);

        self::assertSame(['roots' => [], 'sampling' => []], $caps->toArray());
    }

    public function testFromArrayEmptyIsAllNull(): void
    {
        $caps = ClientCapabilities::fromArray([]);

        self::assertNull($caps->elicitation);
        self::assertNull($caps->experimental);
        self::assertNull($caps->roots);
        self::assertNull($caps->sampling);
        self::assertNull($caps->tasks);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ClientCapabilities(
            elicitation: ['form' => ['custom' => 1], 'url' => []],
            experimental: ['ext' => ['nested' => 'value']],
            roots: ['listChanged' => true],
            sampling: ['context' => [], 'tools' => ['x' => 'y']],
            tasks: [
                'cancel' => [],
                'list' => [],
                'requests' => [
                    'elicitation' => ['create' => []],
                    'sampling' => ['createMessage' => []],
                ],
            ],
        );

        $rebuilt = ClientCapabilities::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayPreservesEmptyStructuredFields(): void
    {
        $caps = ClientCapabilities::fromArray([
            'elicitation' => [],
            'roots' => [],
            'sampling' => [],
            'tasks' => [],
        ]);

        self::assertSame([], $caps->elicitation);
        self::assertSame([], $caps->roots);
        self::assertSame([], $caps->sampling);
        self::assertSame([], $caps->tasks);
    }

    public function testFromArrayIgnoresUnknownNestedKeys(): void
    {
        $caps = ClientCapabilities::fromArray([
            'roots' => ['listChanged' => true, 'extra' => 'ignored'],
        ]);

        self::assertSame(['listChanged' => true], $caps->roots);
    }

    public function testFromArrayBoolListChangedRoundTrips(): void
    {
        $caps = ClientCapabilities::fromArray(['roots' => ['listChanged' => false]]);

        self::assertSame(['listChanged' => false], $caps->roots);
    }

    public function testFromArrayTasksRequestsWithoutInnerKeysIsEmpty(): void
    {
        $caps = ClientCapabilities::fromArray(['tasks' => ['requests' => []]]);

        self::assertSame(['requests' => []], $caps->tasks);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ClientCapabilities::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'elicitation not an object' => [
            ['elicitation' => 'oops'],
            '"capabilities.elicitation" must be an object, string given.',
        ];

        yield 'elicitation list-keyed' => [
            ['elicitation' => ['list-entry']],
            '"capabilities.elicitation" must be a string-keyed object.',
        ];

        yield 'elicitation.form not an object' => [
            ['elicitation' => ['form' => 'oops']],
            '"capabilities.elicitation.form" must be an object, string given.',
        ];

        yield 'elicitation.url not an object' => [
            ['elicitation' => ['url' => 1]],
            '"capabilities.elicitation.url" must be an object, int given.',
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

        yield 'roots not an object' => [
            ['roots' => 'oops'],
            '"capabilities.roots" must be an object, string given.',
        ];

        yield 'roots list-keyed' => [
            ['roots' => ['x']],
            '"capabilities.roots" must be a string-keyed object.',
        ];

        yield 'roots.listChanged not a boolean' => [
            ['roots' => ['listChanged' => 'true']],
            '"capabilities.roots.listChanged" must be a boolean, string given.',
        ];

        yield 'sampling not an object' => [
            ['sampling' => 1],
            '"capabilities.sampling" must be an object, int given.',
        ];

        yield 'sampling.context not an object' => [
            ['sampling' => ['context' => 'oops']],
            '"capabilities.sampling.context" must be an object, string given.',
        ];

        yield 'sampling.tools not an object' => [
            ['sampling' => ['tools' => 'oops']],
            '"capabilities.sampling.tools" must be an object, string given.',
        ];

        yield 'tasks not an object' => [
            ['tasks' => 'oops'],
            '"capabilities.tasks" must be an object, string given.',
        ];

        yield 'tasks.cancel not an object' => [
            ['tasks' => ['cancel' => 'oops']],
            '"capabilities.tasks.cancel" must be an object, string given.',
        ];

        yield 'tasks.list not an object' => [
            ['tasks' => ['list' => 'oops']],
            '"capabilities.tasks.list" must be an object, string given.',
        ];

        yield 'tasks.requests not an object' => [
            ['tasks' => ['requests' => 'oops']],
            '"capabilities.tasks.requests" must be an object, string given.',
        ];

        yield 'tasks.requests.elicitation not an object' => [
            ['tasks' => ['requests' => ['elicitation' => 'oops']]],
            '"capabilities.tasks.requests.elicitation" must be an object, string given.',
        ];

        yield 'tasks.requests.elicitation.create not an object' => [
            ['tasks' => ['requests' => ['elicitation' => ['create' => 'oops']]]],
            '"capabilities.tasks.requests.elicitation.create" must be an object, string given.',
        ];

        yield 'tasks.requests.sampling not an object' => [
            ['tasks' => ['requests' => ['sampling' => 'oops']]],
            '"capabilities.tasks.requests.sampling" must be an object, string given.',
        ];

        yield 'tasks.requests.sampling.createMessage not an object' => [
            ['tasks' => ['requests' => ['sampling' => ['createMessage' => 'oops']]]],
            '"capabilities.tasks.requests.sampling.createMessage" must be an object, string given.',
        ];
    }
}
