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
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\ResultMetaObject;

/**
 * The result returned by the server for a `completion/complete` request.
 *
 * @extends Result<array{
 *   _meta?: template-type<ResultMetaObject, Arrayable, 'T'>,
 *   resultType: non-empty-string,
 *   completion: array{values: list<string>, total?: int, hasMore?: bool},
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#completeresult
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
    public function __construct(array $completion, ResultMetaObject $meta = new ResultMetaObject())
    {
        Assert::that($completion['values'])
            ->isList('"result.completion.values" must be a list, non-list array given.')
            ->values()->isString('each "result.completion.values" must be a string, {type} given.')
        ;

        $normalized = ['values' => $completion['values']];

        if (\array_key_exists('total', $completion)) {
            Assert::that($completion['total'])->isInt('"result.completion.total" must be an int, {type} given.');
            $normalized['total'] = $completion['total'];
        }

        if (\array_key_exists('hasMore', $completion)) {
            Assert::that($completion['hasMore'])->isBool('"result.completion.hasMore" must be a bool, {type} given.');
            $normalized['hasMore'] = $completion['hasMore'];
        }

        $this->completion = $normalized;

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('completion', '"result" is missing the required "completion" key.');
        Assert::that($data['completion'])
            ->isArray('"result.completion" must be an object, {type} given.')
            ->isMap('"result.completion" must be a string-keyed object.')
        ;

        Assert::that($data['completion'])->hasOffset('values', '"result" is missing the required "completion.values" key.');
        Assert::that($data['completion']['values'])
            ->isList('"result.completion.values" must be a list, {type} given.')
            ->values()->isString('each "result.completion.values" must be a string, {type} given.')
        ;

        $completion = ['values' => $data['completion']['values']];

        if (\array_key_exists('total', $data['completion'])) {
            Assert::that($data['completion']['total'])->isInt('"result.completion.total" must be an int, {type} given.');
            $completion['total'] = $data['completion']['total'];
        }

        if (\array_key_exists('hasMore', $data['completion'])) {
            Assert::that($data['completion']['hasMore'])->isBool('"result.completion.hasMore" must be a bool, {type} given.');
            $completion['hasMore'] = $data['completion']['hasMore'];
        }

        $meta = new ResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = ResultMetaObject::fromArray($data['_meta']);
        }

        return new self(completion: $completion, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['resultType'] = self::getResultType();
        $data['completion'] = $this->completion;

        return $data;
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        return new self(completion: $this->completion, meta: $meta);
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
