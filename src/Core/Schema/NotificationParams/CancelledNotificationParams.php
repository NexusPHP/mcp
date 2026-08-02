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
use Nexus\Mcp\Core\Schema\MetaObject\NotificationMetaObject;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Parameters for a `notifications/cancelled` notification.
 *
 * @extends NotificationParams<array{
 *   _meta?: template-type<NotificationMetaObject, MetaObject, 'T'>,
 *   requestId: int|non-empty-string,
 *   reason?: non-empty-string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#cancellednotificationparams
 */
final readonly class CancelledNotificationParams extends NotificationParams
{
    /**
     * @var null|non-empty-string
     */
    public ?string $reason;

    public function __construct(
        public RequestId $requestId,
        ?string $reason = null,
        NotificationMetaObject $meta = new NotificationMetaObject(),
    ) {
        Assert::that($reason)->nullOr()->isNonEmptyString('"params.reason" must be a non-empty string or null.');

        $this->reason = $reason;

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('requestId', '"params" is missing the required "requestId" key.');
        Assert::that($data['requestId'])->isIntOrNonEmptyString('"params.requestId" must be an int or non-empty string, {type} given.');
        $requestId = new RequestId(id: $data['requestId']);

        $reason = $data['reason'] ?? null;
        Assert::that($reason)->nullOr()->isString('"params.reason" must be a string or null, {type} given.');

        $meta = new NotificationMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = NotificationMetaObject::fromArray($data['_meta']);
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

        $data['requestId'] = $this->requestId->id;

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
