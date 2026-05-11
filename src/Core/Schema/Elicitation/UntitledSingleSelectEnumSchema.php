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
            ->isList('UntitledSingleSelectEnumSchema enum must be a list, got non-list array.')
            ->values()->isNonEmptyString('UntitledSingleSelectEnumSchema enum entry must be a non-empty string.')
        ;
        Assert::that($title)->nullOr()->isNonEmptyString('UntitledSingleSelectEnumSchema title must be a non-empty string or null.');
        Assert::that($description)->nullOr()->isNonEmptyString('UntitledSingleSelectEnumSchema description must be a non-empty string or null.');

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
        Assert::that($data)->hasOffset('type', 'UntitledSingleSelectEnumSchema wire data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('UntitledSingleSelectEnumSchema wire "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('enum', 'UntitledSingleSelectEnumSchema wire data missing "enum".');
        Assert::that($data['enum'])
            ->isList('UntitledSingleSelectEnumSchema wire "enum" must be a list, got non-list array.')
            ->values()->isString('UntitledSingleSelectEnumSchema wire "enum" entry must be a string, {type} given.')
        ;
        $enum = $data['enum'];

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('UntitledSingleSelectEnumSchema wire "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('UntitledSingleSelectEnumSchema wire "description" must be a string or null, {type} given.');

        $default = $data['default'] ?? null;
        Assert::that($default)->nullOr()->isString('UntitledSingleSelectEnumSchema wire "default" must be a string or null, {type} given.');

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
