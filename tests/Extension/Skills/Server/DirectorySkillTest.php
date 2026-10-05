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

namespace Nexus\Mcp\Tests\Extension\Skills\Server;

use Nexus\Mcp\Extension\Skills\Schema\SkillResource;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Extension\Skills\TemporarySkillDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(DirectorySkill::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class DirectorySkillTest extends AbstractMcpTestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/Extension/Skills';

    private TemporarySkillDirectory $directory;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = new TemporarySkillDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->directory->remove();

        parent::tearDown();
    }

    public function testASkillIsReadFromItsDirectory(): void
    {
        $skill = new DirectorySkill('git-workflow', self::FIXTURES.'/git-workflow');

        self::assertSame('skill://git-workflow/SKILL.md', $skill->entry->uri);
        self::assertSame(
            [
                'name' => 'git-workflow',
                'description' => 'Follow the team\'s Git conventions for branching and commits.',
                'license' => 'Apache-2.0',
                'metadata' => ['version' => '2.1.0', 'updated' => '2026-05-01'],
            ],
            $skill->entry->frontmatter,
        );
        self::assertSame(
            [
                'skill://git-workflow/SKILL.md' => 'text/markdown',
                'skill://git-workflow/references/GUIDE.md' => 'text/markdown',
                'skill://git-workflow/scripts/sync.sh' => 'application/x-sh',
                'skill://git-workflow/templates/commit/message.md' => 'text/markdown',
            ],
            array_map(static fn($file): ?string => $file->mimeType, $skill->files),
        );
    }

    public function testEveryFileIsHeldUnderItsOwnUriAndDescribedInTheManifest(): void
    {
        $skill = new DirectorySkill('git-workflow', self::FIXTURES.'/git-workflow');
        $guide = (string) file_get_contents(self::FIXTURES.'/git-workflow/references/GUIDE.md');
        $guideUri = 'skill://git-workflow/references/GUIDE.md';
        $resources = $skill->entry->resources;

        self::assertArrayHasKey($guideUri, $skill->files);
        self::assertSame($guide, $skill->files[$guideUri]->contents);
        self::assertSame($guideUri, $skill->files[$guideUri]->uri);
        self::assertIsArray($resources);
        self::assertSame('skill://git-workflow', $skill->rootUri);
        self::assertSame(array_keys($skill->files), array_map(static fn(SkillResource $resource): string => $resource->uri, $resources));
        self::assertArrayHasKey(1, $resources);
        self::assertSame('sha256:'.hash('sha256', $guide), $resources[1]->digest);
        self::assertSame(\strlen($guide), $resources[1]->size);
    }

    public function testASkillPathMayNestTheSkillUnderOtherSegments(): void
    {
        $skill = new DirectorySkill('Acme.v2/team_a/refunds', self::FIXTURES.'/refunds');

        self::assertSame('skill://Acme.v2/team_a/refunds/SKILL.md', $skill->entry->uri);
        self::assertSame(['skill://Acme.v2/team_a/refunds/SKILL.md'], array_keys($skill->files));
    }

    public function testAnEmptyFrontmatterMappingIsSentAsAnObject(): void
    {
        $this->directory->write('SKILL.md', "---\nname: bare\ndescription: A skill.\nmetadata: {}\n---\n");

        self::assertStringContainsString(
            '"frontmatter":{"name":"bare","description":"A skill.","metadata":{}}',
            json_encode((new DirectorySkill('bare', $this->directory->path))->entry, \JSON_THROW_ON_ERROR),
        );
    }

    public function testASkillNameMayRunToSixtyFourCharacters(): void
    {
        $name = str_repeat('a', 64);
        $this->directory->writeManifest($name);

        self::assertSame($name, (new DirectorySkill($name, $this->directory->path))->entry->frontmatter['name']);
    }

    public function testDotPrefixedEntriesAreLeftOutWithEverythingBeneathThem(): void
    {
        $this->directory->writeManifest('public-only');
        $this->directory->write('.env', 'SECRET=1');
        $this->directory->write('.git/config', '[remote]');
        $this->directory->write('docs/.draft.md', 'draft');
        $this->directory->write('docs/guide.md', 'guide');

        $skill = new DirectorySkill('public-only', $this->directory->path);

        self::assertSame(['skill://public-only/SKILL.md', 'skill://public-only/docs/guide.md'], array_keys($skill->files));
    }

    public function testSupportingFilesFollowTheManifestInByteOrderWhateverTheFilesystemReturns(): void
    {
        $this->directory->writeManifest('walk');

        foreach (['B.css', 'NOTES.TXT', 'a b.md', 'blob.bin', 'one/deep/q.md', 'one/x.md', 'two/y.md', 'two/z.md'] as $relativePath) {
            $this->directory->write($relativePath, "\xff".$relativePath);
        }

        $skill = new DirectorySkill('walk', $this->directory->path);

        self::assertSame(
            [
                'skill://walk/SKILL.md' => 'text/markdown',
                'skill://walk/B.css' => 'text/css',
                'skill://walk/NOTES.TXT' => 'text/plain',
                'skill://walk/a%20b.md' => 'text/markdown',
                'skill://walk/blob.bin' => 'application/octet-stream',
                'skill://walk/one/deep/q.md' => 'text/markdown',
                'skill://walk/one/x.md' => 'text/markdown',
                'skill://walk/two/y.md' => 'text/markdown',
                'skill://walk/two/z.md' => 'text/markdown',
            ],
            array_map(static fn($file): ?string => $file->mimeType, $skill->files),
        );
        self::assertSame("\xffa b.md", ($skill->files['skill://walk/a%20b.md'] ?? null)?->contents);
    }

    public function testADirectoryWrittenWithATrailingSlashReadsTheSame(): void
    {
        $skill = new DirectorySkill('git-workflow', self::FIXTURES.'/git-workflow/');

        self::assertCount(4, $skill->files);
    }

    public function testLinksAreLeftOut(): void
    {
        $this->directory->writeManifest('linked');
        $target = $this->directory->write('real/notes.md', 'notes');

        if (! @symlink($target, $this->directory->path.'/alias.md') || ! @symlink(\dirname($target), $this->directory->path.'/alias')) {
            self::markTestSkipped('This platform refuses to create symbolic links.');
        }

        $skill = new DirectorySkill('linked', $this->directory->path);

        self::assertSame(['skill://linked/SKILL.md', 'skill://linked/real/notes.md'], array_keys($skill->files));
    }

    public function testALinkedManifestIsNotAManifest(): void
    {
        $target = $this->directory->write('elsewhere.md', "---\nname: linked\ndescription: A skill.\n---\n");
        mkdir($this->directory->path.'/skill');

        if (! @symlink($target, $this->directory->path.'/skill/SKILL.md')) {
            self::markTestSkipped('This platform refuses to create symbolic links.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Skill directory "%s/skill" holds no "SKILL.md".', $this->directory->path));

        new DirectorySkill('linked', $this->directory->path.'/skill');
    }

    public function testAManifestNamedInAnotherCaseIsNotAManifest(): void
    {
        $this->directory->write('skill.md', "---\nname: lower\ndescription: A skill.\n---\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Skill directory "%s" holds no "SKILL.md".', $this->directory->path));

        new DirectorySkill('lower', $this->directory->path);
    }

    public function testADirectoryThatDoesNotExistIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Skill directory "%s/absent" does not exist.', $this->directory->path));

        new DirectorySkill('absent', $this->directory->path.'/absent');
    }

    public function testADirectoryWithoutAManifestIsRefused(): void
    {
        $this->directory->write('notes.md', 'notes');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Skill directory "%s" holds no "SKILL.md".', $this->directory->path));

        new DirectorySkill('notes', $this->directory->path);
    }

    public function testAnUnreadableFileIsRefused(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('Windows ACLs ignore POSIX modes, so the refusal cannot be provoked with chmod.');
        }

        $this->directory->writeManifest('locked');
        $path = $this->directory->write('secret.md', 'secret');
        chmod($path, 0o000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Skill file "%s" could not be read.', $path));

        new DirectorySkill('locked', $this->directory->path);
    }

    #[DataProvider('provideAMalformedSkillPathIsRefusedCases')]
    public function testAMalformedSkillPathIsRefused(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Skill path must be "/"-separated URI-safe segments ending in a valid skill name, \'%s\' given.',
            $path,
        ));

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new DirectorySkill($path, self::FIXTURES.'/refunds');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAMalformedSkillPathIsRefusedCases(): iterable
    {
        yield 'empty' => [''];

        yield 'a parent segment' => ['../refunds'];

        yield 'a current-directory segment' => ['acme/./refunds'];

        yield 'a percent-encoded segment' => ['acme%20inc/refunds'];

        yield 'a name in upper case' => ['acme/Refunds'];

        yield 'a name with an underscore' => ['acme/re_funds'];

        yield 'a name with consecutive hyphens' => ['acme/re--funds'];

        yield 'a name ending in a hyphen' => ['acme/refunds-'];

        yield 'a name of sixty-five characters' => [str_repeat('a', 65)];

        yield 'leading slash' => ['/refunds'];

        yield 'trailing slash' => ['refunds/'];

        yield 'empty segment' => ['acme//refunds'];

        yield 'whitespace' => ['acme billing/refunds'];

        yield 'query' => ['refunds?x'];

        yield 'fragment' => ['refunds#x'];
    }
}
