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

namespace Nexus\Mcp\Core\Schema\NotificationParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Parameters for a `notifications/cancelled` notification.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#cancellednotificationparams
 */
final readonly class CancelledNotificationParams extends NotificationParams
{
    public function __construct(
        public ?RequestId $requestId = null,
        public ?string $reason = null,
        MetaObject $meta = new MetaObject(),
    ) {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $requestId = null;

        if (\array_key_exists('requestId', $data)) {
            Assert::that($data['requestId'])->isArrayKey('CancelledNotificationParams "requestId" must be int or string, {type} given.');
            $requestId = new RequestId($data['requestId']);
        }

        $reason = $data['reason'] ?? null;
        Assert::that($reason)->nullOr()->isString('CancelledNotificationParams "reason" must be a string or null, {type} given.');

        $meta = MetaObject::parseFrom($data, 'Notification params');

        return new self($requestId, $reason, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = parent::toArray();

        if (null !== $this->requestId) {
            $data['requestId'] = $this->requestId->id;
        }

        if (null !== $this->reason) {
            $data['reason'] = $this->reason;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
