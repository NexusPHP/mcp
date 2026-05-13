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

namespace Nexus\Mcp\Core\Handler;

use Amp\Cancellation;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;

/**
 * Context passed to a request handler. Carries metadata about the incoming
 * request and exposes domain helpers for emitting out-of-band messages.
 */
abstract readonly class AbstractContext
{
    public function __construct(
        public RequestId $requestId,
        public Cancellation $cancellation,
        public ?RequestMetaObject $meta,
        public ?string $sessionId,
        protected SenderInterface $sender,
    ) {
    }

    public function reportProgress(float $progress, ?float $total = null, ?string $message = null): void
    {
        $token = $this->meta?->progressToken;

        if (null === $token) {
            return;
        }

        $this->sender->sendNotification(new ProgressNotification(
            new ProgressNotificationParams($token, $progress, $total, $message),
        ));
    }
}
