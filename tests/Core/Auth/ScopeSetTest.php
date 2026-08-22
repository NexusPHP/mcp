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

use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ScopeSet::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ScopeSetTest extends AbstractMcpTestCase
{
    public function testConstructorDropsDuplicatesAndReindexes(): void
    {
        self::assertSame(['files:read', 'files:write'], (new ScopeSet(['files:read', 'files:read', 'files:write']))->values);
    }

    public function testConstructorDefaultsToTheEmptySet(): void
    {
        self::assertSame([], (new ScopeSet())->values);
    }

    public function testConstructorRejectsAnEmptyScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
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
        $merged = (new ScopeSet(['files:read']))->mergeWith(new ScopeSet(['files:write', 'files:read']));

        self::assertSame(['files:read', 'files:write'], $merged->values);
    }

    public function testMergeWithAnEmptySetChangesNothing(): void
    {
        self::assertSame(['files:read'], (new ScopeSet(['files:read']))->mergeWith(new ScopeSet())->values);
    }

    public function testContainsAllAcceptsASubset(): void
    {
        self::assertTrue((new ScopeSet(['files:read', 'files:write']))->containsAll(new ScopeSet(['files:write'])));
    }

    public function testContainsAllAcceptsTheEmptySet(): void
    {
        self::assertTrue((new ScopeSet())->containsAll(new ScopeSet()));
    }

    public function testContainsAllRejectsAnUnheldValue(): void
    {
        self::assertFalse((new ScopeSet(['files:read']))->containsAll(new ScopeSet(['files:read', 'files:write'])));
    }

    public function testContainsAllIsCaseSensitive(): void
    {
        self::assertFalse((new ScopeSet(['files:read']))->containsAll(new ScopeSet(['Files:Read'])));
    }

    public function testToParameterOmitsAnEmptySet(): void
    {
        self::assertNull((new ScopeSet())->toParameter());
    }

    public function testToParameterJoinsWithSpaces(): void
    {
        self::assertSame('files:read files:write', (new ScopeSet(['files:read', 'files:write']))->toParameter());
    }

    public function testWithoutDropsTheNamedScope(): void
    {
        self::assertSame(['files:read'], (new ScopeSet(['files:read', 'offline_access']))->without('offline_access')->values);
    }

    public function testWithoutLeavesASetThatNeverHeldItAlone(): void
    {
        self::assertSame(['files:read'], (new ScopeSet(['files:read']))->without('offline_access')->values);
    }

    /**
     * @param non-empty-string $scope
     */
    #[DataProvider('provideParseHoldsScopesToTheRfc6749GrammarCases')]
    public function testParseHoldsScopesToTheRfc6749Grammar(string $scope, bool $kept): void
    {
        self::assertSame($kept ? [$scope] : [], ScopeSet::parse($scope)->values);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function provideParseHoldsScopesToTheRfc6749GrammarCases(): iterable
    {
        yield 'a colon-delimited scope' => ['files:read', true];

        yield 'a bare scope' => ['openid', true];

        yield 'a URL-shaped scope' => ['https://graph.microsoft.com/.default', true];

        yield 'a dotted API scope' => ['api://a-b/access_as_user', true];

        yield 'an escape sequence is dropped' => ["\x1b[2Jadmin", false];

        yield 'a quote is dropped' => ['a"b', false];

        yield 'a backslash is dropped' => ['a\\b', false];

        yield 'a high byte is dropped' => ["read\xc3\xa9", false];
    }

    public function testParseKeepsTheConformingScopesAlongsideOneItDrops(): void
    {
        self::assertSame(['files:read', 'files:write'], ScopeSet::parse("files:read \x1b[2Jadmin files:write")->values);
    }

    public function testFromListDropsAValueThatIsNotAScopeToken(): void
    {
        self::assertSame(
            ['files:read', 'files:write'],
            ScopeSet::fromList(['files:read', "adm\x1b[2Jin", 'files:write', 'wr"ite'])->values,
        );
    }

    public function testFromListKeepsASpaceBearingValueOut(): void
    {
        self::assertSame([], ScopeSet::fromList(['files:read files:write'])->values);
    }

    public function testContainsFindsAHeldScope(): void
    {
        self::assertTrue((new ScopeSet(['files:read', 'files:write']))->contains('files:write'));
    }

    public function testContainsRejectsAnUnheldScope(): void
    {
        self::assertFalse((new ScopeSet(['files:read']))->contains('files:write'));
    }
}
