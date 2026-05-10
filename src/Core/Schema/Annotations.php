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
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#annotations
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
            foreach ($this->audience as $role) {
                Assert::that($role)->isInstanceOf(Role::class);
            }
        }

        if (null !== $this->priority && ($this->priority < 0.0 || $this->priority > 1.0)) {
            throw new \InvalidArgumentException('Priority must be between 0.0 and 1.0.');
        }

        if (null !== $lastModified) {
            $lastModified = Iso8601DateTimeValidator::parse($lastModified, 'Last modified');
        }

        $this->lastModified = $lastModified;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $audience = null;

        if (isset($data['audience'])) {
            Assert::that($data['audience'])->isArray('Annotations wire "audience" must be an array, {type} given.');

            $audience = [];

            foreach ($data['audience'] as $role) {
                Assert::that($role)->isString('Annotations wire audience entry must be a string, {type} given.');
                $audience[] = Role::from($role);
            }
        }

        $priority = $data['priority'] ?? null;

        if (null !== $priority) {
            $priority = self::parseNumber($priority, 'Annotations wire "priority" must be a number or null, {type} given.');
        }

        $lastModified = $data['lastModified'] ?? null;
        Assert::that($lastModified)->nullOr()->isString('Annotations wire "lastModified" must be a string or null, {type} given.');

        return new self($audience, $priority, $lastModified);
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
            $data['lastModified'] = $this->lastModified->format(\DATE_RFC3339);
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
