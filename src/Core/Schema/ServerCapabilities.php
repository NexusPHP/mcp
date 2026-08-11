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
 * Capabilities that a server may support. Known capabilities are defined here, in this schema, but this
 * is not a closed set: any server can define its own, additional capabilities.
 *
 * @phpstan-type CompletionsCapability array<string, mixed>
 * @phpstan-type ExtensionsCapability array<string, array<string, mixed>>
 * @phpstan-type LoggingCapability array<string, mixed>
 * @phpstan-type PromptsCapability array{listChanged?: bool, ...<string, mixed>}
 * @phpstan-type ResourcesCapability array{listChanged?: bool, subscribe?: bool, ...<string, mixed>}
 * @phpstan-type ServerExperimentalCapability array<string, array<string, mixed>>
 * @phpstan-type ToolsCapability array{listChanged?: bool, ...<string, mixed>}
 *
 * @implements Arrayable<array{
 *   completions?: CompletionsCapability,
 *   experimental?: ServerExperimentalCapability,
 *   extensions?: ExtensionsCapability,
 *   logging?: LoggingCapability,
 *   prompts?: PromptsCapability,
 *   resources?: ResourcesCapability,
 *   tools?: ToolsCapability,
 *   ...<string, mixed>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#servercapabilities
 */
final readonly class ServerCapabilities implements Arrayable
{
    /**
     * @param null|CompletionsCapability        $completions
     * @param null|ServerExperimentalCapability $experimental
     * @param null|ExtensionsCapability         $extensions
     * @param null|LoggingCapability            $logging
     * @param null|PromptsCapability            $prompts
     * @param null|ResourcesCapability          $resources
     * @param null|ToolsCapability              $tools
     * @param array<string, mixed>              $extras       Capabilities outside the set this schema names
     */
    public function __construct(
        public ?array $completions = null,
        public ?array $experimental = null,
        public ?array $extensions = null,
        public ?array $logging = null,
        public ?array $prompts = null,
        public ?array $resources = null,
        public ?array $tools = null,
        public array $extras = [],
    ) {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $completions = self::extractOpenObject($data, 'completions');
        $experimental = self::extractExperimental($data);
        $extensions = self::extractExtensions($data);
        $logging = self::extractOpenObject($data, 'logging');
        $prompts = self::extractListChangedOnly($data, 'prompts');
        $resources = self::extractResources($data);
        $tools = self::extractListChangedOnly($data, 'tools');

        unset(
            $data['completions'],
            $data['experimental'],
            $data['extensions'],
            $data['logging'],
            $data['prompts'],
            $data['resources'],
            $data['tools'],
        );

        return new self(
            completions: $completions,
            experimental: $experimental,
            extensions: $extensions,
            logging: $logging,
            prompts: $prompts,
            resources: $resources,
            tools: $tools,
            extras: $data,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if (null !== $this->completions) {
            $data['completions'] = $this->completions;
        }

        if (null !== $this->experimental) {
            $data['experimental'] = $this->experimental;
        }

        if (null !== $this->extensions) {
            $data['extensions'] = $this->extensions;
        }

        if (null !== $this->logging) {
            $data['logging'] = $this->logging;
        }

        if (null !== $this->prompts) {
            $data['prompts'] = $this->prompts;
        }

        if (null !== $this->resources) {
            $data['resources'] = $this->resources;
        }

        if (null !== $this->tools) {
            $data['tools'] = $this->tools;
        }

        $data += $this->extras;

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        if ([] === $data) {
            return new \stdClass();
        }

        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = [] === $value ? new \stdClass() : self::normalizeEmptyObjects($value);
            }
        }

        return $data;
    }

    /**
     * Substitutes `\stdClass` for empty arrays so `json_encode` emits `{}`.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function normalizeEmptyObjects(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = [] === $value ? new \stdClass() : self::normalizeEmptyObjects($value);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|array<string, mixed>
     */
    private static function extractOpenObject(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray(\sprintf('"capabilities.%s" must be an object, {type} given.', $key))
            ->isMap(\sprintf('"capabilities.%s" must be a string-keyed object.', $key))
        ;

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|ServerExperimentalCapability
     */
    private static function extractExperimental(array $data): ?array
    {
        $value = $data['experimental'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('"capabilities.experimental" must be an object, {type} given.')
            ->isMap('"capabilities.experimental" must be a string-keyed object.')
        ;

        $experimental = [];

        foreach ($value as $extKey => $extValue) {
            Assert::that($extValue)
                ->isArray(\sprintf('"capabilities.experimental.%s" must be an object, {type} given.', $extKey))
                ->isMap(\sprintf('"capabilities.experimental.%s" must be a string-keyed object.', $extKey))
            ;
            $experimental[$extKey] = $extValue;
        }

        return $experimental;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|array{listChanged?: bool, ...<string, mixed>}
     */
    private static function extractListChangedOnly(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray(\sprintf('"capabilities.%s" must be an object, {type} given.', $key))
            ->isMap(\sprintf('"capabilities.%s" must be a string-keyed object.', $key))
        ;

        if (\array_key_exists('listChanged', $value)) {
            Assert::that($value['listChanged'])
                ->isBool(\sprintf('"capabilities.%s.listChanged" must be a boolean, {type} given.', $key))
            ;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|ResourcesCapability
     */
    private static function extractResources(array $data): ?array
    {
        $value = $data['resources'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('"capabilities.resources" must be an object, {type} given.')
            ->isMap('"capabilities.resources" must be a string-keyed object.')
        ;

        if (\array_key_exists('listChanged', $value)) {
            Assert::that($value['listChanged'])
                ->isBool('"capabilities.resources.listChanged" must be a boolean, {type} given.')
            ;
        }

        if (\array_key_exists('subscribe', $value)) {
            Assert::that($value['subscribe'])
                ->isBool('"capabilities.resources.subscribe" must be a boolean, {type} given.')
            ;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|ExtensionsCapability
     */
    private static function extractExtensions(array $data): ?array
    {
        $value = $data['extensions'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('"capabilities.extensions" must be an object, {type} given.')
            ->isMap('"capabilities.extensions" must be a string-keyed object.')
        ;

        $extensions = [];

        foreach ($value as $extKey => $extValue) {
            Assert::that($extValue)
                ->isArray(\sprintf('"capabilities.extensions.%s" must be an object, {type} given.', $extKey))
                ->isMap(\sprintf('"capabilities.extensions.%s" must be a string-keyed object.', $extKey))
            ;
            $extensions[$extKey] = $extValue;
        }

        return $extensions;
    }
}
