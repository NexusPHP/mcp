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

namespace Nexus\Mcp\Core\Schema\Sampling;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\MetaObject;

/**
 * A request from the assistant to call a tool.
 *
 * @implements Arrayable<array{
 *   id: non-empty-string,
 *   input: array<string, mixed>,
 *   name: non-empty-string,
 *   type: 'tool_use',
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#toolusecontent
 */
final readonly class ToolUseContent implements Arrayable, SamplingMessageContentBlock
{
    public const string TYPE = 'tool_use';

    /**
     * @var non-empty-string
     */
    public string $id;

    /**
     * @var non-empty-string
     */
    public string $name;

    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        string $id,
        string $name,
        public array $input,
        public MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($id)->isNonEmptyString('ToolUseContent id must be a non-empty string.');
        Assert::that($name)->isNonEmptyString('ToolUseContent name must be a non-empty string.');

        $this->id = $id;
        $this->name = $name;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'ToolUseContent data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('ToolUseContent "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('id', 'ToolUseContent data missing "id".');
        $id = $data['id'];
        Assert::that($id)->isString('ToolUseContent "id" must be a string, {type} given.');

        Assert::that($data)->hasOffset('name', 'ToolUseContent data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('ToolUseContent "name" must be a string, {type} given.');

        Assert::that($data)->hasOffset('input', 'ToolUseContent data missing "input".');
        Assert::that($data['input'])
            ->isArray('ToolUseContent "input" must be an object, {type} given.')
            ->isMap('ToolUseContent "input" must be a string-keyed object.')
        ;
        $input = $data['input'];

        $meta = MetaObject::parseFrom($data, 'ToolUseContent');

        return new self($id, $name, $input, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'input' => $this->input,
            'name' => $this->name,
            'type' => self::TYPE,
        ];

        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();

        if ([] === $this->input) {
            $data['input'] = new \stdClass();
        }

        return $data;
    }
}
