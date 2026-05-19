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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

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
        Assert::that($data)->hasOffset('level', 'missing the required "level" key.');
        $level = EnumValueValidator::parse(LoggingLevel::class, $data['level'], '"params.level"');

        Assert::that($data)->hasOffset('data', 'missing the required "data" key.');

        $logger = $data['logger'] ?? null;
        Assert::that($logger)->nullOr()->isString('"params.logger" must be a string or null, {type} given.');

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($level, $data['data'], $logger, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'level' => $this->level->value,
            'data' => $this->data,
        ];

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
