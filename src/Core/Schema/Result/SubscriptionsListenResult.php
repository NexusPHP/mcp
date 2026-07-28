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
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\SubscriptionsListenResultMetaObject;
use Nexus\Mcp\Core\Schema\Result;

/**
 * The response to a `subscriptions/listen` request, signalling that the subscription has ended gracefully
 * (for example, during server shutdown). Because the listen stream is long-lived, this result is sent only
 * when the server tears the subscription down; an abrupt transport close carries no response. The result
 * body is otherwise empty.
 *
 * @property-read SubscriptionsListenResultMetaObject $meta
 *
 * @extends Result<array{
 *   _meta: template-type<SubscriptionsListenResultMetaObject, Arrayable, 'T'>,
 *   resultType: non-empty-string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#subscriptionslistenresult
 */
final readonly class SubscriptionsListenResult extends Result implements ServerResult
{
    public function __construct(SubscriptionsListenResultMetaObject $meta)
    {
        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('_meta', '"result" is missing the required "_meta" key.');
        Assert::that($data['_meta'])
            ->isArray('"result._meta" must be an object, {type} given.')
            ->isMap('"result._meta" must be a string-keyed object.')
        ;

        return new self(meta: SubscriptionsListenResultMetaObject::fromArray($data['_meta']));
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            '_meta' => $this->meta->toArray(),
            'resultType' => self::getResultType(),
        ];
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        // `_meta` is required here and names the stream, so a caller handing over a
        // plain `ResultMetaObject` keeps this result's own subscription identity.
        return new self(meta: new SubscriptionsListenResultMetaObject(
            subscriptionId: $this->meta->subscriptionId,
            serverInfo: $meta->serverInfo,
            extras: $meta->extras,
        ));
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
