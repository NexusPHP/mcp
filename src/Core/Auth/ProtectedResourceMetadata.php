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

namespace Nexus\Mcp\Core\Auth;

/**
 * The OAuth 2.0 Protected Resource Metadata document an MCP server publishes.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728#section-2
 */
final readonly class ProtectedResourceMetadata
{
    /**
     * @var non-empty-list<non-empty-string>
     */
    public array $authorizationServers;

    /**
     * @param list<non-empty-string>      $authorizationServers
     * @param null|list<non-empty-string> $bearerMethodsSupported
     * @param null|non-empty-string       $resourceName
     */
    public function __construct(
        public ResourceIdentifier $resource,
        array $authorizationServers,
        public ?ScopeSet $scopesSupported = null,
        public ?array $bearerMethodsSupported = null,
        public ?string $resourceName = null,
    ) {
        if ([] === $authorizationServers) {
            throw new \InvalidArgumentException('Protected Resource Metadata must name at least one authorization server.');
        }

        $this->authorizationServers = $authorizationServers;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reader = new MetadataReader('Protected Resource Metadata');
        $scopes = $reader->readStringList($data, 'scopes_supported');

        return new self(
            new ResourceIdentifier($reader->readRequiredString($data, 'resource')),
            $reader->readStringList($data, 'authorization_servers') ?? [],
            null === $scopes ? null : ScopeSet::fromList($scopes),
            $reader->readStringList($data, 'bearer_methods_supported'),
            $reader->readString($data, 'resource_name'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'resource' => $this->resource->value,
            'authorization_servers' => $this->authorizationServers,
        ];

        if (null !== $this->scopesSupported) {
            $data['scopes_supported'] = $this->scopesSupported->values;
        }

        if (null !== $this->bearerMethodsSupported) {
            $data['bearer_methods_supported'] = $this->bearerMethodsSupported;
        }

        if (null !== $this->resourceName) {
            $data['resource_name'] = $this->resourceName;
        }

        return $data;
    }
}
