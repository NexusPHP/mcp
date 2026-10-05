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

namespace Nexus\Mcp\Extension\Skills\Server\Store;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\GetSkillResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\ListSkillsResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\ReadResourceDirectoryResult;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Server\DirectorySkill;
use Nexus\Mcp\Extension\Skills\Server\SkillFile;
use Nexus\Mcp\Extension\Skills\Server\SkillProviderInterface;
use Nexus\Mcp\Extension\Skills\Skills;
use Nexus\Mcp\Server\CursorPaginator;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * In-memory implementation of `SkillStoreInterface`.
 */
final readonly class SkillStore implements SkillStoreInterface
{
    private CursorPaginator $paginator;

    /**
     * @var array<non-empty-string, Skill>
     */
    private array $entries;

    /**
     * @var array<non-empty-string, SkillFile>
     */
    private array $files;

    /**
     * @var array<non-empty-string, Resource>
     */
    private array $resources;

    /**
     * Children keyed by their own URI, under the URI of the directory that holds them.
     *
     * @var array<non-empty-string, array<non-empty-string, Resource>>
     */
    private array $directories;

    /**
     * @param list<DirectorySkill>        $skills   The skills enumerated by `skills/list`
     * @param null|SkillProviderInterface $provider Source of the skills left out of the listing
     * @param int<1, max>                 $pageSize
     * @param int<0, max>                 $ttlMs
     */
    public function __construct(
        array $skills = [],
        private ?SkillProviderInterface $provider = null,
        int $pageSize = CursorPaginator::DEFAULT_PAGE_SIZE,
        private int $ttlMs = 0,
        private CacheScope $cacheScope = CacheScope::Private,
        LoggerInterface $logger = new NullLogger(),
    ) {
        Assert::that($skills)
            ->isList('Skill store skills must be a list, non-list array given.')
            ->values()->isInstanceOf(DirectorySkill::class)
        ;
        Assert::that($pageSize)->isPositiveInt('Skill store page size must be a positive integer, {value} given.');
        Assert::that($ttlMs)->isNaturalInt('Skill store TTL must be a non-negative integer, {value} given.');

        $entries = [];
        $files = [];
        $resources = [];
        $directories = [];

        foreach ($skills as $skill) {
            $entry = $skill->entry;
            Assert::that($entries)->not()->hasOffset($entry->uri, \sprintf('Skill "%s" is registered more than once.', $entry->uri));

            $entries[$entry->uri] = $entry;
            $bytes = 0;

            foreach ($skill->files as $uri => $file) {
                $bytes += \strlen($file->contents);

                Assert::that(($files[$uri] ?? $file)->contents)->isIdentical(
                    $file->contents,
                    \sprintf('Skill file "%s" is served with different contents by two skills.', $uri),
                );

                $files[$uri] = $file;

                $segments = explode('/', substr($uri, \strlen($skill->rootUri) + 1));
                $fileName = array_pop($segments);
                $parentUri = $skill->rootUri;

                foreach ($segments as $segment) {
                    $directoryUri = $parentUri.'/'.$segment;
                    $directories[$parentUri][$directoryUri] = $this->describe($segment, $directoryUri, Skills::DIRECTORY_MIME_TYPE);
                    $parentUri = $directoryUri;
                }

                $resources[$uri] = $uri === $entry->uri
                    ? new Resource(
                        $entry->frontmatter['name'],
                        $uri,
                        description: $entry->frontmatter['description'],
                        mimeType: Skills::MANIFEST_MIME_TYPE,
                        size: \strlen($file->contents),
                    )
                    : $resources[$uri] ?? $this->describe($fileName, $uri, $file->mimeType, \strlen($file->contents));
                $directories[$parentUri][$uri] = $resources[$uri];
            }

            if (\count($skill->files) > Skills::MAX_RESOURCES_PER_SKILL || $bytes > Skills::MAX_BYTES_PER_SKILL) {
                $logger->warning(
                    'Skill {uri} exceeds the limits accepted by every host ({files} files, {bytes} bytes), so a host may decline it.',
                    ['uri' => $entry->uri, 'files' => \count($skill->files), 'bytes' => $bytes],
                );
            }
        }

        $this->entries = $entries;
        $this->files = $files;
        $this->resources = $resources;
        $this->directories = $directories;
        $this->paginator = new CursorPaginator($pageSize);
    }

    #[\Override]
    public function list(?Cursor $cursor): ListSkillsResult
    {
        $page = $this->paginator->paginate($this->entries, $cursor);

        return new ListSkillsResult($page->entries, $this->ttlMs, $this->cacheScope, $page->nextCursor);
    }

    #[\Override]
    public function get(string $uri, ServerContext $context): GetSkillResult
    {
        $entry = $this->entries[$uri]
            ?? $this->provider?->findSkill($uri, $context)
            ?? throw new ResourceNotFoundException($uri, $context->requestId);

        return new GetSkillResult($entry, $this->ttlMs, $this->cacheScope);
    }

    #[\Override]
    public function listResources(?Cursor $cursor): ListResourcesResult
    {
        $page = $this->paginator->paginate($this->resources, $cursor);

        return new ListResourcesResult($page->entries, $this->ttlMs, $this->cacheScope, $page->nextCursor);
    }

    #[\Override]
    public function readFile(string $uri, ServerContext $context): ?ReadResourceResult
    {
        $file = $this->files[$uri] ?? (str_starts_with($uri, Skills::URI_PREFIX) ? $this->provider?->findFile($uri, $context) : null);

        if (null === $file) {
            return null;
        }

        return new ReadResourceResult([$file->toResourceContents()], $this->ttlMs, $this->cacheScope);
    }

    #[\Override]
    public function readDirectory(string $uri, ?Cursor $cursor, ServerContext $context): ReadResourceDirectoryResult
    {
        $children = $this->directories[$uri]
            ?? $this->indexByUri($this->provider?->findDirectory($uri, $context))
            ?? throw new ResourceNotFoundException($uri, $context->requestId);

        $page = $this->paginator->paginate($children, $cursor);

        return new ReadResourceDirectoryResult($page->entries, $page->nextCursor);
    }

    /**
     * @param null|list<Resource> $children
     *
     * @return null|array<non-empty-string, Resource>
     */
    private function indexByUri(?array $children): ?array
    {
        if (null === $children) {
            return null;
        }

        $indexed = [];

        foreach ($children as $child) {
            $indexed[$child->uri] = $child;
        }

        return $indexed;
    }

    /**
     * @param string                $segment  The percent-encoded final path segment of the URI
     * @param non-empty-string      $uri
     * @param null|non-empty-string $mimeType
     * @param null|int<0, max>      $size
     */
    private function describe(string $segment, string $uri, ?string $mimeType, ?int $size = null): Resource
    {
        return new Resource(rawurldecode($segment), $uri, mimeType: $mimeType, size: $size);
    }
}
