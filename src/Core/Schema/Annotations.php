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

/**
 * Optional annotations for the client. The client can use annotations to inform how objects are used or displayed.
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
    /**
     * @var null|list<Role>
     */
    public ?array $audience;

    public ?float $priority;
    public ?\DateTimeImmutable $lastModified;

    /**
     * @param null|list<Role> $audience
     */
    public function __construct(?array $audience = null, ?float $priority = null, ?string $lastModified = null)
    {
        if (null !== $audience) {
            foreach ($audience as $role) {
                Assert::that($role)->isInstanceOf(Role::class);
            }
        }

        if (null !== $priority && ($priority < 0.0 || $priority > 1.0)) {
            throw new \InvalidArgumentException('Priority must be between 0.0 and 1.0.');
        }

        if (null !== $lastModified) {
            $lastModified = self::parseLastModified($lastModified);
        }

        $this->audience = $audience;
        $this->priority = $priority;
        $this->lastModified = $lastModified;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $data += ['audience' => null, 'priority' => null, 'lastModified' => null];

        if (isset($data['audience'])) {
            $data['audience'] = array_map(static fn(string $role): Role => Role::from($role), $data['audience']);
        }

        return new self($data['audience'], $data['priority'], $data['lastModified']);
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
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function parseLastModified(string $lastModified): \DateTimeImmutable
    {
        if (str_contains($lastModified, "\0")) {
            throw new \InvalidArgumentException('Last modified must not contain NULL bytes.');
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $lastModified);

        if (false === $parsed) {
            $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $lastModified);
        }

        if (false === $parsed) {
            throw new \InvalidArgumentException('Last modified must be a valid ISO 8601 datetime.');
        }

        $errors = \DateTimeImmutable::getLastErrors();

        if (false !== $errors && [] !== $errors['warnings']) {
            throw new \InvalidArgumentException(implode('; ', $errors['warnings']).'.');
        }

        return $parsed;
    }
}
