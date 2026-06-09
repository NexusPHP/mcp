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
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessageContentBlock;

/**
 * Audio provided to or from an LLM.
 *
 * @implements Arrayable<array{
 *   data: non-empty-string,
 *   mimeType: non-empty-string,
 *   type: 'audio',
 *   annotations?: template-type<Annotations, Arrayable, 'T'>,
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#audiocontent
 */
final readonly class AudioContent implements Arrayable, ContentBlock, SamplingMessageContentBlock
{
    public const string TYPE = 'audio';

    /**
     * @var non-empty-string
     */
    public string $data;

    /**
     * @var non-empty-string
     */
    public string $mimeType;

    public function __construct(
        string $data,
        string $mimeType,
        public Annotations $annotations = new Annotations(),
        public MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($data)->isNonEmptyString('audio content "data" must be a non-empty string.');
        Assert::that($mimeType)->isNonEmptyString('audio content "mimeType" must be a non-empty string.');

        $this->data = $data;
        $this->mimeType = $mimeType;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'audio content missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'audio content "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('data', 'audio content missing the required "data" key.');
        $payload = $data['data'];
        Assert::that($payload)->isString('audio content "data" must be a string, {type} given.');

        Assert::that($data)->hasOffset('mimeType', 'audio content missing the required "mimeType" key.');
        $mimeType = $data['mimeType'];
        Assert::that($mimeType)->isString('audio content "mimeType" must be a string, {type} given.');

        $annotations = new Annotations();

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('audio content "annotations" must be an object, {type} given.')
                ->isMap('audio content "annotations" must be a string-keyed object.')
            ;
            $annotations = Annotations::fromArray($data['annotations']);
        }

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('audio content "_meta" must be an object, {type} given.')
                ->isMap('audio content "_meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(data: $payload, mimeType: $mimeType, annotations: $annotations, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'data' => $this->data,
            'mimeType' => $this->mimeType,
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
