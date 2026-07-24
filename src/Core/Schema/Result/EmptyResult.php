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
 * Common result fields.
 *
 * @extends Result<array{
 *   _meta?: template-type<ResultMetaObject, Arrayable, 'T'>,
 *   resultType: non-empty-string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#emptyresult
 */
final readonly class EmptyResult extends Result implements ClientResult, ServerResult
{
    public function __construct(ResultMetaObject $meta = new ResultMetaObject())
    {
        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $meta = new ResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = ResultMetaObject::fromArray($data['_meta']);
        }

        return new self(meta: $meta);
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

        return $data;
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
