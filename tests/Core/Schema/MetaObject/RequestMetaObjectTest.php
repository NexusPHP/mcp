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

namespace Nexus\Mcp\Tests\Core\Schema\MetaObject;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestMetaObject::class)]
#[CoversClass(MetaObject::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RequestMetaObjectTest extends TestCase
{
    public function testConstructionCapturesAllFields(): void
    {
        $protocolVersion = new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION);
        $clientInfo = new Implementation(name: 'client', version: '1.0.0');
        $clientCapabilities = new ClientCapabilities(experimental: ['acme.experimental' => ['enabled' => true]]);
        $meta = new RequestMetaObject(
            protocolVersion: $protocolVersion,
            clientInfo: $clientInfo,
            clientCapabilities: $clientCapabilities,
            logLevel: LoggingLevel::Debug,
            progressToken: new ProgressToken(token: 'tok-1'),
            extras: ['vendor' => 'x'],
        );

        self::assertSame($protocolVersion, $meta->protocolVersion);
        self::assertSame($clientInfo, $meta->clientInfo);
        self::assertSame($clientCapabilities, $meta->clientCapabilities);
        self::assertSame(LoggingLevel::Debug, $meta->logLevel);
        self::assertInstanceOf(ProgressToken::class, $meta->progressToken);
        self::assertSame('tok-1', $meta->progressToken->token);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testConstructionDefaultsOptionalFields(): void
    {
        $meta = new RequestMetaObject(
            protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
            clientInfo: new Implementation(name: 'client', version: '1.0.0'),
            clientCapabilities: new ClientCapabilities(),
        );

        self::assertNull($meta->logLevel);
        self::assertNull($meta->progressToken);
        self::assertSame([], $meta->extras);
    }

    public function testToArrayEmitsNamespacedKeys(): void
    {
        $meta = new RequestMetaObject(
            protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
            clientInfo: new Implementation(name: 'client', version: '1.0.0'),
            clientCapabilities: new ClientCapabilities(experimental: ['acme.experimental' => ['enabled' => true]]),
        );

        self::assertSame(
            [
                RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
                RequestMetaObject::CLIENT_INFO_KEY => ['name' => 'client', 'version' => '1.0.0'],
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => ['experimental' => ['acme.experimental' => ['enabled' => true]]],
            ],
            $meta->toArray(),
        );
    }

    public function testToArrayEmitsProgressTokenAndLogLevelAndExtras(): void
    {
        $meta = new RequestMetaObject(
            protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
            clientInfo: new Implementation(name: 'client', version: '1.0.0'),
            clientCapabilities: new ClientCapabilities(experimental: ['acme.experimental' => ['enabled' => true]]),
            logLevel: LoggingLevel::Warning,
            progressToken: new ProgressToken(token: 42),
            extras: ['vendor' => 'x'],
        );

        self::assertSame(
            [
                RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
                RequestMetaObject::CLIENT_INFO_KEY => ['name' => 'client', 'version' => '1.0.0'],
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => ['experimental' => ['acme.experimental' => ['enabled' => true]]],
                RequestMetaObject::LOG_LEVEL_KEY => 'warning',
                'progressToken' => 42,
                'vendor' => 'x',
            ],
            $meta->toArray(),
        );
    }

    public function testToArrayOmitsProgressTokenAndLogLevelWhenNull(): void
    {
        $meta = new RequestMetaObject(
            protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
            clientInfo: new Implementation(name: 'client', version: '1.0.0'),
            clientCapabilities: new ClientCapabilities(experimental: ['acme.experimental' => ['enabled' => true]]),
        );

        $data = $meta->toArray();

        self::assertArrayNotHasKey('progressToken', $data);
        self::assertArrayNotHasKey(RequestMetaObject::LOG_LEVEL_KEY, $data);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $meta = RequestMetaObject::fromArray([
            RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
            RequestMetaObject::CLIENT_INFO_KEY => ['name' => 'client', 'version' => '1.0.0'],
            RequestMetaObject::CLIENT_CAPABILITIES_KEY => ['experimental' => ['acme.experimental' => ['enabled' => true]]],
            RequestMetaObject::LOG_LEVEL_KEY => 'info',
            'progressToken' => 'tok-1',
            'vendor' => 'x',
        ]);

        self::assertSame('2026-07-28', $meta->protocolVersion->version);

        if (! $meta->clientInfo instanceof Implementation) {
            self::fail('Expected the client info to be parsed.');
        }

        self::assertSame('client', $meta->clientInfo->name);
        self::assertSame(['acme.experimental' => ['enabled' => true]], $meta->clientCapabilities->experimental);
        self::assertSame(LoggingLevel::Info, $meta->logLevel);
        self::assertNotNull($meta->progressToken);
        self::assertSame('tok-1', $meta->progressToken->token);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testFromArrayLeavesOptionalFieldsNull(): void
    {
        $meta = RequestMetaObject::fromArray([
            RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
            RequestMetaObject::CLIENT_CAPABILITIES_KEY => [],
        ]);

        self::assertNull($meta->clientInfo);
        self::assertNull($meta->logLevel);
        self::assertNull($meta->progressToken);
        self::assertSame([], $meta->extras);
    }

    public function testToArrayOmitsClientInfoWhenNull(): void
    {
        $meta = new RequestMetaObject(
            protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
            clientCapabilities: new ClientCapabilities(),
        );

        self::assertSame(
            [
                RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => [],
            ],
            $meta->toArray(),
        );
    }

    public function testJsonSerializeSubstitutesEmptyClientCapabilities(): void
    {
        $meta = new RequestMetaObject(
            protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
            clientInfo: new Implementation(name: 'client', version: '1.0.0'),
            clientCapabilities: new ClientCapabilities(),
        );

        $serialized = $meta->jsonSerialize();

        self::assertArrayHasKey(RequestMetaObject::CLIENT_CAPABILITIES_KEY, $serialized);
        self::assertInstanceOf(\stdClass::class, $serialized[RequestMetaObject::CLIENT_CAPABILITIES_KEY]);
        self::assertStringContainsString(
            \sprintf('"%s":{}', RequestMetaObject::CLIENT_CAPABILITIES_KEY),
            (string) json_encode($meta, \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testJsonSerializeMatchesToArrayForNonEmptyClientCapabilities(): void
    {
        $meta = new RequestMetaObject(
            protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
            clientInfo: new Implementation(name: 'client', version: '1.0.0'),
            clientCapabilities: new ClientCapabilities(experimental: ['acme.experimental' => ['enabled' => true]]),
        );

        self::assertSame($meta->toArray(), $meta->jsonSerialize());
    }

    public function testRoundTripPreservesEverything(): void
    {
        $original = new RequestMetaObject(
            protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
            clientInfo: new Implementation(name: 'client', version: '1.0.0'),
            clientCapabilities: new ClientCapabilities(experimental: ['acme.experimental' => ['enabled' => true]]),
            logLevel: LoggingLevel::Error,
            progressToken: new ProgressToken(token: 7),
            extras: ['key' => 'value'],
        );

        self::assertSame($original->toArray(), RequestMetaObject::fromArray($original->toArray())->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        RequestMetaObject::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        $validInfo = ['name' => 'client', 'version' => '1.0.0'];

        yield 'missing protocolVersion' => [
            [],
            '"_meta" is missing the required "io.modelcontextprotocol/protocolVersion" key.',
        ];

        yield 'protocolVersion not a string' => [
            [RequestMetaObject::PROTOCOL_VERSION_KEY => 1],
            '"_meta.io.modelcontextprotocol/protocolVersion" must be a non-empty string, int given.',
        ];

        yield 'clientInfo not an object' => [
            [
                RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => [],
                RequestMetaObject::CLIENT_INFO_KEY => 'oops',
            ],
            '"_meta.io.modelcontextprotocol/clientInfo" must be an object, string given.',
        ];

        yield 'clientInfo list-keyed' => [
            [
                RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => [],
                RequestMetaObject::CLIENT_INFO_KEY => ['x'],
            ],
            '"_meta.io.modelcontextprotocol/clientInfo" must be a string-keyed object.',
        ];

        yield 'missing clientCapabilities' => [
            [RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28'],
            '"_meta" is missing the required "io.modelcontextprotocol/clientCapabilities" key.',
        ];

        yield 'clientCapabilities not an object' => [
            [
                RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
                RequestMetaObject::CLIENT_INFO_KEY => $validInfo,
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => 'oops',
            ],
            '"_meta.io.modelcontextprotocol/clientCapabilities" must be an object, string given.',
        ];

        yield 'clientCapabilities list-keyed' => [
            [
                RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
                RequestMetaObject::CLIENT_INFO_KEY => $validInfo,
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => ['x'],
            ],
            '"_meta.io.modelcontextprotocol/clientCapabilities" must be a string-keyed object.',
        ];

        yield 'logLevel not a known value' => [
            [
                RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
                RequestMetaObject::CLIENT_INFO_KEY => $validInfo,
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => [],
                RequestMetaObject::LOG_LEVEL_KEY => 'verbose',
            ],
            '"_meta.io.modelcontextprotocol/logLevel" must be one of [\'debug\', \'info\', \'notice\', \'warning\', \'error\', \'critical\', \'alert\', \'emergency\'], \'verbose\' given.',
        ];

        yield 'progressToken not an int or string' => [
            [
                RequestMetaObject::PROTOCOL_VERSION_KEY => '2026-07-28',
                RequestMetaObject::CLIENT_INFO_KEY => $validInfo,
                RequestMetaObject::CLIENT_CAPABILITIES_KEY => [],
                'progressToken' => [],
            ],
            '"_meta.progressToken" must be an int or non-empty string, array given.',
        ];
    }
}
