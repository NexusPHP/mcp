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

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\GetSkillResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\ListSkillsResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\ReadResourceDirectoryResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;

/**
 * The skills served by a server, with the files and directories that make them up.
 */
interface SkillStoreInterface
{
    /**
     * @throws InvalidCursorException
     */
    public function list(?Cursor $cursor): ListSkillsResult;

    /**
     * @param non-empty-string $uri URI of the skill's `SKILL.md`
     *
     * @throws ResourceNotFoundException
     */
    public function get(string $uri, ServerContext $context): GetSkillResult;

    /**
     * The skill files enumerated by `resources/list`.
     *
     * @throws InvalidCursorException
     */
    public function listResources(?Cursor $cursor): ListResourcesResult;

    /**
     * The file at the URI, or null when the store serves no skill file there.
     *
     * @param non-empty-string $uri
     */
    public function readFile(string $uri, ServerContext $context): ?ReadResourceResult;

    /**
     * @param non-empty-string $uri URI of the directory, written without a trailing slash
     *
     * @throws InvalidCursorException
     * @throws ResourceNotFoundException
     */
    public function readDirectory(string $uri, ?Cursor $cursor, ServerContext $context): ReadResourceDirectoryResult;
}
