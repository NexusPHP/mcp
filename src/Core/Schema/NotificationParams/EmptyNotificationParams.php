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
 * Default notification params for methods that carry no typed fields beyond `_meta`.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
final readonly class EmptyNotificationParams extends NotificationParams
{
    public function __construct(?Meta $meta = null)
    {
        parent::__construct($meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Notification params "_meta" must be an object, {type} given.')
                ->isMap('Notification params "_meta" must be a string-keyed object.')
            ;
            $meta = Meta::fromArray($data['_meta']);
        }

        return new self($meta);
    }
}
