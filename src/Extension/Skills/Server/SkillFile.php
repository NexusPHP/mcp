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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;

/**
 * One file of a skill, held as the bytes served for it.
 */
final readonly class SkillFile
{
    /**
     * @param non-empty-string      $uri
     * @param string                $contents The file's raw bytes
     * @param null|non-empty-string $mimeType
     */
    public function __construct(
        public string $uri,
        public string $contents,
        public ?string $mimeType = null,
    ) {
        Assert::that($uri)->isNonEmptyString('skill file "uri" must be a non-empty string.');
        Assert::that($mimeType)->nullOr()->isNonEmptyString('skill file "mimeType" must be a non-empty string or null.');
    }

    /**
     * The contents as text when the bytes are valid UTF-8 free of NUL, and as a base64 blob otherwise.
     */
    public function toResourceContents(): BlobResourceContents|TextResourceContents
    {
        if (preg_match('//u', $this->contents) === 1 && ! str_contains($this->contents, "\0")) {
            return new TextResourceContents($this->uri, $this->contents, $this->mimeType);
        }

        return new BlobResourceContents($this->uri, base64_encode($this->contents), $this->mimeType);
    }
}
