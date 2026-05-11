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
use Nexus\Mcp\Core\Schema\ParsesNumber;
use Nexus\Mcp\Core\Schema\ProgressToken;

/**
 * Parameters for a `notifications/progress` notification.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#progressnotificationparams
 */
final readonly class ProgressNotificationParams extends NotificationParams
{
    use ParsesNumber;

    public function __construct(
        public ProgressToken $progressToken,
        public float $progress,
        public ?float $total = null,
        public ?string $message = null,
        ?MetaObject $meta = null,
    ) {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('progressToken', 'ProgressNotificationParams wire data missing "progressToken".');
        $progressToken = $data['progressToken'];
        Assert::that($progressToken)->isArrayKey('ProgressNotificationParams wire "progressToken" must be int or string, {type} given.');

        Assert::that($data)->hasOffset('progress', 'ProgressNotificationParams wire data missing "progress".');
        $progress = self::parseNumber($data['progress'], 'ProgressNotificationParams wire "progress" must be a number, {type} given.');

        $total = $data['total'] ?? null;

        if (null !== $total) {
            $total = self::parseNumber($total, 'ProgressNotificationParams wire "total" must be a number or null, {type} given.');
        }

        $message = $data['message'] ?? null;
        Assert::that($message)->nullOr()->isString('ProgressNotificationParams wire "message" must be a string or null, {type} given.');

        $meta = MetaObject::parseFromWire($data, 'Notification params');

        return new self(new ProgressToken($progressToken), $progress, $total, $message, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'progressToken' => $this->progressToken->token,
            'progress' => $this->progress,
        ];

        if (null !== $this->total) {
            $data['total'] = $this->total;
        }

        if (null !== $this->message) {
            $data['message'] = $this->message;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
