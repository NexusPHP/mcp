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

namespace Nexus\Mcp\Tests\Fixtures\Server\Tool;

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;

/**
 * Tool store double that serves a fixed sequence of pages and counts how often it was listed. A walk over
 * the pages visits each one once, so the double refuses to serve a page it has already handed out.
 *
 * @internal
 */
final class PagedToolStore implements ToolStoreInterface
{
    public int $listCalls = 0;

    /**
     * @var list<int>
     */
    private array $served = [];

    /**
     * @param list<list<Tool>> $pages One entry per page, in cursor order
     */
    public function __construct(private readonly array $pages)
    {
    }

    #[\Override]
    public function list(?Cursor $cursor): ListToolsResult
    {
        ++$this->listCalls;

        $index = null === $cursor ? 0 : (int) $cursor->cursor;

        if (\in_array($index, $this->served, true)) {
            throw new \LogicException(\sprintf('The fixture was asked for page %d a second time.', $index));
        }

        $this->served[] = $index;
        $next = $index + 1;

        return new ListToolsResult(
            tools: $this->pages[$index] ?? [],
            ttlMs: 0,
            cacheScope: CacheScope::Private,
            nextCursor: \array_key_exists($next, $this->pages) ? new Cursor(cursor: (string) $next) : null,
        );
    }

    #[\Override]
    public function call(string $name, ?array $arguments, ServerContext $context): CallToolResult
    {
        throw new \LogicException('The fixture serves listings only.');
    }
}
