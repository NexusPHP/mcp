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

/**
 * Parameters for a `notifications/resources/updated` notification.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#resourceupdatednotificationparams
 */
final readonly class ResourceUpdatedNotificationParams extends NotificationParams
{
    public function __construct(public string $uri, MetaObject $meta = new MetaObject())
    {
        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'ResourceUpdatedNotificationParams data missing "uri".');
        $uri = $data['uri'];
        Assert::that($uri)->isString('ResourceUpdatedNotificationParams "uri" must be a string, {type} given.');

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Notification params "_meta" must be an object, {type} given.')
                ->isMap('Notification params "_meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($uri, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'uri' => $this->uri,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
