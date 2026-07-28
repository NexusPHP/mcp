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

/**
 * Parameters for a `notifications/resources/updated` notification.
 *
 * @extends NotificationParams<array{
 *   _meta?: template-type<NotificationMetaObject, MetaObject, 'T'>,
 *   uri: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#resourceupdatednotificationparams
 */
final readonly class ResourceUpdatedNotificationParams extends NotificationParams
{
    public function __construct(public string $uri, NotificationMetaObject $meta = new NotificationMetaObject())
    {
        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', '"params" is missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isString('"params.uri" must be a string, {type} given.');

        $meta = new NotificationMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = NotificationMetaObject::fromArray($data['_meta']);
        }

        return new self(uri: $uri, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['uri'] = $this->uri;

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
