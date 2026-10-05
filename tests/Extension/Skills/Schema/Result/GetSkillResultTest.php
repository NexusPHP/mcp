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

use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\GetSkillResult;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GetSkillResult::class)]
#[CoversClass(CacheableResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class GetSkillResultTest extends AbstractMcpTestCase
{
    private const array ENTRY = [
        'uri' => 'skill://refunds/SKILL.md',
        'frontmatter' => ['name' => 'refunds', 'description' => 'Process refunds.'],
        'resources' => 'dynamic',
    ];

    public function testConstruction(): void
    {
        $entry = Skill::fromArray(self::ENTRY);
        $result = new GetSkillResult(skill: $entry, ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame($entry, $result->skill);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertSame([], $result->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $result = new GetSkillResult(skill: Skill::fromArray(self::ENTRY), ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame(
            ['resultType' => 'complete', 'skill' => self::ENTRY, 'ttlMs' => 0, 'cacheScope' => 'private'],
            $result->toArray(),
        );
        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testToArrayWithAllFields(): void
    {
        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'skill' => self::ENTRY,
                'ttlMs' => 60_000,
                'cacheScope' => 'public',
            ],
            $this->createFull()->toArray(),
        );
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
        $rebuilt = GetSkillResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
        self::assertSame('refunds', $rebuilt->skill->frontmatter['name']);
    }

    public function testFromArrayAcceptsAnEmptyMeta(): void
    {
        $result = GetSkillResult::fromArray(['skill' => self::ENTRY, 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => []]);

        self::assertSame([], $result->meta->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        GetSkillResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing skill' => [[], '"result" is missing the required "skill" key.'];

        yield 'skill not an object' => [['skill' => 'oops'], '"result.skill" must be an object, string given.'];

        yield 'skill list-keyed' => [['skill' => ['x']], '"result.skill" must be a string-keyed object.'];

        yield 'missing ttlMs' => [['skill' => self::ENTRY], '"result" is missing the required "ttlMs" key.'];

        yield 'ttlMs not an integer' => [['skill' => self::ENTRY, 'ttlMs' => 'oops'], '"result.ttlMs" must be an integer, string given.'];

        yield 'missing cacheScope' => [['skill' => self::ENTRY, 'ttlMs' => 0], '"result" is missing the required "cacheScope" key.'];

        yield 'cacheScope not a known value' => [
            ['skill' => self::ENTRY, 'ttlMs' => 0, 'cacheScope' => 'shared'],
            '"result.cacheScope" must be one of [\'public\', \'private\'], \'shared\' given.',
        ];

        yield '_meta not an object' => [
            ['skill' => self::ENTRY, 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['skill' => self::ENTRY, 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => ['x']],
            '"result._meta" must be a string-keyed object.',
        ];
    }

    private function createFull(): GetSkillResult
    {
        return new GetSkillResult(
            skill: Skill::fromArray(self::ENTRY),
            ttlMs: 60_000,
            cacheScope: CacheScope::Public,
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );
    }
}
