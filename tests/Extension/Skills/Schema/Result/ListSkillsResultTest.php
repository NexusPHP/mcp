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

namespace Nexus\Mcp\Tests\Extension\Skills\Schema\Result;

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\ListSkillsResult;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListSkillsResult::class)]
#[CoversClass(PaginatedResult::class)]
#[CoversClass(CacheableResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ListSkillsResultTest extends AbstractMcpTestCase
{
    private const array ENTRY = [
        'uri' => 'skill://refunds/SKILL.md',
        'frontmatter' => ['name' => 'refunds', 'description' => 'Process refunds.'],
        'resources' => 'dynamic',
    ];

    public function testConstructionDefaults(): void
    {
        $result = new ListSkillsResult(skills: [], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame([], $result->skills);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        self::assertSame(
            ['resultType' => 'complete', 'skills' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
            (new ListSkillsResult(skills: [], ttlMs: 0, cacheScope: CacheScope::Private))->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = $this->createFull();

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'skills' => [self::ENTRY],
                'nextCursor' => 'cursor-1',
                'ttlMs' => 60_000,
                'cacheScope' => 'public',
            ],
            $result->toArray(),
        );
        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testRebuildingWithNewMetaKeepsEveryOtherField(): void
    {
        $result = $this->createFull();
        $rebuilt = $result->rebuildWithMeta(new GenericResultMetaObject(extras: ['replaced' => true]));

        self::assertSame(['_meta' => ['replaced' => true]] + $result->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = $this->createFull();
        $rebuilt = ListSkillsResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
        self::assertArrayHasKey(0, $rebuilt->skills);
        self::assertSame('refunds', $rebuilt->skills[0]->frontmatter['name']);
    }

    public function testFromArrayAcceptsAnEmptyMeta(): void
    {
        $result = ListSkillsResult::fromArray(['skills' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => []]);

        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructorRejectsNonListSkills(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result.skills" must be a list, non-list array given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ListSkillsResult(skills: [5 => Skill::fromArray(self::ENTRY)], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    public function testConstructorRejectsANonEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ListSkillsResult(skills: [42], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ListSkillsResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing skills' => [[], '"result" is missing the required "skills" key.'];

        yield 'skills not a list' => [['skills' => 'oops'], '"result.skills" must be a list, string given.'];

        yield 'skill entry not an object' => [['skills' => ['oops']], 'each "result.skills" entry must be an object, string given.'];

        yield 'skill entry list-keyed' => [['skills' => [['x']]], 'each "result.skills" entry must be a string-keyed object.'];

        yield 'missing ttlMs' => [['skills' => []], '"result" is missing the required "ttlMs" key.'];

        yield 'ttlMs not an integer' => [['skills' => [], 'ttlMs' => 'oops'], '"result.ttlMs" must be an integer, string given.'];

        yield 'missing cacheScope' => [['skills' => [], 'ttlMs' => 0], '"result" is missing the required "cacheScope" key.'];

        yield 'cacheScope not a known value' => [
            ['skills' => [], 'ttlMs' => 0, 'cacheScope' => 'shared'],
            '"result.cacheScope" must be one of [\'public\', \'private\'], \'shared\' given.',
        ];

        yield 'nextCursor not a string' => [
            ['skills' => [], 'ttlMs' => 0, 'cacheScope' => 'private', 'nextCursor' => 1],
            '"result.nextCursor" must be a non-empty string, int given.',
        ];

        yield '_meta not an object' => [
            ['skills' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['skills' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => ['x']],
            '"result._meta" must be a string-keyed object.',
        ];
    }

    private function createFull(): ListSkillsResult
    {
        return new ListSkillsResult(
            skills: [Skill::fromArray(self::ENTRY)],
            ttlMs: 60_000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cursor-1'),
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );
    }
}
