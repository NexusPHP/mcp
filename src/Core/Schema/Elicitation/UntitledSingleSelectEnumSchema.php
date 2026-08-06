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

/**
 * Schema for single-selection enumeration without display titles for options.
 *
 * @implements SingleSelectEnumSchema<array{
 *   type: 'string',
 *   enum: list<non-empty-string>,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   default?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#untitledsingleselectenumschema
 */
final readonly class UntitledSingleSelectEnumSchema implements SingleSelectEnumSchema
{
    public const string TYPE = 'string';

    /**
     * @param list<non-empty-string> $enum
     * @param null|non-empty-string  $title
     * @param null|non-empty-string  $description
     */
    public function __construct(
        public array $enum,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $default = null,
    ) {
        Assert::that($enum)
            ->isList('untitled single select enum schema "enum" must be a list, non-list array given.')
            ->values()->isNonEmptyString('each untitled single select enum schema "enum" must be a non-empty string.')
        ;
        Assert::that($title)
            ->nullOr()
            ->isNonEmptyString('untitled single select enum schema "title" must be a non-empty string or null.')
        ;
        Assert::that($description)
            ->nullOr()
            ->isNonEmptyString('untitled single select enum schema "description" must be a non-empty string or null.')
        ;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'untitled single select enum schema is missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'untitled single select enum schema "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('enum', 'untitled single select enum schema is missing the required "enum" key.');
        Assert::that($data['enum'])
            ->isList('untitled single select enum schema "enum" must be a list, non-list array given.')
            ->values()->isNonEmptyString('each untitled single select enum schema "enum" must be a non-empty string, {type} given.')
        ;
        $enum = $data['enum'];

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isNonEmptyString('untitled single select enum schema "title" must be a non-empty string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isNonEmptyString('untitled single select enum schema "description" must be a non-empty string or null, {type} given.');

        $default = $data['default'] ?? null;
        Assert::that($default)->nullOr()->isString('untitled single select enum schema "default" must be a string or null, {type} given.');

        return new self(enum: $enum, title: $title, description: $description, default: $default);
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
