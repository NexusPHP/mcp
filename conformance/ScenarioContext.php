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

use Nexus\Assert\Assert;
use Nexus\Mcp\Client\Auth\ClientRegistration;

/**
 * The scenario JSON that the referee supplies in `MCP_CONFORMANCE_CONTEXT`.
 *
 * @phpstan-type ToolCall array{name: non-empty-string, arguments: array<string, mixed>}
 */
final readonly class ScenarioContext
{
    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public static function fromEnvironment(): self
    {
        $raw = getenv('MCP_CONFORMANCE_CONTEXT');
        $context = is_string($raw) && '' !== $raw ? json_decode($raw, true, 512, \JSON_THROW_ON_ERROR) : [];

        return new self(is_array($context) ? $context : []);
    }

    /**
     * @return list<ToolCall>
     */
    public function readToolCalls(): array
    {
        $calls = is_array($this->values['toolCalls'] ?? null) ? $this->values['toolCalls'] : [];
        $normalized = [];

        foreach ($calls as $call) {
            if (is_array($call) && is_string($call['name'] ?? null) && '' !== $call['name']) {
                /** @var array<string, mixed> $arguments */
                $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
                $normalized[] = ['name' => $call['name'], 'arguments' => $arguments];
            }
        }

        return $normalized;
    }

    /**
     * @return non-empty-string
     */
    public function readString(string $key): string
    {
        $value = $this->values[$key] ?? null;
        Assert::that($value)->isNonEmptyString(sprintf('The scenario context must carry a non-empty "%s".', $key));

        return $value;
    }

    public function readPreRegistration(): ?ClientRegistration
    {
        $clientId = is_string($this->values['client_id'] ?? null) ? $this->values['client_id'] : null;
        $clientSecret = is_string($this->values['client_secret'] ?? null) ? $this->values['client_secret'] : null;

        return null !== $clientId ? new ClientRegistration(clientId: $clientId, clientSecret: $clientSecret) : null;
    }
}
