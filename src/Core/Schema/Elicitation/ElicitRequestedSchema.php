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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * The `requestedSchema` shape carried by an `ElicitRequestFormParams`.
 *
 * @implements Arrayable<array{
 *   type: 'object',
 *   properties: array<non-empty-string, template-type<PrimitiveSchemaDefinition, Arrayable, 'T'>>,
 *   required?: list<non-empty-string>,
 *   '$schema'?: non-empty-string,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
final readonly class ElicitRequestedSchema implements Arrayable
{
    public const string TYPE = 'object';

    /**
     * @var array<non-empty-string, PrimitiveSchemaDefinition>
     */
    public array $properties;

    /**
     * @var null|list<non-empty-string>
     */
    public ?array $required;

    /**
     * @var null|non-empty-string
     */
    public ?string $schema;

    /**
     * @param array<string, PrimitiveSchemaDefinition> $properties
     * @param null|list<string>                        $required
     */
    public function __construct(array $properties, ?array $required = null, ?string $schema = null)
    {
        Assert::that($properties)
            ->isMap('ElicitRequestedSchema properties must be a string-keyed map.')
            ->keys()->isNonEmptyString('ElicitRequestedSchema property name must be a non-empty string.')
        ;
        Assert::that($properties)->values()->isInstanceOf(PrimitiveSchemaDefinition::class);

        if (null !== $required) {
            Assert::that($required)
                ->isList('ElicitRequestedSchema required must be a list, got non-list array.')
                ->values()->isNonEmptyString('ElicitRequestedSchema required entry must be a non-empty string.')
            ;
        }

        Assert::that($schema)->nullOr()->isNonEmptyString('ElicitRequestedSchema $schema must be a non-empty string or null.');

        $this->properties = $properties;
        $this->required = $required;
        $this->schema = $schema;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'ElicitRequestedSchema data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('ElicitRequestedSchema "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('properties', 'ElicitRequestedSchema data missing "properties".');
        Assert::that($data['properties'])
            ->isArray('ElicitRequestedSchema "properties" must be an object, {type} given.')
            ->isMap('ElicitRequestedSchema "properties" must be a string-keyed object.')
        ;

        $properties = [];

        foreach ($data['properties'] as $name => $shape) {
            Assert::that($shape)
                ->isArray('ElicitRequestedSchema properties entry must be an object, {type} given.')
                ->isMap('ElicitRequestedSchema properties entry must be a string-keyed object.')
            ;

            $properties[$name] = self::parsePrimitiveSchema($shape);
        }

        $required = null;

        if (isset($data['required'])) {
            Assert::that($data['required'])
                ->isList('ElicitRequestedSchema "required" must be a list, got non-list array.')
                ->values()->isString('ElicitRequestedSchema required entry must be a string, {type} given.')
            ;
            $required = $data['required'];
        }

        $schema = $data['$schema'] ?? null;
        Assert::that($schema)->nullOr()->isString('ElicitRequestedSchema "$schema" must be a string or null, {type} given.');

        return new self($properties, $required, $schema);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'type' => self::TYPE,
            'properties' => array_map(
                static fn(PrimitiveSchemaDefinition $p): array => $p->toArray(),
                $this->properties,
            ),
        ];

        if (null !== $this->required) {
            $data['required'] = $this->required;
        }

        if (null !== $this->schema) {
            $data['$schema'] = $this->schema;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parsePrimitiveSchema(array $data): PrimitiveSchemaDefinition
    {
        $type = $data['type'] ?? null;
        Assert::that($type)->isString('ElicitRequestedSchema primitive schema entry must carry a "type" string, {type} given.');

        return match (true) {
            BooleanSchema::TYPE === $type => BooleanSchema::fromArray($data),
            NumberSchema::TYPE === $type, NumberSchema::TYPE_INTEGER === $type => NumberSchema::fromArray($data),
            UntitledMultiSelectEnumSchema::TYPE === $type => self::parseArraySchema($data),
            StringSchema::TYPE === $type => self::parseStringSchema($data),
            default => throw new ExpectationFailedException(\sprintf('ElicitRequestedSchema primitive schema entry has unknown "type" %s.', json_encode($type, \JSON_THROW_ON_ERROR))),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseStringSchema(array $data): PrimitiveSchemaDefinition
    {
        return match (true) {
            isset($data['oneOf']) => TitledSingleSelectEnumSchema::fromArray($data),
            isset($data['enum']) && isset($data['enumNames']) => LegacyTitledEnumSchema::fromArray($data),
            isset($data['enum']) => UntitledSingleSelectEnumSchema::fromArray($data),
            default => StringSchema::fromArray($data),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseArraySchema(array $data): PrimitiveSchemaDefinition
    {
        $items = $data['items'] ?? null;
        Assert::that($items)
            ->isArray('ElicitRequestedSchema multi-select items must be an object, {type} given.')
            ->isMap('ElicitRequestedSchema multi-select items must be a string-keyed object.')
        ;

        return isset($items['anyOf'])
            ? TitledMultiSelectEnumSchema::fromArray($data)
            : UntitledMultiSelectEnumSchema::fromArray($data);
    }
}
