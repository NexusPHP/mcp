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
 * @phpstan-type LoggingCapability array<string, mixed>
 * @phpstan-type PromptsCapability array{listChanged?: bool}
 * @phpstan-type ResourcesCapability array{listChanged?: bool, subscribe?: bool}
 * @phpstan-type ServerExperimentalCapability array<string, array<string, mixed>>
 * @phpstan-type ServerTasksCapability array{
 *   cancel?: array<string, mixed>,
 *   list?: array<string, mixed>,
 *   requests?: array{
 *     tools?: array{call?: array<string, mixed>},
 *   },
 * }
 * @phpstan-type ToolsCapability array{listChanged?: bool}
 *
 * @implements Arrayable<array{
 *   completions?: CompletionsCapability,
 *   experimental?: ServerExperimentalCapability,
 *   logging?: LoggingCapability,
 *   prompts?: PromptsCapability,
 *   resources?: ResourcesCapability,
 *   tasks?: ServerTasksCapability,
 *   tools?: ToolsCapability,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#servercapabilities
 */
final readonly class ServerCapabilities implements Arrayable
{
    /**
     * @var null|CompletionsCapability
     */
    public ?array $completions;

    /**
     * @var null|ServerExperimentalCapability
     */
    public ?array $experimental;

    /**
     * @var null|LoggingCapability
     */
    public ?array $logging;

    /**
     * @var null|PromptsCapability
     */
    public ?array $prompts;

    /**
     * @var null|ResourcesCapability
     */
    public ?array $resources;

    /**
     * @var null|ServerTasksCapability
     */
    public ?array $tasks;

    /**
     * @var null|ToolsCapability
     */
    public ?array $tools;

    /**
     * @param null|CompletionsCapability        $completions
     * @param null|ServerExperimentalCapability $experimental
     * @param null|LoggingCapability            $logging
     * @param null|PromptsCapability            $prompts
     * @param null|ResourcesCapability          $resources
     * @param null|ServerTasksCapability        $tasks
     * @param null|ToolsCapability              $tools
     */
    public function __construct(
        ?array $completions = null,
        ?array $experimental = null,
        ?array $logging = null,
        ?array $prompts = null,
        ?array $resources = null,
        ?array $tasks = null,
        ?array $tools = null,
    ) {
        $this->completions = $completions;
        $this->experimental = $experimental;
        $this->logging = $logging;
        $this->prompts = $prompts;
        $this->resources = $resources;
        $this->tasks = $tasks;
        $this->tools = $tools;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            self::extractOpenObject($data, 'completions'),
            self::extractExperimental($data),
            self::extractOpenObject($data, 'logging'),
            self::extractListChangedOnly($data, 'prompts'),
            self::extractResources($data),
            self::extractTasks($data),
            self::extractListChangedOnly($data, 'tools'),
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

        if (null !== $this->logging) {
            $data['logging'] = $this->logging;
        }

        if (null !== $this->prompts) {
            $data['prompts'] = $this->prompts;
        }

        if (null !== $this->resources) {
            $data['resources'] = $this->resources;
        }

        if (null !== $this->tasks) {
            $data['tasks'] = $this->tasks;
        }

        if (null !== $this->tools) {
            $data['tools'] = $this->tools;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();

        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = [] === $value ? new \stdClass() : self::normalizeEmptyObjects($value);
            }
        }

        return $data;
    }

    /**
     * Substitutes `\stdClass` for empty arrays so `json_encode` emits `{}`. Safe
     * because every capability slot is spec-typed as an object (no list-typed leaves).
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
            ->isArray(\sprintf('ServerCapabilities wire "%s" must be an object, {type} given.', $key))
            ->isMap(\sprintf('ServerCapabilities wire "%s" must be a string-keyed object.', $key))
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
            ->isArray('ServerCapabilities wire "experimental" must be an object, {type} given.')
            ->isMap('ServerCapabilities wire "experimental" must be a string-keyed object.')
        ;

        $experimental = [];

        foreach ($value as $extKey => $extValue) {
            Assert::that($extValue)
                ->isArray(\sprintf('ServerCapabilities wire "experimental.%s" must be an object, {type} given.', $extKey))
                ->isMap(\sprintf('ServerCapabilities wire "experimental.%s" must be a string-keyed object.', $extKey))
            ;
            $experimental[$extKey] = $extValue;
        }

        return $experimental;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|array{listChanged?: bool}
     */
    private static function extractListChangedOnly(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray(\sprintf('ServerCapabilities wire "%s" must be an object, {type} given.', $key))
            ->isMap(\sprintf('ServerCapabilities wire "%s" must be a string-keyed object.', $key))
        ;

        $result = [];

        if (\array_key_exists('listChanged', $value)) {
            Assert::that($value['listChanged'])
                ->isBool(\sprintf('ServerCapabilities wire "%s.listChanged" must be a boolean, {type} given.', $key))
            ;
            $result['listChanged'] = $value['listChanged'];
        }

        return $result;
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
            ->isArray('ServerCapabilities wire "resources" must be an object, {type} given.')
            ->isMap('ServerCapabilities wire "resources" must be a string-keyed object.')
        ;

        $resources = [];

        if (\array_key_exists('listChanged', $value)) {
            Assert::that($value['listChanged'])
                ->isBool('ServerCapabilities wire "resources.listChanged" must be a boolean, {type} given.')
            ;
            $resources['listChanged'] = $value['listChanged'];
        }

        if (\array_key_exists('subscribe', $value)) {
            Assert::that($value['subscribe'])
                ->isBool('ServerCapabilities wire "resources.subscribe" must be a boolean, {type} given.')
            ;
            $resources['subscribe'] = $value['subscribe'];
        }

        return $resources;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|ServerTasksCapability
     */
    private static function extractTasks(array $data): ?array
    {
        $value = $data['tasks'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('ServerCapabilities wire "tasks" must be an object, {type} given.')
            ->isMap('ServerCapabilities wire "tasks" must be a string-keyed object.')
        ;

        $tasks = [];

        if (\array_key_exists('cancel', $value)) {
            Assert::that($value['cancel'])
                ->isArray('ServerCapabilities wire "tasks.cancel" must be an object, {type} given.')
                ->isMap('ServerCapabilities wire "tasks.cancel" must be a string-keyed object.')
            ;
            $tasks['cancel'] = $value['cancel'];
        }

        if (\array_key_exists('list', $value)) {
            Assert::that($value['list'])
                ->isArray('ServerCapabilities wire "tasks.list" must be an object, {type} given.')
                ->isMap('ServerCapabilities wire "tasks.list" must be a string-keyed object.')
            ;
            $tasks['list'] = $value['list'];
        }

        if (\array_key_exists('requests', $value)) {
            $tasks['requests'] = self::extractTasksRequests($value['requests']);
        }

        return $tasks;
    }

    /**
     * @return array{
     *   tools?: array{call?: array<string, mixed>},
     * }
     */
    private static function extractTasksRequests(mixed $value): array
    {
        Assert::that($value)
            ->isArray('ServerCapabilities wire "tasks.requests" must be an object, {type} given.')
            ->isMap('ServerCapabilities wire "tasks.requests" must be a string-keyed object.')
        ;

        if (! \array_key_exists('tools', $value)) {
            return [];
        }

        Assert::that($value['tools'])
            ->isArray('ServerCapabilities wire "tasks.requests.tools" must be an object, {type} given.')
            ->isMap('ServerCapabilities wire "tasks.requests.tools" must be a string-keyed object.')
        ;

        $tools = [];

        if (\array_key_exists('call', $value['tools'])) {
            Assert::that($value['tools']['call'])
                ->isArray('ServerCapabilities wire "tasks.requests.tools.call" must be an object, {type} given.')
                ->isMap('ServerCapabilities wire "tasks.requests.tools.call" must be a string-keyed object.')
            ;
            $tools['call'] = $value['tools']['call'];
        }

        return ['tools' => $tools];
    }
}
