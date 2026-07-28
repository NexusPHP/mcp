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
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Validation\IdentifierNameValidator;

/**
 * Definition for a tool the client can call.
 *
 * @phpstan-type ToolInputSchemaShape array{type: 'object', ...<string, mixed>}
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   inputSchema: ToolInputSchemaShape,
 *   outputSchema?: array<string, mixed>,
 *   annotations?: template-type<ToolAnnotations, Arrayable, 'T'>,
 *   icons?: list<template-type<Icon, Arrayable, 'T'>>,
 *   _meta?: template-type<PayloadMetaObject, MetaObject, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#tool
 */
final readonly class Tool extends BaseMetadata implements Arrayable, Icons
{
    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @var ToolInputSchemaShape
     */
    public array $inputSchema;

    /**
     * @var null|array<string, mixed>
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
        public ToolAnnotations $annotations = new ToolAnnotations(),
        public ?array $icons = null,
        public PayloadMetaObject $meta = new PayloadMetaObject(),
    ) {
        parent::__construct(name: $name, title: $title);

        IdentifierNameValidator::validate($name, 'tool "name"');
        Assert::that($description)->nullOr()->isNonEmptyString('Tool description must be a non-empty string or null.');

        if (null !== $this->icons) {
            Assert::that($this->icons)->values()->isInstanceOf(Icon::class);
        }

        $this->description = $description;
        $this->inputSchema = self::validateInputSchema($inputSchema);
        $this->outputSchema = null === $outputSchema ? null : self::validateOutputSchema($outputSchema);
    }

    /**
     * Inserts `annotations.title` between `title` and `name` per the spec's
     * Tool-specific fallback rule.
     *
     * @return non-empty-string
     */
    #[\Override]
    public function getDisplayName(): string
    {
        return $this->title ?? $this->annotations->title ?? $this->name;
    }

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

        $annotations = new ToolAnnotations();

        if (\array_key_exists('annotations', $data)) {
            Assert::that($data['annotations'])
                ->isArray('Tool "annotations" must be an object, {type} given.')
                ->isMap('Tool "annotations" must be a string-keyed object.')
            ;
            $annotations = ToolAnnotations::fromArray($data['annotations']);
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

        $meta = new PayloadMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Tool "_meta" must be an object, {type} given.')
                ->isMap('Tool "_meta" must be a string-keyed object.')
            ;
            $meta = PayloadMetaObject::fromArray($data['_meta']);
        }

        return new self(
            name: $name,
            inputSchema: $inputSchema,
            title: $title,
            description: $description,
            outputSchema: $outputSchema,
            annotations: $annotations,
            icons: $icons,
            meta: $meta,
        );
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

        $annotations = $this->annotations->toArray();

        if ([] !== $annotations) {
            $data['annotations'] = $annotations;
        }

        if (null !== $this->icons) {
            $data['icons'] = array_map(static fn(Icon $icon): array => $icon->toArray(), $this->icons);
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

    /**
     * Validates and returns a tool `inputSchema`. The root must be `type: "object"`.
     *
     * @param array<string, mixed> $schema
     *
     * @return ToolInputSchemaShape
     */
    private static function validateInputSchema(array $schema): array
    {
        Assert::that($schema)->hasOffset('type', 'tool "inputSchema" missing "type".');
        Assert::that($schema['type'])->isIdentical('object', 'tool "inputSchema" "type" must be {other}, {value} given.');
        self::assertSchemaKeywords($schema, 'tool "inputSchema"');

        return $schema;
    }

    /**
     * Validates and returns a tool `outputSchema`.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function validateOutputSchema(array $schema): array
    {
        self::assertSchemaKeywords($schema, 'tool "outputSchema"');

        return $schema;
    }

    /**
     * Validates the `$schema`, `properties`, and `required` keywords when present.
     *
     * @param array<string, mixed> $schema
     * @param non-empty-string     $context
     */
    private static function assertSchemaKeywords(array $schema, string $context): void
    {
        if (\array_key_exists('$schema', $schema)) {
            Assert::that($schema['$schema'])->isNonEmptyString(\sprintf('%s "$schema" must be a non-empty string, {type} given.', $context));
        }

        if (\array_key_exists('properties', $schema)) {
            Assert::that($schema['properties'])
                ->isArray(\sprintf('%s "properties" must be an object, {type} given.', $context))
                ->isMap(\sprintf('%s "properties" must be a string-keyed object.', $context))
                ->values()
                ->isArray(\sprintf('%s property entry must be an object, {type} given.', $context))
                ->isMap(\sprintf('%s property entry must be a string-keyed object.', $context))
            ;
        }

        if (\array_key_exists('required', $schema)) {
            Assert::that($schema['required'])
                ->isList(\sprintf('%s "required" must be a list, got non-list array.', $context))
                ->values()->isString(\sprintf('%s "required" entry must be a string, {type} given.', $context))
            ;
        }
    }
}
