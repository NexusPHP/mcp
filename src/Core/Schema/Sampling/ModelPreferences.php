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

namespace Nexus\Mcp\Core\Schema\Sampling;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ParsesNumber;

/**
 * The server's preferences for model selection, requested of the client during sampling.
 *
 * Because LLMs can vary along multiple dimensions, choosing the "best" model is
 * rarely straightforward.  Different models excel in different areas—some are
 * faster but less capable, others are more capable but more expensive, and so
 * on. This interface allows servers to express their priorities across multiple
 * dimensions to help clients make an appropriate selection for their use case.
 *
 * These preferences are always advisory. The client MAY ignore them. It is also
 * up to the client to decide how to interpret these preferences and how to
 * balance them against other considerations.
 *
 * @implements Arrayable<array{
 *   hints?: list<template-type<ModelHint, Arrayable, 'T'>>,
 *   costPriority?: float,
 *   speedPriority?: float,
 *   intelligencePriority?: float,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#modelpreferences
 */
final readonly class ModelPreferences implements Arrayable
{
    use ParsesNumber;

    /**
     * @var null|list<ModelHint>
     */
    public ?array $hints;

    /**
     * @param null|list<ModelHint> $hints
     */
    public function __construct(
        ?array $hints = null,
        public ?float $costPriority = null,
        public ?float $speedPriority = null,
        public ?float $intelligencePriority = null,
    ) {
        if (null !== $hints) {
            foreach ($hints as $hint) {
                Assert::that($hint)->isInstanceOf(ModelHint::class);
            }
        }

        foreach (['costPriority' => $costPriority, 'speedPriority' => $speedPriority, 'intelligencePriority' => $intelligencePriority] as $field => $value) {
            if (null !== $value && ($value < 0.0 || $value > 1.0)) {
                throw new \InvalidArgumentException(\sprintf('ModelPreferences "%s" must be between 0.0 and 1.0.', $field));
            }
        }

        $this->hints = $hints;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $hints = null;

        if (isset($data['hints'])) {
            Assert::that($data['hints'])->isArray('ModelPreferences wire "hints" must be an array, {type} given.');

            $hints = [];

            foreach ($data['hints'] as $hint) {
                Assert::that($hint)
                    ->isArray('ModelPreferences wire hint entry must be an object, {type} given.')
                    ->isMap('ModelPreferences wire hint entry must be a string-keyed object.')
                ;
                $hints[] = ModelHint::fromArray($hint);
            }
        }

        $costPriority = $data['costPriority'] ?? null;

        if (null !== $costPriority) {
            $costPriority = self::parseNumber($costPriority, 'ModelPreferences wire "costPriority" must be a number or null, {type} given.');
        }

        $speedPriority = $data['speedPriority'] ?? null;

        if (null !== $speedPriority) {
            $speedPriority = self::parseNumber($speedPriority, 'ModelPreferences wire "speedPriority" must be a number or null, {type} given.');
        }

        $intelligencePriority = $data['intelligencePriority'] ?? null;

        if (null !== $intelligencePriority) {
            $intelligencePriority = self::parseNumber($intelligencePriority, 'ModelPreferences wire "intelligencePriority" must be a number or null, {type} given.');
        }

        return new self($hints, $costPriority, $speedPriority, $intelligencePriority);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if (null !== $this->hints) {
            $data['hints'] = array_map(static fn(ModelHint $hint): array => $hint->toArray(), $this->hints);
        }

        if (null !== $this->costPriority) {
            $data['costPriority'] = $this->costPriority;
        }

        if (null !== $this->speedPriority) {
            $data['speedPriority'] = $this->speedPriority;
        }

        if (null !== $this->intelligencePriority) {
            $data['intelligencePriority'] = $this->intelligencePriority;
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

        if (null !== $this->hints) {
            $data['hints'] = array_map(static fn(ModelHint $hint): array|\stdClass => $hint->jsonSerialize(), $this->hints);
        }

        return $data;
    }
}
