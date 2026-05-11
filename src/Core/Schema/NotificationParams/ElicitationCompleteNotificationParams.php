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
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\NotificationParams;

/**
 * Parameters for a `notifications/elicitation/complete` notification.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
final readonly class ElicitationCompleteNotificationParams extends NotificationParams
{
    /**
     * @var non-empty-string
     */
    public string $elicitationId;

    public function __construct(string $elicitationId, ?Meta $meta = null)
    {
        Assert::that($elicitationId)->isNonEmptyString('ElicitationCompleteNotificationParams elicitationId must be a non-empty string.');

        $this->elicitationId = $elicitationId;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('elicitationId', 'ElicitationCompleteNotificationParams wire data missing "elicitationId".');
        $elicitationId = $data['elicitationId'];
        Assert::that($elicitationId)->isString('ElicitationCompleteNotificationParams wire "elicitationId" must be a string, {type} given.');

        $meta = Meta::parseFromWire($data, 'Notification params');

        return new self($elicitationId, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'elicitationId' => $this->elicitationId,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
