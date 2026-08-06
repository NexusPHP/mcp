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

namespace Nexus\Mcp\Tests\Core\Auth;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Auth\ScopeSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ScopeSet::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ScopeSetTest extends TestCase
{
    public function testConstructorDropsDuplicatesAndReindexes(): void
    {
        self::assertSame(['files:read', 'files:write'], new ScopeSet(['files:read', 'files:read', 'files:write'])->values);
    }

    public function testConstructorDefaultsToTheEmptySet(): void
    {
        self::assertSame([], new ScopeSet()->values);
    }

    public function testConstructorRejectsAnEmptyScope(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Each scope must be a non-empty string, string given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ScopeSet(['files:read', '']);
    }

    /**
     * @param list<non-empty-string> $expected
     */
    #[DataProvider('provideParseCases')]
    public function testParse(?string $scope, array $expected): void
    {
        self::assertSame($expected, ScopeSet::parse($scope)->values);
    }

    /**
     * @return iterable<string, array{null|string, list<non-empty-string>}>
     */
    public static function provideParseCases(): iterable
    {
        yield 'an absent parameter is the empty set' => [null, []];

        yield 'a blank parameter is the empty set' => ['', []];

        yield 'a parameter of only spaces is the empty set' => ['   ', []];

        yield 'one value' => ['files:read', ['files:read']];

        yield 'values are space-delimited' => ['files:read files:write', ['files:read', 'files:write']];

        yield 'surrounding spaces are ignored' => ['  files:read  ', ['files:read']];

        yield 'repeated spaces are ignored' => ['files:read   files:write', ['files:read', 'files:write']];

        yield 'duplicates collapse' => ['files:read files:read files:write', ['files:read', 'files:write']];

        yield 'values keep their case' => ['Files:Read', ['Files:Read']];
    }

    public function testMergeWithKeepsThisSetFirstAndDropsDuplicates(): void
    {
        $merged = new ScopeSet(['files:read'])->mergeWith(new ScopeSet(['files:write', 'files:read']));

        self::assertSame(['files:read', 'files:write'], $merged->values);
    }

    public function testMergeWithAnEmptySetChangesNothing(): void
    {
        self::assertSame(['files:read'], new ScopeSet(['files:read'])->mergeWith(new ScopeSet())->values);
    }

    public function testContainsAllAcceptsASubset(): void
    {
        self::assertTrue(new ScopeSet(['files:read', 'files:write'])->containsAll(new ScopeSet(['files:write'])));
    }

    public function testContainsAllAcceptsTheEmptySet(): void
    {
        self::assertTrue(new ScopeSet()->containsAll(new ScopeSet()));
    }

    public function testContainsAllRejectsAnUnheldValue(): void
    {
        self::assertFalse(new ScopeSet(['files:read'])->containsAll(new ScopeSet(['files:read', 'files:write'])));
    }

    public function testContainsAllIsCaseSensitive(): void
    {
        self::assertFalse(new ScopeSet(['files:read'])->containsAll(new ScopeSet(['Files:Read'])));
    }

    public function testToParameterOmitsAnEmptySet(): void
    {
        self::assertNull(new ScopeSet()->toParameter());
    }

    public function testToParameterJoinsWithSpaces(): void
    {
        self::assertSame('files:read files:write', new ScopeSet(['files:read', 'files:write'])->toParameter());
    }

    public function testWithoutDropsTheNamedScope(): void
    {
        self::assertSame(['files:read'], new ScopeSet(['files:read', 'offline_access'])->without('offline_access')->values);
    }

    public function testWithoutLeavesASetThatNeverHeldItAlone(): void
    {
        self::assertSame(['files:read'], new ScopeSet(['files:read'])->without('offline_access')->values);
    }
}
