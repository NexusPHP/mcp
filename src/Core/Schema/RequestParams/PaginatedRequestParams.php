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

namespace Nexus\Mcp\Core\Schema\RequestParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Common params for paginated requests.
 *
 * @extends RequestParams<array{
 *   _meta: template-type<RequestMetaObject, Arrayable, 'T'>,
 *   cursor?: non-empty-string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#paginatedrequestparams
 */
final readonly class PaginatedRequestParams extends RequestParams
{
    public function __construct(RequestMetaObject $meta, public ?Cursor $cursor = null)
    {
        parent::__construct($meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $cursor = null;

        if (\array_key_exists('cursor', $data)) {
            $raw = $data['cursor'];
            Assert::that($raw)->isString('"params.cursor" must be a string, {type} given.');
            $cursor = new Cursor(cursor: $raw);
        }

        Assert::that($data)->hasOffset('_meta', '"params" missing the required "_meta" key.');
        Assert::that($data['_meta'])
            ->isArray('"params._meta" must be an object, {type} given.')
            ->isMap('"params._meta" must be a string-keyed object.')
        ;
        $meta = RequestMetaObject::fromArray($data['_meta']);

        return new self(meta: $meta, cursor: $cursor);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['_meta' => $this->meta->toArray()];

        if (null !== $this->cursor) {
            $data['cursor'] = $this->cursor->cursor;
        }

        return $data;
    }
}
