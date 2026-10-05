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

namespace Nexus\Mcp\Extension\Skills;

/**
 * Protocol vocabulary of the skills extension (`io.modelcontextprotocol/skills`, SEP-2640).
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2640-skills-extension.md
 */
final readonly class Skills
{
    public const string IDENTIFIER = 'io.modelcontextprotocol/skills';
    public const string URI_PREFIX = 'skill://';
    public const string MANIFEST_FILENAME = 'SKILL.md';
    public const string MANIFEST_MIME_TYPE = 'text/markdown';
    public const string DIRECTORY_MIME_TYPE = 'inode/directory';
    public const string DIRECTORY_READ_SETTING = 'directoryRead';

    /**
     * The `resources` value of a skill whose content is generated and carries no stable digests.
     */
    public const string DYNAMIC_RESOURCES = 'dynamic';

    /**
     * The file count accepted by every host for one skill, above which a host may decline it.
     */
    public const int MAX_RESOURCES_PER_SKILL = 512;

    /**
     * The total size in bytes accepted by every host for one skill, above which a host may decline it.
     */
    public const int MAX_BYTES_PER_SKILL = 16_777_216;
}
