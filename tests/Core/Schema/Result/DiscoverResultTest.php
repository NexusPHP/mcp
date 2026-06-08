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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DiscoverResult::class)]
#[CoversClass(CacheableResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class DiscoverResultTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $capabilities = new ServerCapabilities(tools: ['listChanged' => true]);
        $serverInfo = new Implementation('srv', '1.0.0');
        $result = new DiscoverResult(
            [ProtocolVersion::LATEST_VERSION],
            $capabilities,
            $serverInfo,
            0,
            CacheScope::Private,
        );

        self::assertSame([ProtocolVersion::LATEST_VERSION], $result->supportedVersions);
        self::assertSame($capabilities, $result->capabilities);
        self::assertSame($serverInfo, $result->serverInfo);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->instructions);
        self::assertSame([], $result->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $result = new DiscoverResult(
            ['2026-07-28'],
            new ServerCapabilities(tools: ['listChanged' => true]),
            new Implementation('srv', '1.0.0'),
            0,
            CacheScope::Private,
        );

        self::assertSame(
            [
                'resultType' => 'complete',
                'ttlMs' => 0,
                'cacheScope' => 'private',
                'supportedVersions' => ['2026-07-28'],
                'capabilities' => ['tools' => ['listChanged' => true]],
                'serverInfo' => ['name' => 'srv', 'version' => '1.0.0'],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new DiscoverResult(
            ['2026-07-28', '2025-06-18'],
            new ServerCapabilities(tools: ['listChanged' => true]),
            new Implementation('srv', '1.0.0'),
            60000,
            CacheScope::Public,
            'Be helpful.',
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'ttlMs' => 60000,
                'cacheScope' => 'public',
                'supportedVersions' => ['2026-07-28', '2025-06-18'],
                'capabilities' => ['tools' => ['listChanged' => true]],
                'serverInfo' => ['name' => 'srv', 'version' => '1.0.0'],
                'instructions' => 'Be helpful.',
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeSubstitutesEmptyCapabilities(): void
    {
        $result = new DiscoverResult(
            ['2026-07-28'],
            new ServerCapabilities(logging: []),
            new Implementation('srv', '1.0.0'),
            0,
            CacheScope::Private,
        );

        self::assertStringContainsString('"logging":{}', (string) json_encode($result));
    }

    public function testJsonSerializeMatchesToArrayForNonEmptyCapabilities(): void
    {
        $result = new DiscoverResult(
            ['2026-07-28'],
            new ServerCapabilities(tools: ['listChanged' => true]),
            new Implementation('srv', '1.0.0'),
            0,
            CacheScope::Private,
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new DiscoverResult(
            ['2026-07-28', '2025-06-18'],
            new ServerCapabilities(tools: ['listChanged' => true]),
            new Implementation('srv', '1.0.0'),
            60000,
            CacheScope::Public,
            'Be helpful.',
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = DiscoverResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonStringSupportedVersion(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "result.supportedVersions" must be a non-empty string.');

        new DiscoverResult(
            // @phpstan-ignore argument.type
            [1],
            new ServerCapabilities(),
            new Implementation('srv', '1.0.0'),
            0,
            CacheScope::Private,
        );
    }

    public function testConstructorRejectsEmptyStringSupportedVersion(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "result.supportedVersions" must be a non-empty string.');

        new DiscoverResult(
            [''],
            new ServerCapabilities(),
            new Implementation('srv', '1.0.0'),
            0,
            CacheScope::Private,
        );
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.ttlMs" must be a non-negative integer, -1 given.');

        new DiscoverResult(
            [ProtocolVersion::LATEST_VERSION],
            new ServerCapabilities(),
            new Implementation('srv', '1.0.0'),
            -1,
            CacheScope::Private,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        DiscoverResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        $validInfo = ['name' => 'srv', 'version' => '1.0.0'];

        yield 'missing supportedVersions' => [
            [],
            '"result" missing the required "supportedVersions" key.',
        ];

        yield 'supportedVersions not a list' => [
            ['supportedVersions' => 'oops'],
            '"result.supportedVersions" must be a list, string given.',
        ];

        yield 'supportedVersions entry not a string' => [
            ['supportedVersions' => [1]],
            'each "result.supportedVersions" must be a string, int given.',
        ];

        yield 'missing capabilities' => [
            ['supportedVersions' => ['2026-07-28']],
            '"result" missing the required "capabilities" key.',
        ];

        yield 'capabilities not an object' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => 'oops'],
            '"result.capabilities" must be an object, string given.',
        ];

        yield 'capabilities list-keyed' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => ['x']],
            '"result.capabilities" must be a string-keyed object.',
        ];

        yield 'missing serverInfo' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => []],
            '"result" missing the required "serverInfo" key.',
        ];

        yield 'serverInfo not an object' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'serverInfo' => 'oops'],
            '"result.serverInfo" must be an object, string given.',
        ];

        yield 'serverInfo list-keyed' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'serverInfo' => ['x']],
            '"result.serverInfo" must be a string-keyed object.',
        ];

        yield 'missing ttlMs' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'serverInfo' => $validInfo],
            '"result" missing the required "ttlMs" key.',
        ];

        yield 'ttlMs not an integer' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'serverInfo' => $validInfo, 'ttlMs' => 'oops'],
            '"result.ttlMs" must be an integer, string given.',
        ];

        yield 'missing cacheScope' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'serverInfo' => $validInfo, 'ttlMs' => 0],
            '"result" missing the required "cacheScope" key.',
        ];

        yield 'cacheScope not a known value' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'serverInfo' => $validInfo, 'ttlMs' => 0, 'cacheScope' => 'shared'],
            '"result.cacheScope" must be one of [\'public\', \'private\'], \'shared\' given.',
        ];

        yield 'instructions not a string' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'serverInfo' => $validInfo, 'ttlMs' => 0, 'cacheScope' => 'private', 'instructions' => 1],
            '"result.instructions" must be a string, int given.',
        ];

        yield '_meta not an object' => [
            ['supportedVersions' => ['2026-07-28'], 'capabilities' => [], 'serverInfo' => $validInfo, 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];
    }
}
