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
use Nexus\Mcp\Core\Schema\RequestMetaObject;

/**
 * Parameters for a `resources/unsubscribe` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#unsubscriberequestparams
 */
final readonly class UnsubscribeRequestParams extends ResourceRequestParams
{
    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isString('"params.uri" must be a string, {type} given.');

        $meta = new RequestMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = RequestMetaObject::fromArray($data['_meta']);
        }

        return new self($uri, $meta);
    }
}
