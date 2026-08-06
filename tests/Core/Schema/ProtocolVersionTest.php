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
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProtocolVersion::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ProtocolVersionTest extends TestCase
{
    public function testLatestIsAcceptedByTheConstructor(): void
    {
        self::assertSame(ProtocolVersion::LATEST_VERSION, new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION)->version);
    }

    public function testPreviousVersionsDoNotIncludeLatest(): void
    {
        self::assertNotContains(ProtocolVersion::LATEST_VERSION, ProtocolVersion::PREVIOUS_VERSIONS);
    }

    /**
     * @param non-empty-string $version
     */
    #[DataProvider('providePreviousVersionsAreAcceptedByConstructorCases')]
    public function testPreviousVersionsAreAcceptedByConstructor(string $version): void
    {
        self::assertSame($version, new ProtocolVersion(version: $version)->version);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function providePreviousVersionsAreAcceptedByConstructorCases(): iterable
    {
        foreach (ProtocolVersion::PREVIOUS_VERSIONS as $version) {
            yield $version => [$version];
        }
    }

    public function testEmptyVersionIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"protocolVersion" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ProtocolVersion(version: '');
    }

    /**
     * @param non-empty-string $version
     */
    #[DataProvider('provideAnUnrecognisedVersionIsAcceptedCases')]
    public function testAnUnrecognisedVersionIsAccepted(string $version): void
    {
        self::assertSame($version, new ProtocolVersion(version: $version)->version);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideAnUnrecognisedVersionIsAcceptedCases(): iterable
    {
        yield 'two-digit year' => ['25-11-25'];

        yield 'missing day' => ['2025-11'];

        yield 'extra segment' => ['2025-11-25-1'];

        yield 'non-numeric segment' => ['2025-XX-25'];

        yield 'underscores instead of dashes' => ['2025_11_25'];

        yield 'free-form text' => ['latest'];

        yield 'semver' => ['v999.0.0'];
    }
}
