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

namespace Nexus\Mcp\Core\Schema\Resource;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\MetaObject;

/**
 * Text-encoded resource contents. The `text` payload is set only when the
 * resource can actually be represented as text (not binary data).
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#textresourcecontents
 */
final readonly class TextResourceContents extends ResourceContents
{
    public function __construct(
        string $uri,
        public string $text,
        ?string $mimeType = null,
        MetaObject $meta = new MetaObject(),
    ) {
        parent::__construct($uri, $mimeType, $meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'text resource contents missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isString('text resource contents "uri" must be a string, {type} given.');

        Assert::that($data)->hasOffset('text', 'text resource contents missing the required "text" key.');
        $text = $data['text'];
        Assert::that($text)->isString('text resource contents "text" must be a string, {type} given.');

        $mimeType = $data['mimeType'] ?? null;
        Assert::that($mimeType)->nullOr()->isString('text resource contents "mimeType" must be a string or null, {type} given.');

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('text resource contents "_meta" must be an object, {type} given.')
                ->isMap('text resource contents "_meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($uri, $text, $mimeType, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'text' => $this->text];
    }
}
