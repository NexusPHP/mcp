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
 * @phpstan-type ExtensionsCapability array<string, array<string, mixed>>
 * @phpstan-type RootsCapability array{listChanged?: bool}
 * @phpstan-type SamplingCapability array{context?: array<string, mixed>, tools?: array<string, mixed>}
 *
 * @implements Arrayable<array{
 *   elicitation?: ElicitationCapability,
 *   experimental?: ExperimentalCapability,
 *   extensions?: ExtensionsCapability,
 *   roots?: RootsCapability,
 *   sampling?: SamplingCapability,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#clientcapabilities
 */
final readonly class ClientCapabilities implements Arrayable
{
    /**
     * @param null|ElicitationCapability  $elicitation
     * @param null|ExperimentalCapability $experimental
     * @param null|ExtensionsCapability   $extensions
     * @param null|RootsCapability        $roots
     * @param null|SamplingCapability     $sampling
     */
    public function __construct(
        public ?array $elicitation = null,
        public ?array $experimental = null,
        public ?array $extensions = null,
        public ?array $roots = null,
        public ?array $sampling = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            elicitation: self::extractElicitation($data),
            experimental: self::extractExperimental($data),
            extensions: self::extractExtensions($data),
            roots: self::extractRoots($data),
            sampling: self::extractSampling($data),
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

        if (null !== $this->extensions) {
            $data['extensions'] = $this->extensions;
        }

        if (null !== $this->roots) {
            $data['roots'] = $this->roots;
        }

        if (null !== $this->sampling) {
            $data['sampling'] = $this->sampling;
        }

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
            ->isArray('"capabilities.elicitation" must be an object, {type} given.')
            ->isMap('"capabilities.elicitation" must be a string-keyed object.')
        ;

        $elicitation = [];

        if (\array_key_exists('form', $value)) {
            Assert::that($value['form'])
                ->isArray('"capabilities.elicitation.form" must be an object, {type} given.')
                ->isMap('"capabilities.elicitation.form" must be a string-keyed object.')
            ;
            $elicitation['form'] = $value['form'];
        }

        if (\array_key_exists('url', $value)) {
            Assert::that($value['url'])
                ->isArray('"capabilities.elicitation.url" must be an object, {type} given.')
                ->isMap('"capabilities.elicitation.url" must be a string-keyed object.')
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
     * @return null|RootsCapability
     */
    private static function extractRoots(array $data): ?array
    {
        $value = $data['roots'] ?? null;

        if (null === $value) {
            return null;
        }

        Assert::that($value)
            ->isArray('"capabilities.roots" must be an object, {type} given.')
            ->isMap('"capabilities.roots" must be a string-keyed object.')
        ;

        $roots = [];

        if (\array_key_exists('listChanged', $value)) {
            Assert::that($value['listChanged'])
                ->isBool('"capabilities.roots.listChanged" must be a boolean, {type} given.')
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
            ->isArray('"capabilities.sampling" must be an object, {type} given.')
            ->isMap('"capabilities.sampling" must be a string-keyed object.')
        ;

        $sampling = [];

        if (\array_key_exists('context', $value)) {
            Assert::that($value['context'])
                ->isArray('"capabilities.sampling.context" must be an object, {type} given.')
                ->isMap('"capabilities.sampling.context" must be a string-keyed object.')
            ;
            $sampling['context'] = $value['context'];
        }

        if (\array_key_exists('tools', $value)) {
            Assert::that($value['tools'])
                ->isArray('"capabilities.sampling.tools" must be an object, {type} given.')
                ->isMap('"capabilities.sampling.tools" must be a string-keyed object.')
            ;
            $sampling['tools'] = $value['tools'];
        }

        return $sampling;
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
