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

namespace Nexus\Mcp\Core\Schema\Elicitation;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Schema for single-selection enumeration without display titles for options.
 *
 * @implements Arrayable<array{
 *   type: 'string',
 *   enum: list<non-empty-string>,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   default?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#untitledsingleselectenumschema
 */
final readonly class UntitledSingleSelectEnumSchema implements Arrayable, SingleSelectEnumSchema
{
    public const string TYPE = 'string';

    /**
     * @var list<non-empty-string>
     */
    public array $enum;

    /**
     * @var null|non-empty-string
     */
    public ?string $title;

    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @param list<string> $enum
     */
    public function __construct(
        array $enum,
        ?string $title = null,
        ?string $description = null,
        public ?string $default = null,
    ) {
        Assert::that($enum)
            ->isList('untitled single select enum schema "enum" must be a list, non-list array given.')
            ->values()->isNonEmptyString('each untitled single select enum schema "enum" must be a non-empty string.')
        ;
        Assert::that($title)->nullOr()->isNonEmptyString('untitled single select enum schema "title" must be a non-empty string or null.');
        Assert::that($description)->nullOr()->isNonEmptyString('untitled single select enum schema "description" must be a non-empty string or null.');

        $this->enum = $enum;
        $this->title = $title;
        $this->description = $description;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'untitled single select enum schema missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'untitled single select enum schema "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('enum', 'untitled single select enum schema missing the required "enum" key.');
        Assert::that($data['enum'])
            ->isList('untitled single select enum schema "enum" must be a list, non-list array given.')
            ->values()->isString('each untitled single select enum schema "enum" must be a string, {type} given.')
        ;
        $enum = $data['enum'];

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('untitled single select enum schema "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('untitled single select enum schema "description" must be a string or null, {type} given.');

        $default = $data['default'] ?? null;
        Assert::that($default)->nullOr()->isString('untitled single select enum schema "default" must be a string or null, {type} given.');

        return new self($enum, $title, $description, $default);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'type' => self::TYPE,
            'enum' => $this->enum,
        ];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->default) {
            $data['default'] = $this->default;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
