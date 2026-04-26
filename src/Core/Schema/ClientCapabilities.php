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
 * Capabilities a client may support. Known capabilities are defined here, in this schema, but this is
 * not a closed set: any client can define its own, additional capabilities.
 *
 * @phpstan-type ElicitationCapability array{form?: array<string, mixed>, url?: array<string, mixed>}
 * @phpstan-type ExperimentalCapability array<string, array<string, mixed>>
 * @phpstan-type RootsCapability array{listChanged?: bool}
 * @phpstan-type SamplingCapability array{context?: array<string, mixed>, tools?: array<string, mixed>}
 * @phpstan-type ClientTasksCapability array{
 *   cancel?: array<string, mixed>,
 *   list?: array<string, mixed>,
 *   requests?: array{
 *     elicitation?: array{create?: array<string, mixed>},
 *     sampling?: array{createMessage?: array<string, mixed>},
 *   },
 * }
 *
 * @implements Arrayable<array{
 *   elicitation?: ElicitationCapability,
 *   experimental?: ExperimentalCapability,
 *   roots?: RootsCapability,
 *   sampling?: SamplingCapability,
 *   tasks?: ClientTasksCapability,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#clientcapabilities
 */
final readonly class ClientCapabilities implements Arrayable
{
    /**
     * @var null|ElicitationCapability
     */
    public ?array $elicitation;

    /**
     * @var null|ExperimentalCapability
     */
    public ?array $experimental;

    /**
     * @var null|RootsCapability
     */
    public ?array $roots;

    /**
     * @var null|SamplingCapability
     */
    public ?array $sampling;

    /**
     * @var null|ClientTasksCapability
     */
    public ?array $tasks;

    /**
     * @param null|ElicitationCapability  $elicitation
     * @param null|ExperimentalCapability $experimental
     * @param null|RootsCapability        $roots
     * @param null|SamplingCapability     $sampling
     * @param null|ClientTasksCapability  $tasks
     */
    public function __construct(
        ?array $elicitation = null,
        ?array $experimental = null,
        ?array $roots = null,
        ?array $sampling = null,
        ?array $tasks = null,
    ) {
        $this->elicitation = $elicitation;
        $this->experimental = $experimental;
        $this->roots = $roots;
        $this->sampling = $sampling;
        $this->tasks = $tasks;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            self::extractElicitation($data),
            self::extractExperimental($data),
            self::extractRoots($data),
            self::extractSampling($data),
            self::extractTasks($data),
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if (null !== $this->elicitation) {
            $data['elicitation'] = $this->elicitation;
        }

        if (null !== $this->experimental) {
            $data['experimental'] = $this->experimental;
        }

        if (null !== $this->roots) {
            $data['roots'] = $this->roots;
        }

        if (null !== $this->sampling) {
            $data['sampling'] = $this->sampling;
        }

        if (null !== $this->tasks) {
            $data['tasks'] = $this->tasks;
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
     * @return null|ElicitationCapability
     */
    private static function extractElicitation(array $data): ?array
    {
        $value = $data['elicitation'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('ClientCapabilities wire "elicitation" must be an object, {type} given.')
            ->isMap('ClientCapabilities wire "elicitation" must be a string-keyed object.')
        ;

        $elicitation = [];

        if (\array_key_exists('form', $value)) {
            Assert::that($value['form'])
                ->isArray('ClientCapabilities wire "elicitation.form" must be an object, {type} given.')
                ->isMap('ClientCapabilities wire "elicitation.form" must be a string-keyed object.')
            ;
            $elicitation['form'] = $value['form'];
        }

        if (\array_key_exists('url', $value)) {
            Assert::that($value['url'])
                ->isArray('ClientCapabilities wire "elicitation.url" must be an object, {type} given.')
                ->isMap('ClientCapabilities wire "elicitation.url" must be a string-keyed object.')
            ;
            $elicitation['url'] = $value['url'];
        }

        return $elicitation;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|ExperimentalCapability
     */
    private static function extractExperimental(array $data): ?array
    {
        $value = $data['experimental'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('ClientCapabilities wire "experimental" must be an object, {type} given.')
            ->isMap('ClientCapabilities wire "experimental" must be a string-keyed object.')
        ;

        $experimental = [];

        foreach ($value as $extKey => $extValue) {
            Assert::that($extValue)
                ->isArray(\sprintf('ClientCapabilities wire "experimental.%s" must be an object, {type} given.', $extKey))
                ->isMap(\sprintf('ClientCapabilities wire "experimental.%s" must be a string-keyed object.', $extKey))
            ;
            $experimental[$extKey] = $extValue;
        }

        return $experimental;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|RootsCapability
     */
    private static function extractRoots(array $data): ?array
    {
        $value = $data['roots'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('ClientCapabilities wire "roots" must be an object, {type} given.')
            ->isMap('ClientCapabilities wire "roots" must be a string-keyed object.')
        ;

        $roots = [];

        if (\array_key_exists('listChanged', $value)) {
            Assert::that($value['listChanged'])
                ->isBool('ClientCapabilities wire "roots.listChanged" must be a boolean, {type} given.')
            ;
            $roots['listChanged'] = $value['listChanged'];
        }

        return $roots;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|SamplingCapability
     */
    private static function extractSampling(array $data): ?array
    {
        $value = $data['sampling'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('ClientCapabilities wire "sampling" must be an object, {type} given.')
            ->isMap('ClientCapabilities wire "sampling" must be a string-keyed object.')
        ;

        $sampling = [];

        if (\array_key_exists('context', $value)) {
            Assert::that($value['context'])
                ->isArray('ClientCapabilities wire "sampling.context" must be an object, {type} given.')
                ->isMap('ClientCapabilities wire "sampling.context" must be a string-keyed object.')
            ;
            $sampling['context'] = $value['context'];
        }

        if (\array_key_exists('tools', $value)) {
            Assert::that($value['tools'])
                ->isArray('ClientCapabilities wire "sampling.tools" must be an object, {type} given.')
                ->isMap('ClientCapabilities wire "sampling.tools" must be a string-keyed object.')
            ;
            $sampling['tools'] = $value['tools'];
        }

        return $sampling;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|ClientTasksCapability
     */
    private static function extractTasks(array $data): ?array
    {
        $value = $data['tasks'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('ClientCapabilities wire "tasks" must be an object, {type} given.')
            ->isMap('ClientCapabilities wire "tasks" must be a string-keyed object.')
        ;

        $tasks = [];

        if (\array_key_exists('cancel', $value)) {
            Assert::that($value['cancel'])
                ->isArray('ClientCapabilities wire "tasks.cancel" must be an object, {type} given.')
                ->isMap('ClientCapabilities wire "tasks.cancel" must be a string-keyed object.')
            ;
            $tasks['cancel'] = $value['cancel'];
        }

        if (\array_key_exists('list', $value)) {
            Assert::that($value['list'])
                ->isArray('ClientCapabilities wire "tasks.list" must be an object, {type} given.')
                ->isMap('ClientCapabilities wire "tasks.list" must be a string-keyed object.')
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
     *   elicitation?: array{create?: array<string, mixed>},
     *   sampling?: array{createMessage?: array<string, mixed>},
     * }
     */
    private static function extractTasksRequests(mixed $value): array
    {
        Assert::that($value)
            ->isArray('ClientCapabilities wire "tasks.requests" must be an object, {type} given.')
            ->isMap('ClientCapabilities wire "tasks.requests" must be a string-keyed object.')
        ;

        $requests = [];

        if (\array_key_exists('elicitation', $value)) {
            Assert::that($value['elicitation'])
                ->isArray('ClientCapabilities wire "tasks.requests.elicitation" must be an object, {type} given.')
                ->isMap('ClientCapabilities wire "tasks.requests.elicitation" must be a string-keyed object.')
            ;

            $elicitation = [];

            if (\array_key_exists('create', $value['elicitation'])) {
                Assert::that($value['elicitation']['create'])
                    ->isArray('ClientCapabilities wire "tasks.requests.elicitation.create" must be an object, {type} given.')
                    ->isMap('ClientCapabilities wire "tasks.requests.elicitation.create" must be a string-keyed object.')
                ;
                $elicitation['create'] = $value['elicitation']['create'];
            }

            $requests['elicitation'] = $elicitation;
        }

        if (\array_key_exists('sampling', $value)) {
            Assert::that($value['sampling'])
                ->isArray('ClientCapabilities wire "tasks.requests.sampling" must be an object, {type} given.')
                ->isMap('ClientCapabilities wire "tasks.requests.sampling" must be a string-keyed object.')
            ;

            $sampling = [];

            if (\array_key_exists('createMessage', $value['sampling'])) {
                Assert::that($value['sampling']['createMessage'])
                    ->isArray('ClientCapabilities wire "tasks.requests.sampling.createMessage" must be an object, {type} given.')
                    ->isMap('ClientCapabilities wire "tasks.requests.sampling.createMessage" must be a string-keyed object.')
                ;
                $sampling['createMessage'] = $value['sampling']['createMessage'];
            }

            $requests['sampling'] = $sampling;
        }

        return $requests;
    }
}
