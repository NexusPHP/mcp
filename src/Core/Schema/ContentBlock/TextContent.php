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

namespace Nexus\Mcp\Core\Schema\ContentBlock;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ContentBlock;
use Nexus\Mcp\Core\Schema\Meta;

/**
 * Text provided to or from an LLM.
 *
 * @implements Arrayable<array{
 *   text: string,
 *   type: 'text',
 *   annotations?: template-type<Annotations, Arrayable, 'T'>,
 *   _meta?: template-type<Meta, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#textcontent
 */
final readonly class TextContent implements Arrayable, ContentBlock
{
    public const string TYPE = 'text';

    public function __construct(
        public string $text,
        public ?Annotations $annotations = null,
        public ?Meta $meta = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'TextContent wire data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('TextContent wire "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('text', 'TextContent wire data missing "text".');
        $text = $data['text'];
        Assert::that($text)->isString('TextContent wire "text" must be a string, {type} given.');

        $annotations = null;

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('TextContent wire "annotations" must be an object, {type} given.')
                ->isMap('TextContent wire "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('TextContent "_meta" must be an object, {type} given.')
                ->isMap('TextContent "_meta" must be a string-keyed object.')
            ;
            $meta = Meta::fromArray($data['_meta']);
        }

        return new self($text, $annotations, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'text' => $this->text,
            'type' => self::TYPE,
        ];

        if (null !== $this->annotations) {
            $data['annotations'] = $this->annotations->toArray();
        }

        if (null !== $this->meta) {
            $meta = $this->meta->toArray();

            if ([] !== $meta) {
                $data['_meta'] = $meta;
            }
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
