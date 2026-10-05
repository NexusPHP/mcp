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

namespace Nexus\Mcp\Tests\Extension\Skills;

use Nexus\Mcp\Extension\Skills\Frontmatter;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Frontmatter::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class FrontmatterTest extends AbstractMcpTestCase
{
    public function testParsesEveryFieldOfTheBlock(): void
    {
        $markdown = <<<'MD'
            ---
            name: git-workflow
            description: Follow the team's Git conventions.
            license: Apache-2.0
            allowed: yes
            metadata:
              version: "2.1.0"
              tags: [git, review]
            ---

            # Git workflow
            MD;

        self::assertSame([
            'name' => 'git-workflow',
            'description' => 'Follow the team\'s Git conventions.',
            'license' => 'Apache-2.0',
            'allowed' => 'yes',
            'metadata' => ['version' => '2.1.0', 'tags' => ['git', 'review']],
        ], Frontmatter::parse($markdown));
    }

    public function testABlockAtTheValueLimitIsRead(): void
    {
        $frontmatter = Frontmatter::parse(\sprintf("---\nname: a\ndescription: b\ntags: [%s]\n---\n", implode(', ', array_fill(0, 9_996, 'x'))));

        self::assertIsArray($frontmatter['tags'] ?? null);
        self::assertCount(9_996, $frontmatter['tags']);
    }

    public function testABlockOverTheValueLimitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"SKILL.md" frontmatter holds more than 10000 values.');

        Frontmatter::parse(\sprintf("---\nname: a\ndescription: b\ntags: [%s]\n---\n", implode(', ', array_fill(0, 9_997, 'x'))));
    }

    public function testAliasesThatExpandPastTheValueLimitAreRefused(): void
    {
        $yaml = "name: a\ndescription: b\nl0: &l0 [x, x, x, x, x, x, x, x, x, x]\n";

        foreach ([1, 2, 3, 4] as $level) {
            $yaml .= \sprintf("l%d: &l%d [%s]\n", $level, $level, implode(', ', array_fill(0, 10, \sprintf('*l%d', $level - 1))));
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"SKILL.md" frontmatter holds more than 10000 values.');

        Frontmatter::parse(\sprintf("---\n%s---\n", $yaml));
    }

    public function testAMappingThatWouldEncodeAsAListStaysAnObject(): void
    {
        $frontmatter = Frontmatter::parse("---\nname: a\ndescription: b\nmetadata: {}\ntags: []\nsteps: {0: plan, 1: ship}\nnested:\n  empty: {}\n---\n");

        self::assertSame(
            '{"name":"a","description":"b","metadata":{},"tags":[],"steps":{"0":"plan","1":"ship"},"nested":{"empty":{}}}',
            json_encode($frontmatter, \JSON_THROW_ON_ERROR),
        );
        self::assertIsArray($frontmatter['nested'] ?? null);
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('provideADateIsRenderedAsTextCases')]
    public function testADateIsRenderedAsText(string $yaml, array $expected): void
    {
        self::assertSame(
            ['name' => 'a', 'description' => 'b'] + $expected,
            Frontmatter::parse("---\nname: a\ndescription: b\n{$yaml}\n---\n"),
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideADateIsRenderedAsTextCases(): iterable
    {
        yield 'a date' => ['updated: 2026-05-01', ['updated' => '2026-05-01']];

        yield 'a date nested in a mapping' => ["metadata:\n  updated: 2026-05-01", ['metadata' => ['updated' => '2026-05-01']]];

        yield 'a date nested in a list' => ["releases:\n  - 2026-05-01", ['releases' => ['2026-05-01']]];

        yield 'a timestamp' => ['updated: 2026-05-01T12:30:00Z', ['updated' => '2026-05-01T12:30:00+00:00']];

        yield 'a midnight timestamp in another zone' => ['updated: 2026-05-01T00:00:00+02:00', ['updated' => '2026-05-01T00:00:00+02:00']];
    }

    #[DataProvider('provideTheBlockMayEndInAnyLineEndingCases')]
    public function testTheBlockMayEndInAnyLineEnding(string $markdown): void
    {
        self::assertSame(['name' => 'a', 'description' => 'b'], Frontmatter::parse($markdown));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheBlockMayEndInAnyLineEndingCases(): iterable
    {
        yield 'line feeds' => ["---\nname: a\ndescription: b\n---\nbody"];

        yield 'carriage returns and line feeds' => ["---\r\nname: a\r\ndescription: b\r\n---\r\nbody"];

        yield 'nothing after the closing fence' => ["---\nname: a\ndescription: b\n---"];

        yield 'blanks after the fences' => ["--- \t\nname: a\ndescription: b\n--- \t\nbody"];

        yield 'a byte order mark before the opening fence' => ["\xEF\xBB\xBF---\nname: a\ndescription: b\n---\nbody"];

        yield 'a later fence left in the body' => ["---\nname: a\ndescription: b\n---\nbody\n---\nname: c\n---\n"];
    }

    #[DataProvider('provideAManifestWithoutAReadableBlockIsRefusedCases')]
    public function testAManifestWithoutAReadableBlockIsRefused(string $markdown, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        Frontmatter::parse($markdown);
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function provideAManifestWithoutAReadableBlockIsRefusedCases(): iterable
    {
        $missing = '/^"SKILL\.md" must begin with YAML frontmatter\.$/';

        yield 'no block at all' => ["# Title\n", $missing];

        yield 'a block that is not at the start' => ["\n---\nname: a\n---\n", $missing];

        yield 'an opening fence with text after it' => ["---yaml\nname: a\n---\n", $missing];

        yield 'a closing fence with text after it' => ["---\nname: a\n---x\n", $missing];

        yield 'an indented closing fence' => ["---\nname: a\n ---\n", $missing];

        yield 'a block that never closes' => ["---\nname: a\n", $missing];

        yield 'YAML that does not parse' => ["---\nname: [a\n---\n", '/^"SKILL\.md" frontmatter is not valid YAML: /'];

        yield 'a scalar' => ["---\njust text\n---\n", '/^"SKILL\.md" frontmatter must be a mapping, string given\.$/'];

        yield 'a sequence' => ["---\n- a\n- b\n---\n", '/^"SKILL\.md" frontmatter must be a mapping\.$/'];

        yield 'no name' => ["---\ndescription: b\n---\n", '/^"SKILL\.md" frontmatter is missing the required "name" field\.$/'];

        yield 'a name that is not text' => ["---\nname: 7\ndescription: b\n---\n", '/^"SKILL\.md" frontmatter "name" must be a non-empty string, int given\.$/'];

        yield 'no description' => ["---\nname: a\n---\n", '/^"SKILL\.md" frontmatter is missing the required "description" field\.$/'];

        yield 'an empty description' => ["---\nname: a\ndescription: ''\n---\n", '/^"SKILL\.md" frontmatter "description" must be a non-empty string, string given\.$/'];
    }

    public function testAParseFailureKeepsItsCause(): void
    {
        try {
            Frontmatter::parse("---\nname: [a\n---\n");
            self::fail('The frontmatter should have been refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
    }

    #[DataProvider('provideEqualsComparesFieldByFieldCases')]
    public function testEqualsComparesFieldByField(mixed $left, mixed $right, bool $expected): void
    {
        self::assertSame($expected, Frontmatter::equals($left, $right));
    }

    /**
     * @return iterable<string, array{mixed, mixed, bool}>
     */
    public static function provideEqualsComparesFieldByFieldCases(): iterable
    {
        yield 'identical' => [['name' => 'a', 'description' => 'b'], ['name' => 'a', 'description' => 'b'], true];

        yield 'keys in another order' => [['name' => 'a', 'description' => 'b'], ['description' => 'b', 'name' => 'a'], true];

        yield 'nested keys in another order' => [
            ['metadata' => ['version' => '1', 'owner' => 'x']],
            ['metadata' => ['owner' => 'x', 'version' => '1']],
            true,
        ];

        yield 'a changed value' => [['description' => 'Extract PDFs'], ['description' => 'Exfiltrate credentials'], false];

        yield 'a missing field' => [['name' => 'a', 'license' => 'MIT'], ['name' => 'a'], false];

        yield 'an added field' => [['name' => 'a'], ['name' => 'a', 'license' => 'MIT'], false];

        yield 'a value of another type' => [['version' => '1'], ['version' => 1], false];

        yield 'an empty mapping against the empty list it is decoded as' => [['metadata' => new \stdClass()], ['metadata' => []], true];

        yield 'an integer-keyed mapping against the list it is decoded as' => [
            ['steps' => (object) ['plan', 'ship']],
            ['steps' => ['plan', 'ship']],
            true,
        ];

        yield 'an integer-keyed mapping against another' => [['steps' => (object) ['plan', 'ship']], ['steps' => (object) ['plan', 'halt']], false];

        yield 'a whole float against the integer it is sent as' => [['version' => 1.0], ['version' => 1], true];

        yield 'a fractional float against an integer' => [['version' => 1.5], ['version' => 1], false];

        yield 'a list in another order' => [['tags' => ['a', 'b']], ['tags' => ['b', 'a']], false];

        yield 'scalars' => ['a', 'a', true];
    }
}
