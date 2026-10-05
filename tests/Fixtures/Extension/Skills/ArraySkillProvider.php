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

namespace Nexus\Mcp\Tests\Fixtures\Extension\Skills;

use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Server\SkillFile;
use Nexus\Mcp\Extension\Skills\Server\SkillProviderInterface;
use Nexus\Mcp\Server\ServerContext;

/**
 * A skill provider answering from fixed maps and recording every lookup.
 *
 * @internal
 */
final class ArraySkillProvider implements SkillProviderInterface
{
    /**
     * @var list<array{non-empty-string, non-empty-string}>
     */
    public array $lookups = [];

    /**
     * @param array<non-empty-string, Skill>          $skills
     * @param array<non-empty-string, SkillFile>      $files
     * @param array<non-empty-string, list<Resource>> $directories
     */
    public function __construct(
        private readonly array $skills = [],
        private readonly array $files = [],
        private readonly array $directories = [],
    ) {
    }

    #[\Override]
    public function findSkill(string $uri, ServerContext $context): ?Skill
    {
        $this->lookups[] = ['findSkill', $uri];

        return $this->skills[$uri] ?? null;
    }

    #[\Override]
    public function findFile(string $uri, ServerContext $context): ?SkillFile
    {
        $this->lookups[] = ['findFile', $uri];

        return $this->files[$uri] ?? null;
    }

    #[\Override]
    public function findDirectory(string $uri, ServerContext $context): ?array
    {
        $this->lookups[] = ['findDirectory', $uri];

        return $this->directories[$uri] ?? null;
    }
}
