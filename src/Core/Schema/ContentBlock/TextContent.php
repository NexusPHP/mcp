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
use Nexus\Mcp\Core\Schema\MetaObject;

/**
 * Text provided to or from an LLM.
 *
 * @implements Arrayable<array{
 *   text: string,
 *   type: 'text',
 *   annotations?: template-type<Annotations, Arrayable, 'T'>,
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#textcontent
 */
final readonly class TextContent implements Arrayable, ContentBlock
{
    public const string TYPE = 'text';

    public function __construct(
        public string $text,
        public Annotations $annotations = new Annotations(),
        public MetaObject $meta = new MetaObject(),
    ) {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'text content is missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'text content "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('text', 'text content is missing the required "text" key.');
        $text = $data['text'];
        Assert::that($text)->isString('text content "text" must be a string, {type} given.');

        $annotations = new Annotations();

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('text content "annotations" must be an object, {type} given.')
                ->isMap('text content "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('text content "_meta" must be an object, {type} given.')
                ->isMap('text content "_meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(text: $text, annotations: $annotations, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'text' => $this->text,
            'type' => self::TYPE,
        ];

        $annotations = $this->annotations->toArray();

        if ([] !== $annotations) {
            $data['annotations'] = $annotations;
        }

        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
