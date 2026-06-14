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
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Parameters for a `notifications/cancelled` notification.
 *
 * @extends NotificationParams<array{
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 *   requestId?: int|non-empty-string,
 *   reason?: non-empty-string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#cancellednotificationparams
 */
final readonly class CancelledNotificationParams extends NotificationParams
{
    /**
     * @var null|non-empty-string
     */
    public ?string $reason;

    public function __construct(
        public ?RequestId $requestId = null,
        ?string $reason = null,
        MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($reason)->nullOr()->isNonEmptyString('"params.reason" must be a non-empty string or null.');

        $this->reason = $reason;

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $requestId = null;

        if (\array_key_exists('requestId', $data)) {
            Assert::that($data['requestId'])->isArrayKey('"params.requestId" must be an int or string, {type} given.');
            $requestId = new RequestId(id: $data['requestId']);
        }

        $reason = $data['reason'] ?? null;
        Assert::that($reason)->nullOr()->isString('"params.reason" must be a string or null, {type} given.');

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(requestId: $requestId, reason: $reason, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

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
