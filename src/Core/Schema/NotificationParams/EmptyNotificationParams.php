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
 * Default notification params for methods that carry no typed fields beyond `_meta`.
 *
 * @extends NotificationParams<array{
 *   _meta?: template-type<NotificationMetaObject, MetaObject, 'T'>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/draft/schema.ts
 */
final readonly class EmptyNotificationParams extends NotificationParams
{
    #[\Override]
    public static function fromArray(array $data): static
    {
        $meta = new NotificationMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = NotificationMetaObject::fromArray($data['_meta']);
        }

        return new self(meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $meta = $this->meta->toArray();

        return [] !== $meta ? ['_meta' => $meta] : [];
    }
}
