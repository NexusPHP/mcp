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
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Common parameters for paginated requests.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
final readonly class PaginatedRequestParams extends RequestParams
{
    public function __construct(public ?Cursor $cursor = null, ?RequestMeta $meta = null)
    {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $cursor = null;

        if (\array_key_exists('cursor', $data)) {
            $raw = $data['cursor'];
            Assert::that($raw)->isString('PaginatedRequestParams wire "cursor" must be a string, {type} given.');
            $cursor = new Cursor($raw);
        }

        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Request params "_meta" must be an object, {type} given.')
                ->isMap('Request params "_meta" must be a string-keyed object.')
            ;
            $meta = RequestMeta::fromArray($data['_meta']);
        }

        return new self($cursor, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = parent::toArray();

        if (null !== $this->cursor) {
            $data['cursor'] = $this->cursor->cursor;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
