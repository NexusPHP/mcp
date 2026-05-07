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

namespace Nexus\Mcp\Core\Schema\Result;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Result;

/**
 * The server's response to a completion/complete request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#completeresult
 */
final readonly class CompleteResult extends Result implements ServerResult
{
    /**
     * @var array{values: list<string>, total?: int, hasMore?: bool}
     */
    public array $completion;

    /**
     * @param array{values: list<string>, total?: int, hasMore?: bool} $completion
     */
    public function __construct(array $completion, ?Meta $meta = null)
    {
        Assert::that($completion['values'])->isList('CompleteResult completion "values" must be a list, got non-list array.');

        foreach ($completion['values'] as $value) {
            Assert::that($value)->isString('CompleteResult completion values must all be strings, {type} given.');
        }

        $normalized = ['values' => $completion['values']];

        if (\array_key_exists('total', $completion)) {
            Assert::that($completion['total'])->isInt('CompleteResult completion "total" must be an int, {type} given.');
            $normalized['total'] = $completion['total'];
        }

        if (\array_key_exists('hasMore', $completion)) {
            Assert::that($completion['hasMore'])->isBool('CompleteResult completion "hasMore" must be a bool, {type} given.');
            $normalized['hasMore'] = $completion['hasMore'];
        }

        $this->completion = $normalized;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('completion', 'CompleteResult wire data missing "completion".');
        Assert::that($data['completion'])
            ->isArray('CompleteResult wire "completion" must be an object, {type} given.')
            ->isMap('CompleteResult wire "completion" must be a string-keyed object.')
        ;

        Assert::that($data['completion'])->hasOffset('values', 'CompleteResult wire completion missing "values".');
        Assert::that($data['completion']['values'])->isArray('CompleteResult wire completion "values" must be an array, {type} given.');
        Assert::that($data['completion']['values'])->isList('CompleteResult wire completion "values" must be a list, got non-list array.');

        $values = [];

        foreach ($data['completion']['values'] as $value) {
            Assert::that($value)->isString('CompleteResult wire completion value must be a string, {type} given.');
            $values[] = $value;
        }

        $completion = ['values' => $values];

        if (\array_key_exists('total', $data['completion'])) {
            Assert::that($data['completion']['total'])->isInt('CompleteResult wire completion "total" must be an int, {type} given.');
            $completion['total'] = $data['completion']['total'];
        }

        if (\array_key_exists('hasMore', $data['completion'])) {
            Assert::that($data['completion']['hasMore'])->isBool('CompleteResult wire completion "hasMore" must be a bool, {type} given.');
            $completion['hasMore'] = $data['completion']['hasMore'];
        }

        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Result "_meta" must be an object, {type} given.')
                ->isMap('Result "_meta" must be a string-keyed object.')
            ;
            $meta = Meta::fromArray($data['_meta']);
        }

        return new self($completion, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'completion' => $this->completion,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
