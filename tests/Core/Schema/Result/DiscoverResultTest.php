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

namespace Nexus\Mcp\Tests\Core\Schema\Result;

use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(DiscoverResult::class)]
#[CoversClass(CacheableResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class DiscoverResultTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $capabilities = new ServerCapabilities(tools: ['listChanged' => true]);
        $result = new DiscoverResult(
            supportedVersions: [ProtocolVersion::LATEST_VERSION],
            capabilities: $capabilities,
            ttlMs: 0,
            cacheScope: CacheScope::Private,
        );

        self::assertSame([ProtocolVersion::LATEST_VERSION], $result->supportedVersions);
        self::assertSame($capabilities, $result->capabilities);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->instructions);
        self::assertSame([], $result->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $result = new DiscoverResult(
            supportedVersions: ['2026-07-28'],
            capabilities: new ServerCapabilities(tools: ['listChanged' => true]),
            ttlMs: 0,
            cacheScope: CacheScope::Private,
        );

        self::assertSame(
            [
                'resultType' => 'complete',
                'supportedVersions' => ['2026-07-28'],
                'capabilities' => ['tools' => ['listChanged' => true]],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
            $result->toArray(),
        );
    }

    public function testRebuildingWithNewMetaKeepsEveryOtherField(): void
    {
        $result = new DiscoverResult(
            supportedVersions: ['2026-07-28', '2025-06-18'],
            capabilities: new ServerCapabilities(tools: ['listChanged' => true]),
            ttlMs: 60_000,
            cacheScope: CacheScope::Public,
            instructions: 'Be helpful.',
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = $result->rebuildWithMeta(new GenericResultMetaObject(extras: ['replaced' => true]));

        self::assertSame(
            ['_meta' => ['replaced' => true]] + $result->toArray(),
            $rebuilt->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new DiscoverResult(
            supportedVersions: ['2026-07-28', '2025-06-18'],
            capabilities: new ServerCapabilities(tools: ['listChanged' => true]),
            ttlMs: 60_000,
            cacheScope: CacheScope::Public,
            instructions: 'Be helpful.',
            meta: new GenericResultMetaObject(
                serverInfo: new Implementation(name: 'srv', version: '1.0.0'),
                extras: ['vendor' => 'x'],
            ),
        );

        self::assertSame(
            [
                '_meta' => [
                    ResultMetaObject::SERVER_INFO_KEY => ['name' => 'srv', 'version' => '1.0.0'],
                    'vendor' => 'x',
                ],
                'resultType' => 'complete',
                'supportedVersions' => ['2026-07-28', '2025-06-18'],
                'capabilities' => ['tools' => ['listChanged' => true]],
                'instructions' => 'Be helpful.',
                'ttlMs' => 60_000,
                'cacheScope' => 'public',
            ],
            $result->toArray(),
        );
    }

    public function testToArrayOmitsTheServerInfoWhenTheMetaIsEmpty(): void
    {
        $result = new DiscoverResult(
            supportedVersions: ['2026-07-28'],
            capabilities: new ServerCapabilities(),
            ttlMs: 0,
            cacheScope: CacheScope::Private,
        );

        self::assertArrayNotHasKey('_meta', $result->toArray());
    }

    public function testJsonSerializeSubstitutesEmptyCapabilities(): void
    {
        $result = new DiscoverResult(
            supportedVersions: ['2026-07-28'],
            capabilities: new ServerCapabilities(completions: []),
            ttlMs: 0,
            cacheScope: CacheScope::Private,
        );

        self::assertStringContainsString('"completions":{}', (string) json_encode($result));
    }

    public function testJsonSerializeMatchesToArrayForNonEmptyCapabilities(): void
    {
        $result = new DiscoverResult(
            supportedVersions: ['2026-07-28'],
            capabilities: new ServerCapabilities(tools: ['listChanged' => true]),
            ttlMs: 0,
            cacheScope: CacheScope::Private,
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new DiscoverResult(
            supportedVersions: ['2026-07-28', '2025-06-18'],
            capabilities: new ServerCapabilities(tools: ['listChanged' => true]),
            ttlMs: 60_000,
            cacheScope: CacheScope::Public,
            instructions: 'Be helpful.',
            meta: new GenericResultMetaObject(
                serverInfo: new Implementation(name: 'srv', version: '1.0.0'),
                extras: ['vendor' => 'x'],
            ),
        );

        $rebuilt = DiscoverResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayParsesTheServerInfoFromMeta(): void
    {
        $result = DiscoverResult::fromArray([
            '_meta' => [ResultMetaObject::SERVER_INFO_KEY => ['name' => 'srv', 'version' => '1.0.0']],
            'supportedVersions' => ['2026-07-28'],
            'capabilities' => [],
            'ttlMs' => 0,
            'cacheScope' => 'private',
        ]);

        self::assertInstanceOf(Implementation::class, $result->meta->serverInfo);

        self::assertSame('srv', $result->meta->serverInfo->name);
    }

    public function testConstructorRejectsNonStringSupportedVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.supportedVersions" must be a non-empty string.');

        new DiscoverResult(
            // @phpstan-ignore argument.type
            supportedVersions: [1],
            capabilities: new ServerCapabilities(),
            ttlMs: 0,
            cacheScope: CacheScope::Private,
        );
    }

    public function testConstructorRejectsEmptyStringSupportedVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('each "result.supportedVersions" must be a non-empty string.');

        new DiscoverResult(
            // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
            supportedVersions: [''],
            capabilities: new ServerCapabilities(),
            ttlMs: 0,
            cacheScope: CacheScope::Private,
        );
    }

    public function testConstructorRejectsEmptyInstructions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result.instructions" must be a non-empty string or null.');

        new DiscoverResult(
            supportedVersions: ['2026-07-28'],
            capabilities: new ServerCapabilities(),
            ttlMs: 0,
            cacheScope: CacheScope::Private,
            // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
            instructions: '',
        );
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result.ttlMs" must be a non-negative integer, -1 given.');

        new DiscoverResult(
            supportedVersions: [ProtocolVersion::LATEST_VERSION],
            capabilities: new ServerCapabilities(),
            ttlMs: -1,
            cacheScope: CacheScope::Private,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        DiscoverResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing supportedVersions' => [
            [],
            '"result" is missing the required "supportedVersions" key.',
        ];

        yield 'supportedVersions not a list' => [
            ['supportedVersions' => 'oops'],
            '"result.supportedVersions" must be a list, string given.',
        ];

        yield 'supportedVersions entry not a string' => [
            ['supportedVersions' => [1]],
            'each "result.supportedVersions" must be a non-empty string, int given.',
        ];

        yield 'missing capabilities' => [
            ['supportedVersions' => ['2026-07-28']],
            '"result" is missing the required "capabilities" key.',
        ];

        yield 'capabilities not an object' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => 'oops'],
            '"result.capabilities" must be an object, string given.',
        ];

        yield 'capabilities list-keyed' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => ['x']],
            '"result.capabilities" must be a string-keyed object.',
        ];

        yield 'missing ttlMs' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => []],
            '"result" is missing the required "ttlMs" key.',
        ];

        yield 'ttlMs not an integer' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'ttlMs' => 'oops'],
            '"result.ttlMs" must be an integer, string given.',
        ];

        yield 'missing cacheScope' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'ttlMs' => 0],
            '"result" is missing the required "cacheScope" key.',
        ];

        yield 'cacheScope not a known value' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'ttlMs' => 0, 'cacheScope' => 'shared'],
            '"result.cacheScope" must be one of [\'public\', \'private\'], \'shared\' given.',
        ];

        yield 'instructions not a string' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'ttlMs' => 0, 'cacheScope' => 'private', 'instructions' => 1],
            '"result.instructions" must be a non-empty string, int given.',
        ];

        yield 'instructions empty' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'ttlMs' => 0, 'cacheScope' => 'private', 'instructions' => ''],
            '"result.instructions" must be a non-empty string, string given.',
        ];

        yield '_meta not an object' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];
    }
}
