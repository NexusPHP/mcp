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
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Validation\EnumValueValidator;
use Nexus\Mcp\Core\Validation\Iso8601DateTimeValidator;

/**
 * Optional annotations for the client. The client can use annotations to inform how objects are
 * used or displayed.
 *
 * @implements Arrayable<array{
 *   audience?: list<'assistant'|'user'>,
 *   priority?: float,
 *   lastModified?: string,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#annotations
 */
final readonly class Annotations implements Arrayable
{
    use ParsesNumber;

    public ?\DateTimeImmutable $lastModified;

    /**
     * @param null|list<Role> $audience
     */
    public function __construct(
        public ?array $audience = null,
        public ?float $priority = null,
        ?string $lastModified = null,
    ) {
        if (null !== $this->audience) {
            Assert::that($this->audience)
                ->values()
                ->isInstanceOf(Role::class, 'each "annotations.audience" must be a valid role, {type} given.')
            ;
        }

        Assert::that($this->priority)->nullOr()->isBetween(0.0, 1.0, message: '"annotations.priority" must be between 0.0 and 1.0.');

        if (null !== $lastModified) {
            $lastModified = Iso8601DateTimeValidator::parse($lastModified, '"annotations.lastModified"');
        }

        $this->lastModified = $lastModified;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $audience = null;

        if (isset($data['audience'])) {
            Assert::that($data['audience'])->isList('"annotations.audience" must be a list, {type} given.');
            $audience = array_map(
                static fn(mixed $role): Role => EnumValueValidator::parse(Role::class, $role, 'each "annotations.audience"'),
                $data['audience'],
            );
        }

        $priority = $data['priority'] ?? null;

        if (null !== $priority) {
            $priority = self::parseNumber($priority, '"annotations.priority" must be a number or null, {type} given.');
        }

        $lastModified = $data['lastModified'] ?? null;
        Assert::that($lastModified)->nullOr()->isString('"annotations.lastModified" must be a string or null, {type} given.');

        return new self(audience: $audience, priority: $priority, lastModified: $lastModified);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if (null !== $this->audience) {
            $data['audience'] = array_map(static fn(Role $role): string => $role->value, $this->audience);
        }

        if (null !== $this->priority) {
            $data['priority'] = $this->priority;
        }

        if (null !== $this->lastModified) {
            $data['lastModified'] = Iso8601DateTimeValidator::format($this->lastModified);
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
