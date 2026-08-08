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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Extends `MetaObject` with additional notification-specific fields.
 * All key naming rules from `MetaObject` apply.
 *
 * @extends MetaObject<array<array-key, mixed>>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#notificationmetaobject
 */
final readonly class NotificationMetaObject extends MetaObject
{
    public const string SUBSCRIPTION_ID_KEY = 'io.modelcontextprotocol/subscriptionId';

    /**
     * @param array<array-key, mixed> $extras
     */
    public function __construct(public ?RequestId $subscriptionId = null, array $extras = [])
    {
        parent::__construct(extras: $extras);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $subscriptionId = null;

        if (\array_key_exists(self::SUBSCRIPTION_ID_KEY, $data)) {
            Assert::that($data[self::SUBSCRIPTION_ID_KEY])->isIntOrNonEmptyString(
                \sprintf('"_meta.%s" must be an int or a non-empty string, {type} given.', self::SUBSCRIPTION_ID_KEY),
            );
            $subscriptionId = new RequestId($data[self::SUBSCRIPTION_ID_KEY]);
            unset($data[self::SUBSCRIPTION_ID_KEY]);
        }

        return new self(subscriptionId: $subscriptionId, extras: $data);
    }

    #[\Override]
    public function toArray(): array
    {
        $out = [];

        if (null !== $this->subscriptionId) {
            $out[self::SUBSCRIPTION_ID_KEY] = $this->subscriptionId->id;
        }

        return $out + $this->extras;
    }
}
