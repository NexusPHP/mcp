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

namespace Nexus\Mcp\Extension\Skills\Server;

use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Server\ServerContext;

/**
 * Source of unlisted skills, such as a set too large to enumerate or one whose content is generated on request.
 */
interface SkillProviderInterface
{
    /**
     * The entry of the skill whose `SKILL.md` is at the URI, or null when the provider serves no skill there.
     *
     * @param non-empty-string $uri
     */
    public function findSkill(string $uri, ServerContext $context): ?Skill;

    /**
     * The file at the URI, or null when the provider serves none there.
     *
     * @param non-empty-string $uri
     */
    public function findFile(string $uri, ServerContext $context): ?SkillFile;

    /**
     * The direct children of the directory at the URI, or null when the provider serves no directory there.
     *
     * @param non-empty-string $uri
     *
     * @return null|list<Resource>
     */
    public function findDirectory(string $uri, ServerContext $context): ?array;
}
