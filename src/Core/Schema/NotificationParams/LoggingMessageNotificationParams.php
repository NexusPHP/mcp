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
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\NotificationParams;

/**
 * Parameters for a `notifications/message` notification.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#loggingmessagenotificationparams
 */
final readonly class LoggingMessageNotificationParams extends NotificationParams
{
    public function __construct(
        public LoggingLevel $level,
        public mixed $data,
        public ?string $logger = null,
        ?Meta $meta = null,
    ) {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('level', 'LoggingMessageNotificationParams wire data missing "level".');
        $level = $data['level'];
        Assert::that($level)->isString('LoggingMessageNotificationParams wire "level" must be a string, {type} given.');

        Assert::that($data)->hasOffset('data', 'LoggingMessageNotificationParams wire data missing "data".');

        $logger = $data['logger'] ?? null;
        Assert::that($logger)->nullOr()->isString('LoggingMessageNotificationParams wire "logger" must be a string or null, {type} given.');

        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Notification params "_meta" must be an object, {type} given.')
                ->isMap('Notification params "_meta" must be a string-keyed object.')
            ;
            $meta = Meta::fromArray($data['_meta']);
        }

        return new self(LoggingLevel::from($level), $data['data'], $logger, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = array_merge(parent::toArray(), [
            'level' => $this->level->value,
            'data' => $this->data,
        ]);

        if (null !== $this->logger) {
            $data['logger'] = $this->logger;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
