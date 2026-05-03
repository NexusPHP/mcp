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
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\InitializeRequestParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InitializeRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InitializeRequestParamsTest extends TestCase
{
    public function testConstructionExposesFields(): void
    {
        $params = new InitializeRequestParams(
            new ProtocolVersion('2025-11-25'),
            new ClientCapabilities(roots: ['listChanged' => true]),
            new Implementation('client', '1.0.0'),
        );

        self::assertSame('2025-11-25', $params->protocolVersion->version);
        self::assertSame(['listChanged' => true], $params->capabilities->roots);
        self::assertSame('client', $params->clientInfo->name);
        self::assertNull($params->meta);
    }

    public function testConstructionAcceptsMeta(): void
    {
        $params = new InitializeRequestParams(
            new ProtocolVersion('2025-11-25'),
            new ClientCapabilities(),
            new Implementation('client', '1.0.0'),
            new RequestMeta(null, ['vendor' => 'x']),
        );

        self::assertNotNull($params->meta);
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testToArrayEmitsRequiredFields(): void
    {
        $params = new InitializeRequestParams(
            new ProtocolVersion('2025-11-25'),
            new ClientCapabilities(roots: ['listChanged' => true]),
            new Implementation('client', '1.0.0', title: 'My Client'),
        );

        self::assertSame(
            [
                'protocolVersion' => '2025-11-25',
                'capabilities' => ['roots' => ['listChanged' => true]],
                'clientInfo' => ['name' => 'client', 'version' => '1.0.0', 'title' => 'My Client'],
            ],
            $params->toArray(),
        );
    }

    public function testToArrayIncludesMetaWhenPresent(): void
    {
        $params = new InitializeRequestParams(
            new ProtocolVersion('2025-11-25'),
            new ClientCapabilities(),
            new Implementation('client', '1.0.0'),
            new RequestMeta(null, ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'client', 'version' => '1.0.0'],
            ],
            $params->toArray(),
        );
    }

    public function testJsonEncodeSubstitutesStdClassForEmptyCapabilitySlots(): void
    {
        $params = new InitializeRequestParams(
            new ProtocolVersion('2025-11-25'),
            new ClientCapabilities(roots: []),
            new Implementation('client', '1.0.0'),
        );

        $json = json_encode($params);

        self::assertIsString($json);
        self::assertStringContainsString('"roots":{}', $json);
        self::assertStringNotContainsString('"roots":[]', $json);
    }

    public function testJsonSerializeIncludesMetaWhenSet(): void
    {
        $params = new InitializeRequestParams(
            new ProtocolVersion('2025-11-25'),
            new ClientCapabilities(),
            new Implementation('client', '1.0.0'),
            new RequestMeta(null, ['vendor' => 'x']),
        );

        self::assertSame(
            '{"_meta":{"vendor":"x"},"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"client","version":"1.0.0"}}',
            json_encode($params, \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testToArrayKeepsPureArraysForEmptyCapabilitySlots(): void
    {
        $params = new InitializeRequestParams(
            new ProtocolVersion('2025-11-25'),
            new ClientCapabilities(roots: []),
            new Implementation('client', '1.0.0'),
        );

        self::assertSame(
            [
                'protocolVersion' => '2025-11-25',
                'capabilities' => ['roots' => []],
                'clientInfo' => ['name' => 'client', 'version' => '1.0.0'],
            ],
            $params->toArray(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new InitializeRequestParams(
            new ProtocolVersion('2025-11-25'),
            new ClientCapabilities(roots: ['listChanged' => true]),
            new Implementation('client', '1.0.0'),
            new RequestMeta(null, ['vendor' => 'x']),
        );

        $rebuilt = InitializeRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayParsesMinimalShape(): void
    {
        $params = InitializeRequestParams::fromArray([
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'c', 'version' => '1'],
        ]);

        self::assertSame('2025-11-25', $params->protocolVersion->version);
        self::assertSame('c', $params->clientInfo->name);
        self::assertNull($params->meta);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        InitializeRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing protocolVersion' => [
            ['capabilities' => [], 'clientInfo' => ['name' => 'c', 'version' => '1']],
            'InitializeRequestParams wire data missing "protocolVersion".',
        ];

        yield 'protocolVersion not a string' => [
            ['protocolVersion' => 1, 'capabilities' => [], 'clientInfo' => ['name' => 'c', 'version' => '1']],
            'InitializeRequestParams wire "protocolVersion" must be a string, int given.',
        ];

        yield 'missing capabilities' => [
            ['protocolVersion' => '2025-11-25', 'clientInfo' => ['name' => 'c', 'version' => '1']],
            'InitializeRequestParams wire data missing "capabilities".',
        ];

        yield 'capabilities not an object' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => 'oops', 'clientInfo' => ['name' => 'c', 'version' => '1']],
            'InitializeRequestParams wire "capabilities" must be an object, string given.',
        ];

        yield 'capabilities list-keyed' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => ['x'], 'clientInfo' => ['name' => 'c', 'version' => '1']],
            'InitializeRequestParams wire "capabilities" must be a string-keyed object.',
        ];

        yield 'missing clientInfo' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => []],
            'InitializeRequestParams wire data missing "clientInfo".',
        ];

        yield 'clientInfo not an object' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => 'oops'],
            'InitializeRequestParams wire "clientInfo" must be an object, string given.',
        ];

        yield 'clientInfo list-keyed' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['x']],
            'InitializeRequestParams wire "clientInfo" must be a string-keyed object.',
        ];

        yield '_meta not an object' => [
            ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'c', 'version' => '1'], '_meta' => 'oops'],
            'Request params "_meta" must be an object, string given.',
        ];
    }
}
