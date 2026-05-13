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

namespace Nexus\Mcp\Core\Schema\Tool;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Icons;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;

/**
 * Definition for a tool the client can call.
 *
 * @phpstan-type ToolSchemaShape array{
 *   type: 'object',
 *   '$schema'?: non-empty-string,
 *   properties?: array<string, array<string, mixed>>,
 *   required?: list<string>,
 * }
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   inputSchema: array{type: 'object', '$schema'?: non-empty-string, properties?: array<string, array<string, mixed>>, required?: list<string>},
 *   outputSchema?: array{type: 'object', '$schema'?: non-empty-string, properties?: array<string, array<string, mixed>>, required?: list<string>},
 *   annotations?: template-type<ToolAnnotations, Arrayable, 'T'>,
 *   execution?: template-type<ToolExecution, Arrayable, 'T'>,
 *   icons?: list<template-type<Icon, Arrayable, 'T'>>,
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#tool
 */
final readonly class Tool extends BaseMetadata implements Arrayable, Icons
{
    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @var ToolSchemaShape
     */
    public array $inputSchema;

    /**
     * @var null|ToolSchemaShape
     */
    public ?array $outputSchema;

    /**
     * @param array<string, mixed>      $inputSchema
     * @param null|array<string, mixed> $outputSchema
     * @param null|list<Icon>           $icons
     */
    public function __construct(
        string $name,
        array $inputSchema,
        ?string $title = null,
        ?string $description = null,
        ?array $outputSchema = null,
        public ?ToolAnnotations $annotations = null,
        public ?ToolExecution $execution = null,
        public ?array $icons = null,
        public ?MetaObject $meta = null,
    ) {
        parent::__construct($name, $title);

        IdentifierNameValidator::validate($name, 'Tool');
        Assert::that($description)->nullOr()->isNonEmptyString('Tool description must be a non-empty string or null.');

        if (null !== $this->icons) {
            Assert::that($this->icons)->values()->isInstanceOf(Icon::class);
        }

        $this->description = $description;
        $this->inputSchema = self::projectSchemaEnvelope($inputSchema, 'Tool inputSchema');
        $this->outputSchema = null === $outputSchema ? null : self::projectSchemaEnvelope($outputSchema, 'Tool outputSchema');
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('name', 'Tool data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('Tool "name" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('Tool "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('Tool "description" must be a string or null, {type} given.');

        Assert::that($data)->hasOffset('inputSchema', 'Tool data missing "inputSchema".');
        Assert::that($data['inputSchema'])
            ->isArray('Tool "inputSchema" must be an object, {type} given.')
            ->isMap('Tool "inputSchema" must be a string-keyed object.')
        ;
        $inputSchema = $data['inputSchema'];

        $outputSchema = null;

        if (\array_key_exists('outputSchema', $data)) {
            Assert::that($data['outputSchema'])
                ->isArray('Tool "outputSchema" must be an object, {type} given.')
                ->isMap('Tool "outputSchema" must be a string-keyed object.')
            ;
            $outputSchema = $data['outputSchema'];
        }

        $annotations = null;

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('Tool "annotations" must be an object, {type} given.')
                ->isMap('Tool "annotations" must be a string-keyed object.')
            ;
            $annotations = ToolAnnotations::fromArray($data['annotations']);
        }

        $execution = null;

        if (\array_key_exists('execution', $data)) {
            Assert::that($data['execution'])
                ->isArray('Tool "execution" must be an object, {type} given.')
                ->isMap('Tool "execution" must be a string-keyed object.')
            ;
            $execution = ToolExecution::fromArray($data['execution']);
        }

        $icons = null;

        if (isset($data['icons'])) {
            Assert::that($data['icons'])
                ->isList('Tool "icons" must be a list, {type} given.')
                ->values()
                ->isArray('Tool icon entry must be an object, {type} given.')
                ->isMap('Tool icon entry must be a string-keyed object.')
            ;
            $icons = array_map(Icon::fromArray(...), $data['icons']);
        }

        $meta = MetaObject::parseFrom($data, 'Tool');

        return new self($name, $inputSchema, $title, $description, $outputSchema, $annotations, $execution, $icons, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'inputSchema' => $this->inputSchema,
        ];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->outputSchema) {
            $data['outputSchema'] = $this->outputSchema;
        }

        if (null !== $this->annotations) {
            $annotations = $this->annotations->toArray();

            if ([] !== $annotations) {
                $data['annotations'] = $annotations;
            }
        }

        if (null !== $this->execution) {
            $execution = $this->execution->toArray();

            if ([] !== $execution) {
                $data['execution'] = $execution;
            }
        }

        if (null !== $this->icons) {
            $data['icons'] = array_map(static fn(Icon $icon): array => $icon->toArray(), $this->icons);
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

    /**
     * Validates a JSON Schema envelope and projects it to the typed
     * `ToolSchemaShape`. Per-property values inside `properties` stay opaque
     * (`array<string, mixed>`) per spec, so the projection narrows their inner
     * shape only as far as `array<string, mixed>`.
     *
     * @param array<string, mixed> $schema
     * @param non-empty-string     $context
     *
     * @return ToolSchemaShape
     */
    private static function projectSchemaEnvelope(array $schema, string $context): array
    {
        Assert::that($schema)->hasOffset('type', \sprintf('%s missing "type".', $context));
        Assert::that($schema['type'])->isIdentical('object', \sprintf('%s "type" must be "object", {value} given.', $context));

        $out = ['type' => 'object'];

        if (\array_key_exists('$schema', $schema)) {
            Assert::that($schema['$schema'])->isNonEmptyString(\sprintf('%s "$schema" must be a non-empty string, {type} given.', $context));
            $out['$schema'] = $schema['$schema'];
        }

        if (\array_key_exists('properties', $schema)) {
            Assert::that($schema['properties'])
                ->isArray(\sprintf('%s "properties" must be an object, {type} given.', $context))
                ->isMap(\sprintf('%s "properties" must be a string-keyed object.', $context))
                ->values()
                ->isArray(\sprintf('%s property entry must be an object, {type} given.', $context))
                ->isMap(\sprintf('%s property entry must be a string-keyed object.', $context))
            ;
            $out['properties'] = $schema['properties'];
        }

        if (\array_key_exists('required', $schema)) {
            Assert::that($schema['required'])
                ->isList(\sprintf('%s "required" must be a list, got non-list array.', $context))
                ->values()->isString(\sprintf('%s "required" entries must be strings, {type} given.', $context))
            ;
            $out['required'] = $schema['required'];
        }

        return $out;
    }
}
