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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;

/**
 * The set of notification types a client may opt in to on a `subscriptions/listen` request.
 *
 * Each notification type is **opt-in**; the server **MUST NOT** send notification types the
 * client has not explicitly requested here.
 *
 * @implements Arrayable<array{
 *   toolsListChanged?: bool,
 *   promptsListChanged?: bool,
 *   resourcesListChanged?: bool,
 *   resourceSubscriptions?: list<string>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#subscriptionfilter
 */
final readonly class SubscriptionFilter implements Arrayable
{
    /**
     * @param null|list<string> $resourceSubscriptions
     */
    public function __construct(
        public ?bool $toolsListChanged = null,
        public ?bool $promptsListChanged = null,
        public ?bool $resourcesListChanged = null,
        public ?array $resourceSubscriptions = null,
    ) {
        if (null !== $this->resourceSubscriptions) {
            Assert::that($this->resourceSubscriptions)
                ->isList('"notifications.resourceSubscriptions" must be a list, non-list array given.')
                ->values()->isString('each "notifications.resourceSubscriptions" must be a string, {type} given.')
            ;
        }
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $toolsListChanged = $data['toolsListChanged'] ?? null;
        Assert::that($toolsListChanged)
            ->nullOr()
            ->isBool('"notifications.toolsListChanged" must be a bool, {type} given.')
        ;

        $promptsListChanged = $data['promptsListChanged'] ?? null;
        Assert::that($promptsListChanged)
            ->nullOr()
            ->isBool('"notifications.promptsListChanged" must be a bool, {type} given.')
        ;

        $resourcesListChanged = $data['resourcesListChanged'] ?? null;
        Assert::that($resourcesListChanged)
            ->nullOr()
            ->isBool('"notifications.resourcesListChanged" must be a bool, {type} given.')
        ;

        $resourceSubscriptions = $data['resourceSubscriptions'] ?? null;

        if (null !== $resourceSubscriptions) {
            Assert::that($resourceSubscriptions)
                ->isList('"notifications.resourceSubscriptions" must be a list, {type} given.')
                ->values()->isString('each "notifications.resourceSubscriptions" must be a string, {type} given.')
            ;
        }

        return new self(
            toolsListChanged: $toolsListChanged,
            promptsListChanged: $promptsListChanged,
            resourcesListChanged: $resourcesListChanged,
            resourceSubscriptions: $resourceSubscriptions,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if (null !== $this->toolsListChanged) {
            $data['toolsListChanged'] = $this->toolsListChanged;
        }

        if (null !== $this->promptsListChanged) {
            $data['promptsListChanged'] = $this->promptsListChanged;
        }

        if (null !== $this->resourcesListChanged) {
            $data['resourcesListChanged'] = $this->resourcesListChanged;
        }

        if (null !== $this->resourceSubscriptions) {
            $data['resourceSubscriptions'] = $this->resourceSubscriptions;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        return [] === $data ? new \stdClass() : $data;
    }
}
