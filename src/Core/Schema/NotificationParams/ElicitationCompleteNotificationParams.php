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
 * Parameters for a `notifications/elicitation/complete` notification.
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#elicitationcompletenotificationparams
 */
final readonly class ElicitationCompleteNotificationParams extends NotificationParams
{
    /**
     * @var non-empty-string
     */
    public string $elicitationId;

    public function __construct(string $elicitationId, MetaObject $meta = new MetaObject())
    {
        Assert::that($elicitationId)->isNonEmptyString('"params.elicitationId" must be a non-empty string.');

        $this->elicitationId = $elicitationId;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('elicitationId', 'missing the required "elicitationId" key.');
        $elicitationId = $data['elicitationId'];
        Assert::that($elicitationId)->isString('"params.elicitationId" must be a string, {type} given.');

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(elicitationId: $elicitationId, meta: $meta);
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
