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

namespace Nexus\Mcp\Core\Schema\ResourceContents;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\ResourceContents;

/**
 * Text-encoded resource contents. The `text` payload is set only when the
 * resource can actually be represented as text (not binary data).
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#textresourcecontents
 */
final readonly class TextResourceContents extends ResourceContents
{
    public function __construct(
        string $uri,
        public string $text,
        ?string $mimeType = null,
        ?Meta $meta = null,
    ) {
        parent::__construct($uri, $mimeType, $meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'TextResourceContents wire data missing "uri".');
        $uri = $data['uri'];
        Assert::that($uri)->isString('TextResourceContents wire "uri" must be a string, {type} given.');

        Assert::that($data)->hasOffset('text', 'TextResourceContents wire data missing "text".');
        $text = $data['text'];
        Assert::that($text)->isString('TextResourceContents wire "text" must be a string, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isString('TextResourceContents wire "mimeType" must be a string or null, {type} given.');

        $meta = Meta::parseFromWire($data, 'ResourceContents');

        return new self($uri, $text, $mimeType, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'text' => $this->text];
    }
}
