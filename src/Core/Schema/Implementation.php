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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;

/**
 * Describes the MCP implementation.
 *
 * @implements Arrayable<array{
 *   name: non-empty-string,
 *   version: non-empty-string,
 *   title?: non-empty-string,
 *   description?: non-empty-string,
 *   websiteUrl?: non-empty-string,
 *   icons?: list<template-type<Icon, Arrayable, 'T'>>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#implementation
 */
final readonly class Implementation extends BaseMetadata implements Arrayable, Icons
{
    /**
     * @var non-empty-string
     */
    public string $version;

    /**
     * @var null|non-empty-string
     */
    public ?string $description;

    /**
     * @var null|non-empty-string
     */
    public ?string $websiteUrl;

    /**
     * @var null|list<Icon>
     */
    public ?array $icons;

    /**
     * @param null|list<Icon> $icons
     */
    public function __construct(
        string $name,
        string $version,
        ?string $title = null,
        ?string $description = null,
        ?string $websiteUrl = null,
        ?array $icons = null,
    ) {
        parent::__construct($name, $title);

        Assert::that($version)->isNonEmptyString('Implementation version must be a non-empty string.');
        Assert::that($description)->nullOr()->isNonEmptyString('Implementation description must be a non-empty string or null.');
        Assert::that($websiteUrl)
            ->nullOr()
            ->isNonEmptyString('Implementation websiteUrl must be a non-empty string or null.')
            ->matchesRegularExpression('/\Ahttps?:\/\/\S+\z/', 'Implementation websiteUrl must be an HTTP or HTTPS URL.')
        ;

        if (null !== $icons) {
            foreach ($icons as $icon) {
                Assert::that($icon)->isInstanceOf(Icon::class);
            }
        }

        $this->version = $version;
        $this->description = $description;
        $this->websiteUrl = $websiteUrl;
        $this->icons = $icons;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('name', 'Implementation wire data missing "name".');
        $name = $data['name'];
        Assert::that($name)->isString('Implementation wire "name" must be a string, {type} given.');

        Assert::that($data)->hasOffset('version', 'Implementation wire data missing "version".');
        $version = $data['version'];
        Assert::that($version)->isString('Implementation wire "version" must be a string, {type} given.');

        $title = $data['title'] ?? null;
        Assert::that($title)->nullOr()->isString('Implementation wire "title" must be a string or null, {type} given.');

        $description = $data['description'] ?? null;
        Assert::that($description)->nullOr()->isString('Implementation wire "description" must be a string or null, {type} given.');

        $websiteUrl = $data['websiteUrl'] ?? null;
        Assert::that($websiteUrl)->nullOr()->isString('Implementation wire "websiteUrl" must be a string or null, {type} given.');

        $icons = null;

        if (isset($data['icons'])) {
            Assert::that($data['icons'])->isArray('Implementation wire "icons" must be an array, {type} given.');

            $icons = [];

            foreach ($data['icons'] as $iconData) {
                Assert::that($iconData)
                    ->isArray('Implementation wire icon entry must be an object, {type} given.')
                    ->isMap('Implementation wire icon entry must be a string-keyed object.')
                ;
                $icons[] = Icon::fromArray($iconData);
            }
        }

        return new self($name, $version, $title, $description, $websiteUrl, $icons);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'version' => $this->version,
        ];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->websiteUrl) {
            $data['websiteUrl'] = $this->websiteUrl;
        }

        if (null !== $this->icons) {
            $data['icons'] = array_map(static fn(Icon $icon): array => $icon->toArray(), $this->icons);
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
