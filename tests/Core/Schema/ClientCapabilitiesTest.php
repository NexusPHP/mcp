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

use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ClientCapabilities::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ClientCapabilitiesTest extends AbstractMcpTestCase
{
    public function testMinimalConstructionAllFieldsNull(): void
    {
        $caps = new ClientCapabilities();

        self::assertNull($caps->elicitation);
        self::assertNull($caps->experimental);
        self::assertNull($caps->extensions);
    }

    public function testFullConstructionExposesAllFields(): void
    {
        $caps = new ClientCapabilities(
            elicitation: ['form' => [], 'url' => []],
            experimental: ['custom' => ['flag' => true]],
            extensions: ['io.modelcontextprotocol/oauth-client-credentials' => ['scope' => 'read']],
        );

        self::assertSame(['form' => [], 'url' => []], $caps->elicitation);
        self::assertSame(['custom' => ['flag' => true]], $caps->experimental);
        self::assertSame(['io.modelcontextprotocol/oauth-client-credentials' => ['scope' => 'read']], $caps->extensions);
    }

    public function testToArrayMinimalIsEmpty(): void
    {
        self::assertSame([], (new ClientCapabilities())->toArray());
    }

    public function testToArrayOmitsNullFields(): void
    {
        $caps = new ClientCapabilities(experimental: ['acme' => ['enabled' => false]]);

        self::assertSame(['experimental' => ['acme' => ['enabled' => false]]], $caps->toArray());
    }

    public function testToArrayFull(): void
    {
        $caps = new ClientCapabilities(
            elicitation: ['form' => []],
            experimental: ['x' => []],
            extensions: ['io.example/ext' => []],
        );

        self::assertSame(
            [
                'elicitation' => ['form' => []],
                'experimental' => ['x' => []],
                'extensions' => ['io.example/ext' => []],
            ],
            $caps->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArrayWhenNoEmptyObjectSlots(): void
    {
        $caps = new ClientCapabilities(experimental: ['acme' => ['enabled' => true]]);

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
            extensions: ['acme.ext' => []],
        );

        $json = json_encode($caps);

        self::assertIsString($json);
        self::assertStringContainsString('"form":{}', $json);
        self::assertStringContainsString('"url":{}', $json);
        self::assertStringContainsString('"experimental":{"ext":{}}', $json);
        self::assertStringContainsString('"extensions":{"acme.ext":{}}', $json);
        self::assertStringNotContainsString('[]', $json);
    }

    public function testToArrayKeepsPureArraysForEmptyObjectSlots(): void
    {
        $caps = new ClientCapabilities(elicitation: []);

        self::assertSame(['elicitation' => []], $caps->toArray());
    }

    public function testFromArrayEmptyIsAllNull(): void
    {
        $caps = ClientCapabilities::fromArray([]);

        self::assertNull($caps->elicitation);
        self::assertNull($caps->experimental);
        self::assertNull($caps->extensions);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ClientCapabilities(
            elicitation: ['form' => ['custom' => 1], 'url' => []],
            experimental: ['ext' => ['nested' => 'value']],
            extensions: ['io.example/ext' => ['nested' => 'value']],
        );

        $rebuilt = ClientCapabilities::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayPreservesEmptyStructuredFields(): void
    {
        $caps = ClientCapabilities::fromArray([
            'elicitation' => [],
            'extensions' => [],
        ]);

        self::assertSame([], $caps->elicitation);
        self::assertSame([], $caps->extensions);
    }

    public function testFromArrayIgnoresUnknownNestedKeys(): void
    {
        $caps = ClientCapabilities::fromArray([
            'elicitation' => ['form' => [], 'extra' => 'ignored'],
        ]);

        self::assertSame(['form' => []], $caps->elicitation);
    }

    public function testFromArrayExtensionsPreservesSettings(): void
    {
        $caps = ClientCapabilities::fromArray(['extensions' => ['io.example/ext' => ['setting' => 'value']]]);

        self::assertSame(['io.example/ext' => ['setting' => 'value']], $caps->extensions);
    }

    public function testFromArrayKeepsCapabilitiesOutsideTheNamedSet(): void
    {
        // The spec: "this is not a closed set: any client can define its own, additional capabilities."
        $caps = ClientCapabilities::fromArray([
            'elicitation' => [],
            'sampling' => ['tools' => []],
            'roots' => [],
        ]);

        self::assertSame(['sampling' => ['tools' => []], 'roots' => []], $caps->extras);
        self::assertSame([], $caps->elicitation);
    }

    public function testExtrasSurviveTheRoundTripInDeclarationOrder(): void
    {
        $caps = new ClientCapabilities(elicitation: [], extras: ['sampling' => []]);

        self::assertSame(['elicitation' => [], 'sampling' => []], $caps->toArray());
        self::assertSame($caps->toArray(), ClientCapabilities::fromArray($caps->toArray())->toArray());
    }

    public function testAnExtraNeverOverwritesANamedCapability(): void
    {
        $caps = new ClientCapabilities(elicitation: ['form' => []], extras: ['elicitation' => 'clobbered']);

        self::assertSame(['elicitation' => ['form' => []]], $caps->toArray());
    }

    public function testJsonEncodeEmitsAnEmptyExtraAsAnObject(): void
    {
        $caps = new ClientCapabilities(extras: ['sampling' => []]);

        self::assertSame('{"sampling":{}}', json_encode($caps, \JSON_THROW_ON_ERROR));
    }

    public function testJsonEncodeKeepsAnEmptyListNestedInAVendorCapability(): void
    {
        $caps = ClientCapabilities::fromArray(['com.example/vendor' => ['modes' => []]]);

        self::assertSame('{"com.example\/vendor":{"modes":[]}}', json_encode($caps, \JSON_THROW_ON_ERROR));
    }

    public function testJsonEncodeNormalisesEverySlotAfterAnEmptyOne(): void
    {
        $caps = new ClientCapabilities(experimental: [], extras: ['sampling' => []]);

        self::assertSame('{"experimental":{},"sampling":{}}', json_encode($caps, \JSON_THROW_ON_ERROR));
    }

    public function testJsonEncodeNormalisesEverySlotAfterAScalarOne(): void
    {
        $caps = new ClientCapabilities(extras: ['flag' => true, 'sampling' => []]);

        self::assertSame('{"flag":true,"sampling":{}}', json_encode($caps, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
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
    }
}
