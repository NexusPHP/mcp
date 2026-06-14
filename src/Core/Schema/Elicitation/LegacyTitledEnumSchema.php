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
 * Use `TitledSingleSelectEnumSchema` instead. This interface will be removed in a future version.
 *
 * @implements Arrayable<array{
 *   type: 'string',
 *   enum: list<non-empty-string>,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   enumNames?: list<non-empty-string>,
 *   default?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#legacytitledenumschema
 */
final readonly class LegacyTitledEnumSchema implements Arrayable, EnumSchema
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
     * @var null|list<non-empty-string>
     */
    public ?array $enumNames;

    /**
     * @param list<string>      $enum
     * @param null|list<string> $enumNames
     */
    public function __construct(
        array $enum,
        ?string $title = null,
        ?string $description = null,
        ?array $enumNames = null,
        public ?string $default = null,
    ) {
        Assert::that($enum)
            ->isList('legacy titled enum schema "enum" must be a list, non-list array given.')
            ->values()->isNonEmptyString('each legacy titled enum schema "enum" must be a non-empty string.')
        ;
        Assert::that($title)->nullOr()->isNonEmptyString('legacy titled enum schema "title" must be a non-empty string or null.');
        Assert::that($description)->nullOr()->isNonEmptyString('legacy titled enum schema "description" must be a non-empty string or null.');

        if (null !== $enumNames) {
            Assert::that($enumNames)
                ->isList('legacy titled enum schema "enumNames" must be a list, non-list array given.')
                ->values()->isNonEmptyString('each legacy titled enum schema "enumNames" must be a non-empty string.')
            ;
        }

        $this->enum = $enum;
        $this->title = $title;
        $this->description = $description;
        $this->enumNames = $enumNames;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'legacy titled enum schema is missing the required "type" key.');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, 'legacy titled enum schema "type" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('enum', 'legacy titled enum schema is missing the required "enum" key.');
        Assert::that($data['enum'])
            ->isList('legacy titled enum schema "enum" must be a list, non-list array given.')
            ->values()->isString('each legacy titled enum schema "enum" must be a string, {type} given.')
        ;
        $enum = $data['enum'];

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('legacy titled enum schema "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('legacy titled enum schema "description" must be a string or null, {type} given.');

        $enumNames = null;

        if (isset($data['enumNames'])) {
            Assert::that($data['enumNames'])
                ->isList('legacy titled enum schema "enumNames" must be a list, non-list array given.')
                ->values()->isString('each legacy titled enum schema "enumNames" must be a string, {type} given.')
            ;
            $enumNames = $data['enumNames'];
        }

        $default = $data['default'] ?? null;
        Assert::that($default)->nullOr()->isString('legacy titled enum schema "default" must be a string or null, {type} given.');

        return new self(
            enum: $enum,
            title: $title,
            description: $description,
            enumNames: $enumNames,
            default: $default,
        );
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

        if (null !== $this->enumNames) {
            $data['enumNames'] = $this->enumNames;
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
