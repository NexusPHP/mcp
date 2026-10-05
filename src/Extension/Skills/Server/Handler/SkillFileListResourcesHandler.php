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

namespace Nexus\Mcp\Extension\Skills\Server\Handler;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Extension\Skills\Server\Store\SkillStoreInterface;
use Nexus\Mcp\Extension\Skills\Skills;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\ServerContext;

/**
 * Decorates the `resources/list` handler so the skill files follow the server's other resources.
 *
 * @internal
 *
 * @implements RequestHandlerInterface<'resources/list', Result, ServerContext>
 */
final readonly class SkillFileListResourcesHandler implements RequestHandlerInterface
{
    private const string SKILL_CURSOR_PREFIX = Skills::IDENTIFIER.':';

    /**
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $inner
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private SkillStoreInterface $store,
    ) {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): Result
    {
        \assert($request instanceof ListResourcesRequest);
        \assert($context instanceof ServerContext);

        $cursor = $request->params->cursor?->cursor;

        if (null !== $cursor && str_starts_with($cursor, self::SKILL_CURSOR_PREFIX)) {
            $skillCursor = substr($cursor, \strlen(self::SKILL_CURSOR_PREFIX));

            if ('' === $skillCursor) {
                throw new InvalidCursorException($cursor, $context->requestId);
            }

            $page = $this->store->listResources(new Cursor($skillCursor));

            return new ListResourcesResult(
                $page->resources,
                $page->ttlMs,
                $page->cacheScope,
                $this->markAsSkillCursor($page->nextCursor),
                $page->meta,
            );
        }

        $result = $this->inner->handle($request, $context);

        if (! $result instanceof ListResourcesResult || null !== $result->nextCursor) {
            return $result;
        }

        $page = $this->store->listResources(null);

        return new ListResourcesResult(
            [...$result->resources, ...$page->resources],
            min($result->ttlMs, $page->ttlMs),
            CacheScope::Public === $page->cacheScope ? $result->cacheScope : CacheScope::Private,
            $this->markAsSkillCursor($page->nextCursor),
            $result->meta,
        );
    }

    private function markAsSkillCursor(?Cursor $cursor): ?Cursor
    {
        return null === $cursor ? null : new Cursor(self::SKILL_CURSOR_PREFIX.$cursor->cursor);
    }
}
