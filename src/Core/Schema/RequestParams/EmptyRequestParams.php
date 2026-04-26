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
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams;

/**
 * Default request params for methods that carry no typed fields beyond `_meta`.
 */
final readonly class EmptyRequestParams extends RequestParams
{
    public function __construct(?RequestMeta $meta = null)
    {
        parent::__construct($meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Request params "_meta" must be an object, {type} given.')
                ->isMap('Request params "_meta" must be a string-keyed object.')
            ;
            $meta = RequestMeta::fromArray($data['_meta']);
        }

        return new self($meta);
    }
}
