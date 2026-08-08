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

namespace Nexus\Mcp\Core\Schema\MetaObject;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Extends `ResultMetaObject` with the subscription-stream identifier carried by a
 * `SubscriptionsListenResult`. All key naming rules from `MetaObject` apply.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#subscriptionslistenresultmetaobject
 */
final readonly class SubscriptionsListenResultMetaObject extends ResultMetaObject
{
    public const string SUBSCRIPTION_ID_KEY = 'io.modelcontextprotocol/subscriptionId';

    /**
     * @param array<array-key, mixed> $extras
     */
    public function __construct(
        public RequestId $subscriptionId,
        ?Implementation $serverInfo = null,
        array $extras = [],
    ) {
        parent::__construct(serverInfo: $serverInfo, extras: $extras);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset(
            self::SUBSCRIPTION_ID_KEY,
            \sprintf('"result._meta" is missing the required "%s" key.', self::SUBSCRIPTION_ID_KEY),
        );
        Assert::that($data[self::SUBSCRIPTION_ID_KEY])->isIntOrNonEmptyString(
            \sprintf('"_meta.%s" must be an int or a non-empty string, {type} given.', self::SUBSCRIPTION_ID_KEY),
        );

        $subscriptionId = new RequestId($data[self::SUBSCRIPTION_ID_KEY]);
        unset($data[self::SUBSCRIPTION_ID_KEY]);

        [$serverInfo, $extras] = self::splitServerInfo($data);

        return new self(subscriptionId: $subscriptionId, serverInfo: $serverInfo, extras: $extras);
    }

    #[\Override]
    public function toArray(): array
    {
        return [self::SUBSCRIPTION_ID_KEY => $this->subscriptionId->id] + parent::toArray();
    }
}
