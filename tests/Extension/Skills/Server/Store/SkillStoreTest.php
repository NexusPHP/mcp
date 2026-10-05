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

namespace Nexus\Mcp\Tests\Extension\Skills\Server\Store;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\SkillFile;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStore;
use Nexus\Mcp\Extension\Skills\Skills;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Extension\Skills\ArraySkillProvider;
use Nexus\Mcp\Tests\Fixtures\Extension\Skills\TemporarySkillDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

/**
 * @internal
 */
#[CoversClass(SkillStore::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillStoreTest extends AbstractMcpTestCase
{
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/Extension/Skills';
    private const string GIT = 'skill://git-workflow';
    private const string LIMITS_WARNING = 'Skill {uri} exceeds the limits accepted by every host ({files} files, {bytes} bytes), so a host may decline it.';

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

    public function testListEnumeratesTheSkillsInRegistrationOrder(): void
    {
        $git = self::loadSkill('git-workflow');
        $refunds = self::loadSkill('refunds');

        $result = (new SkillStore([$git, $refunds]))->list(null);

        self::assertSame([$git->entry, $refunds->entry], $result->skills);
        self::assertNull($result->nextCursor);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testAStoreHoldingNoSkillListsNone(): void
    {
        $store = new SkillStore();

        self::assertSame([], $store->list(null)->skills);
        self::assertSame([], $store->listResources(null)->resources);
    }

    public function testListPaginates(): void
    {
        $git = self::loadSkill('git-workflow');
        $refunds = self::loadSkill('refunds');
        $store = new SkillStore([$git, $refunds], pageSize: 1);

        $first = $store->list(null);
        $second = $store->list($first->nextCursor);

        self::assertSame([$git->entry], $first->skills);
        self::assertSame(self::GIT.'/SKILL.md', $first->nextCursor?->cursor);
        self::assertSame([$refunds->entry], $second->skills);
        self::assertNull($second->nextCursor);
    }

    public function testAnUnknownCursorIsRefused(): void
    {
        $store = new SkillStore([self::loadSkill('refunds')]);

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageIs('Cursor "nope" does not match any registered entry.');

        $store->list(new Cursor('nope'));
    }

    public function testEveryResultCarriesTheConfiguredCachePolicy(): void
    {
        $store = new SkillStore([self::loadSkill('refunds')], ttlMs: 60_000, cacheScope: CacheScope::Public);
        $context = self::buildContext();
        $results = [
            $store->list(null),
            $store->get('skill://refunds/SKILL.md', $context),
            $store->listResources(null),
            $store->readFile('skill://refunds/SKILL.md', $context),
        ];

        foreach ($results as $result) {
            self::assertSame(60_000, $result?->ttlMs);
            self::assertSame(CacheScope::Public, $result->cacheScope);
        }
    }

    public function testGetReturnsAListedSkillWithoutConsultingTheProvider(): void
    {
        $git = self::loadSkill('git-workflow');
        $provider = new ArraySkillProvider();

        $result = (new SkillStore([$git], $provider))->get(self::GIT.'/SKILL.md', self::buildContext());

        self::assertSame($git->entry, $result->skill);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertSame([], $provider->lookups);
    }

    public function testGetFallsBackToTheProviderForAnUnlistedSkill(): void
    {
        $entry = new Skill('skill://reports/q3/SKILL.md', ['name' => 'q3', 'description' => 'Quarterly report.'], 'dynamic');
        $provider = new ArraySkillProvider(skills: [$entry->uri => $entry]);
        $store = new SkillStore([self::loadSkill('refunds')], $provider);

        self::assertSame($entry, $store->get($entry->uri, self::buildContext())->skill);
        self::assertSame([['findSkill', $entry->uri]], $provider->lookups);
        self::assertCount(1, $store->list(null)->skills);
    }

    public function testGetRefusesASkillNeitherListedNorProvided(): void
    {
        $store = new SkillStore([self::loadSkill('refunds')], new ArraySkillProvider());

        try {
            $store->get('skill://missing/SKILL.md', self::buildContext());
            self::fail('The lookup did not throw.');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('Resource "skill://missing/SKILL.md" not found.', $e->getMessage());
            self::assertSame(7, $e->requestId?->id);
        }
    }

    public function testGetRefusesAnUnlistedSkillWhenNoProviderIsSet(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageIs('Resource "skill://missing/SKILL.md" not found.');

        (new SkillStore())->get('skill://missing/SKILL.md', self::buildContext());
    }

    public function testListResourcesDescribesEverySkillFile(): void
    {
        $store = new SkillStore([self::loadSkill('git-workflow'), self::loadSkill('refunds')]);

        $result = $store->listResources(null);

        self::assertSame(
            [
                ['git-workflow', self::GIT.'/SKILL.md', 'Follow the team\'s Git conventions for branching and commits.', 'text/markdown', self::sizeOf('git-workflow/SKILL.md')],
                ['GUIDE.md', self::GIT.'/references/GUIDE.md', null, 'text/markdown', self::sizeOf('git-workflow/references/GUIDE.md')],
                ['sync.sh', self::GIT.'/scripts/sync.sh', null, 'application/x-sh', self::sizeOf('git-workflow/scripts/sync.sh')],
                ['message.md', self::GIT.'/templates/commit/message.md', null, 'text/markdown', self::sizeOf('git-workflow/templates/commit/message.md')],
                ['refunds', 'skill://refunds/SKILL.md', 'Process customer refund requests per company policy.', 'text/markdown', self::sizeOf('refunds/SKILL.md')],
            ],
            self::summarise($result->resources),
        );
        self::assertNull($result->nextCursor);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testListResourcesPaginates(): void
    {
        $store = new SkillStore([self::loadSkill('git-workflow')], pageSize: 3);

        $first = $store->listResources(null);
        $second = $store->listResources($first->nextCursor);

        self::assertCount(3, $first->resources);
        self::assertSame(self::GIT.'/scripts/sync.sh', $first->nextCursor?->cursor);
        self::assertSame(['message.md'], array_column(self::summarise($second->resources), 0));
        self::assertNull($second->nextCursor);
    }

    public function testReadFileServesAListedFileWithoutConsultingTheProvider(): void
    {
        $provider = new ArraySkillProvider();
        $store = new SkillStore([self::loadSkill('git-workflow')], $provider);

        $result = $store->readFile(self::GIT.'/references/GUIDE.md', self::buildContext());

        self::assertNotNull($result);
        self::assertCount(1, $result->contents);
        self::assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        self::assertSame(self::GIT.'/references/GUIDE.md', $result->contents[0]->uri);
        self::assertSame(file_get_contents(self::FIXTURES.'/git-workflow/references/GUIDE.md'), $result->contents[0]->text);
        self::assertSame('text/markdown', $result->contents[0]->mimeType);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertSame([], $provider->lookups);
    }

    public function testReadFileFallsBackToTheProvider(): void
    {
        $file = new SkillFile('skill://reports/q3/chart.png', "\x89PNG\0", 'image/png');
        $provider = new ArraySkillProvider(files: [$file->uri => $file]);
        $store = new SkillStore(provider: $provider);

        $result = $store->readFile($file->uri, self::buildContext());

        self::assertNotNull($result);
        self::assertArrayHasKey(0, $result->contents);
        self::assertInstanceOf(BlobResourceContents::class, $result->contents[0]);
        self::assertSame(base64_encode("\x89PNG\0"), $result->contents[0]->blob);
        self::assertSame([['findFile', $file->uri]], $provider->lookups);
    }

    public function testReadFileAnswersNothingForAUriItDoesNotServe(): void
    {
        $provider = new ArraySkillProvider();

        self::assertNull((new SkillStore([self::loadSkill('refunds')]))->readFile('skill://refunds/missing.md', self::buildContext()));
        self::assertNull((new SkillStore(provider: $provider))->readFile('skill://reports/q3/missing.md', self::buildContext()));
        self::assertSame([['findFile', 'skill://reports/q3/missing.md']], $provider->lookups);
    }

    public function testReadFileDoesNotAskTheProviderAboutAUriOutsideTheSkillScheme(): void
    {
        $file = new SkillFile('file:///etc/hosts', 'hosts');
        $provider = new ArraySkillProvider(files: [$file->uri => $file]);

        self::assertNull((new SkillStore(provider: $provider))->readFile('file:///etc/hosts', self::buildContext()));
        self::assertSame([], $provider->lookups);
    }

    public function testReadDirectoryListsTheDirectChildrenOfASkillRoot(): void
    {
        $store = new SkillStore([self::loadSkill('git-workflow')]);

        $result = $store->readDirectory(self::GIT, null, self::buildContext());

        self::assertSame(
            [
                ['git-workflow', self::GIT.'/SKILL.md', 'Follow the team\'s Git conventions for branching and commits.', 'text/markdown', self::sizeOf('git-workflow/SKILL.md')],
                ['references', self::GIT.'/references', null, Skills::DIRECTORY_MIME_TYPE, null],
                ['scripts', self::GIT.'/scripts', null, Skills::DIRECTORY_MIME_TYPE, null],
                ['templates', self::GIT.'/templates', null, Skills::DIRECTORY_MIME_TYPE, null],
            ],
            self::summarise($result->resources),
        );
        self::assertNull($result->nextCursor);
    }

    public function testReadDirectoryListsANestedDirectory(): void
    {
        $store = new SkillStore([self::loadSkill('git-workflow')]);
        $context = self::buildContext();

        self::assertSame(
            [['commit', self::GIT.'/templates/commit', null, Skills::DIRECTORY_MIME_TYPE, null]],
            self::summarise($store->readDirectory(self::GIT.'/templates', null, $context)->resources),
        );
        self::assertSame(
            [['message.md', self::GIT.'/templates/commit/message.md', null, 'text/markdown', self::sizeOf('git-workflow/templates/commit/message.md')]],
            self::summarise($store->readDirectory(self::GIT.'/templates/commit', null, $context)->resources),
        );
    }

    public function testADirectoryHoldingSeveralFilesListsEachOnce(): void
    {
        $this->directory->writeManifest('walk');
        $this->directory->write('docs/a.md', 'a');
        $this->directory->write('docs/b.md', 'b');
        $store = new SkillStore([new DirectorySkill('walk', $this->directory->path)]);

        self::assertSame(
            ['walk', 'docs'],
            array_column(self::summarise($store->readDirectory('skill://walk', null, self::buildContext())->resources), 0),
        );
        self::assertSame(
            ['a.md', 'b.md'],
            array_column(self::summarise($store->readDirectory('skill://walk/docs', null, self::buildContext())->resources), 0),
        );
    }

    public function testNamesAreTheDecodedPathSegments(): void
    {
        $store = new SkillStore([self::loadSkill('release-notes')]);
        $context = self::buildContext();

        self::assertSame(
            [
                ['release-notes', 'skill://release-notes/SKILL.md'],
                ['style guide', 'skill://release-notes/style%20guide'],
            ],
            self::listNamesAndUris($store->readDirectory('skill://release-notes', null, $context)->resources),
        );
        self::assertSame(
            [['tone & voice.md', 'skill://release-notes/style%20guide/tone%20%26%20voice.md']],
            self::listNamesAndUris($store->readDirectory('skill://release-notes/style%20guide', null, $context)->resources),
        );
    }

    public function testReadDirectoryPaginates(): void
    {
        $store = new SkillStore([self::loadSkill('git-workflow')], pageSize: 3);
        $context = self::buildContext();

        $first = $store->readDirectory(self::GIT, null, $context);
        $second = $store->readDirectory(self::GIT, $first->nextCursor, $context);

        self::assertCount(3, $first->resources);
        self::assertSame(self::GIT.'/scripts', $first->nextCursor?->cursor);
        self::assertSame(['templates'], array_column(self::summarise($second->resources), 0));
        self::assertNull($second->nextCursor);
    }

    public function testReadDirectoryFallsBackToTheProvider(): void
    {
        $children = [
            new Resource('chart.png', 'skill://reports/q3/chart.png', mimeType: 'image/png'),
            new Resource('data', 'skill://reports/q3/data', mimeType: Skills::DIRECTORY_MIME_TYPE),
        ];
        $provider = new ArraySkillProvider(directories: ['skill://reports/q3' => $children]);
        $store = new SkillStore([self::loadSkill('refunds')], $provider, pageSize: 1);
        $context = self::buildContext();

        $first = $store->readDirectory('skill://reports/q3', null, $context);
        $second = $store->readDirectory('skill://reports/q3', $first->nextCursor, $context);

        self::assertSame([$children[0]], $first->resources);
        self::assertSame('skill://reports/q3/chart.png', $first->nextCursor?->cursor);
        self::assertSame([$children[1]], $second->resources);
        self::assertSame([['findDirectory', 'skill://reports/q3'], ['findDirectory', 'skill://reports/q3']], $provider->lookups);
    }

    public function testReadDirectoryDoesNotConsultTheProviderForAListedDirectory(): void
    {
        $provider = new ArraySkillProvider();

        (new SkillStore([self::loadSkill('refunds')], $provider))->readDirectory('skill://refunds', null, self::buildContext());

        self::assertSame([], $provider->lookups);
    }

    public function testReadResourceDirectoryRefusesAUriThatIsNotADirectory(): void
    {
        $store = new SkillStore([self::loadSkill('git-workflow')], new ArraySkillProvider());

        try {
            $store->readDirectory(self::GIT.'/SKILL.md', null, self::buildContext());
            self::fail('The lookup did not throw.');
        } catch (ResourceNotFoundException $e) {
            self::assertSame('Resource "skill://git-workflow/SKILL.md" not found.', $e->getMessage());
            self::assertSame(7, $e->requestId?->id);
        }
    }

    public function testReadResourceDirectoryRefusesAnUnknownDirectoryWhenNoProviderIsSet(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageIs('Resource "skill://missing" not found.');

        (new SkillStore())->readDirectory('skill://missing', null, self::buildContext());
    }

    public function testASkillRegisteredTwiceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Skill "skill://refunds/SKILL.md" is registered more than once.');

        new SkillStore([self::loadSkill('refunds'), self::loadSkill('refunds')]);
    }

    #[DataProvider('provideANestedSkillIsListedOnItsOwnAndInTheManifestOfTheSkillAroundItCases')]
    public function testANestedSkillIsListedOnItsOwnAndInTheManifestOfTheSkillAroundIt(bool $nestedFirst): void
    {
        $this->directory->writeManifest('outer');
        $this->directory->write('inner/SKILL.md', "---\nname: inner\ndescription: The nested one.\n---\n");
        $this->directory->write('inner/notes.md', 'notes');
        $outer = new DirectorySkill('outer', $this->directory->path);
        $inner = new DirectorySkill('outer/inner', $this->directory->path.'/inner');
        $store = new SkillStore($nestedFirst ? [$inner, $outer] : [$outer, $inner]);
        $context = self::buildContext();

        self::assertCount(2, $store->list(null)->skills);
        self::assertSame(
            ['skill://outer/SKILL.md', 'skill://outer/inner/SKILL.md', 'skill://outer/inner/notes.md'],
            array_keys($outer->files),
        );

        $listed = [];

        foreach (self::summarise($store->listResources(null)->resources) as [$name, $uri, $description]) {
            $listed[$uri] = [$name, $description];
        }

        ksort($listed);

        self::assertSame(
            [
                'skill://outer/SKILL.md' => ['outer', 'A skill.'],
                'skill://outer/inner/SKILL.md' => ['inner', 'The nested one.'],
                'skill://outer/inner/notes.md' => ['notes.md', null],
            ],
            $listed,
        );
        self::assertCount(3, $store->listResources(null)->resources);
        self::assertSame(
            [['inner', 'skill://outer/inner/SKILL.md'], ['notes.md', 'skill://outer/inner/notes.md']],
            self::listNamesAndUris($store->readDirectory('skill://outer/inner', null, $context)->resources),
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideANestedSkillIsListedOnItsOwnAndInTheManifestOfTheSkillAroundItCases(): iterable
    {
        yield 'the nested skill registered first' => [true];

        yield 'the enclosing skill registered first' => [false];
    }

    public function testTwoSkillsServingOneUriWithDifferentBytesAreRefused(): void
    {
        $elsewhere = new TemporarySkillDirectory();

        try {
            $this->directory->writeManifest('outer');
            $this->directory->write('inner/SKILL.md', "---\nname: inner\ndescription: The nested one.\n---\n");
            $elsewhere->write('SKILL.md', "---\nname: inner\ndescription: Another one.\n---\n");
            $outer = new DirectorySkill('outer', $this->directory->path);
            $inner = new DirectorySkill('outer/inner', $elsewhere->path);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageIs('Skill file "skill://outer/inner/SKILL.md" is served with different contents by two skills.');

            new SkillStore([$outer, $inner]);
        } finally {
            $elsewhere->remove();
        }
    }

    public function testANonListOfSkillsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Skill store skills must be a list, non-list array given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new SkillStore([3 => self::loadSkill('refunds')]);
    }

    public function testAnEntryThatIsNotASkillIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new SkillStore(['refunds']);
    }

    public function testANonPositivePageSizeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Skill store page size must be a positive integer, 0 given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new SkillStore(pageSize: 0);
    }

    public function testANegativeTtlIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Skill store TTL must be a non-negative integer, -1 given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new SkillStore(ttlMs: -1);
    }

    public function testASkillAtTheFileLimitRaisesNoWarning(): void
    {
        $this->directory->writeManifest('big');

        for ($i = 1; $i < Skills::MAX_RESOURCES_PER_SKILL; ++$i) {
            $this->directory->write(\sprintf('f%d.txt', $i), 'x');
        }

        $logger = new ArrayLogger();
        new SkillStore([new DirectorySkill('big', $this->directory->path)], logger: $logger);

        self::assertSame([], $logger->records);
    }

    public function testASkillOverTheFileLimitRaisesAWarning(): void
    {
        $manifest = $this->directory->writeManifest('big');

        for ($i = 1; $i <= Skills::MAX_RESOURCES_PER_SKILL; ++$i) {
            $this->directory->write(\sprintf('f%d.txt', $i), 'x');
        }

        $logger = new ArrayLogger();
        new SkillStore([new DirectorySkill('big', $this->directory->path)], logger: $logger);

        self::assertSame(
            [[
                'level' => LogLevel::WARNING,
                'message' => self::LIMITS_WARNING,
                'context' => ['uri' => 'skill://big/SKILL.md', 'files' => 513, 'bytes' => (int) filesize($manifest) + 512],
            ]],
            $logger->records,
        );
    }

    public function testASkillAtTheByteLimitRaisesNoWarning(): void
    {
        $manifest = $this->directory->writeManifest('big');
        $this->directory->write('pad.bin', str_repeat('x', Skills::MAX_BYTES_PER_SKILL - (int) filesize($manifest)));

        $logger = new ArrayLogger();
        new SkillStore([new DirectorySkill('big', $this->directory->path)], logger: $logger);

        self::assertSame([], $logger->records);
    }

    public function testASkillOverTheByteLimitRaisesAWarning(): void
    {
        $manifest = $this->directory->writeManifest('big');
        $this->directory->write('pad.bin', str_repeat('x', Skills::MAX_BYTES_PER_SKILL - (int) filesize($manifest) + 1));

        $logger = new ArrayLogger();
        new SkillStore([new DirectorySkill('big', $this->directory->path)], logger: $logger);

        self::assertSame(
            [[
                'level' => LogLevel::WARNING,
                'message' => self::LIMITS_WARNING,
                'context' => ['uri' => 'skill://big/SKILL.md', 'files' => 2, 'bytes' => Skills::MAX_BYTES_PER_SKILL + 1],
            ]],
            $logger->records,
        );
    }

    /**
     * @param non-empty-string $name
     */
    private static function loadSkill(string $name): DirectorySkill
    {
        return new DirectorySkill($name, self::FIXTURES.'/'.$name);
    }

    private static function sizeOf(string $relativePath): int
    {
        return (int) filesize(self::FIXTURES.'/'.$relativePath);
    }

    /**
     * @param list<Resource> $resources
     *
     * @return list<array{string, string, null|string, null|string, null|int}>
     */
    private static function summarise(array $resources): array
    {
        return array_map(
            static fn(Resource $resource): array => [$resource->name, $resource->uri, $resource->description, $resource->mimeType, $resource->size],
            $resources,
        );
    }

    /**
     * @param list<Resource> $resources
     *
     * @return list<array{string, string}>
     */
    private static function listNamesAndUris(array $resources): array
    {
        return array_map(static fn(Resource $resource): array => [$resource->name, $resource->uri], $resources);
    }

    private static function buildContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
