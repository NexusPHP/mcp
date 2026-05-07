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

/**
 * Parameters for a `resources/subscribe` request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#subscriberequestparams
 */
final readonly class SubscribeRequestParams extends ResourceRequestParams
{
    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'SubscribeRequestParams wire data missing "uri".');
        $uri = $data['uri'];
        Assert::that($uri)->isString('SubscribeRequestParams wire "uri" must be a string, {type} given.');

        $meta = RequestMeta::parseFromWire($data, 'Request params');

        return new self($uri, $meta);
    }
}
