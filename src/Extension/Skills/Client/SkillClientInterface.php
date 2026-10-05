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

namespace Nexus\Mcp\Extension\Skills\Client;

use Nexus\Mcp\Client\Exception\ServerCapabilityNotSupportedException;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Extension\Skills\Client\Exception\SkillVerificationFailedException;
use Nexus\Mcp\Extension\Skills\Schema\Result\ListSkillsResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\ReadResourceDirectoryResult;
use Nexus\Mcp\Extension\Skills\Schema\Skill;

/**
 * Client-side surface of the skills extension.
 */
interface SkillClientInterface
{
    /**
     * One page of the skills listed by the server, which may serve others without listing them.
     *
     * @throws ServerCapabilityNotSupportedException
     */
    public function listSkills(?Cursor $cursor = null): ListSkillsResult;

    /**
     * The current entry of one skill, whether or not the server lists it.
     *
     * @param non-empty-string $uri URI of the skill's `SKILL.md`
     *
     * @throws ServerCapabilityNotSupportedException
     */
    public function getSkill(string $uri): Skill;

    /**
     * Reads one file of a skill and returns its bytes once they match the entry, or the server's request for
     * input. Call it only when the file is needed, since a host must not fetch a skill's files ahead of use.
     *
     * @param non-empty-string                                $uri
     * @param null|array<int|non-empty-string, InputResponse> $inputResponses
     *
     * @throws SkillVerificationFailedException
     */
    public function readSkillFile(Skill $skill, string $uri, ?array $inputResponses = null, ?string $requestState = null): InputRequiredResult|string;

    /**
     * One page of the direct children of a directory resource.
     *
     * @param non-empty-string $uri URI of the directory, written without a trailing slash
     *
     * @throws ServerCapabilityNotSupportedException
     */
    public function readDirectory(string $uri, ?Cursor $cursor = null): ReadResourceDirectoryResult;
}
