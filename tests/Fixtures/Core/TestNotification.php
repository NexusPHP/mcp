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

namespace Nexus\Mcp\Tests\Fixtures\Core;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Internal\NotificationParams;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;

/**
 * @internal
 *
 * @extends JsonRpcNotification<'tests/test-notification'>
 */
final readonly class TestNotification extends JsonRpcNotification
{
    public function __construct(NotificationParams $params = new NotificationParams())
    {
        parent::__construct($params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'tests/test-notification';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $params = new NotificationParams();

        if (\array_key_exists('params', $data)) {
            Assert::that($data['params'])
                ->isArray('TestNotification wire "params" must be an object, {type} given.')
                ->isMap('TestNotification wire "params" must be a string-keyed object.')
            ;
            $params = NotificationParams::fromArray($data['params']);
        }

        return new self($params);
    }
}
