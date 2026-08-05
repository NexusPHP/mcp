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

namespace Nexus\Mcp\Extension\Apps\Schema;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Content Security Policy allow-lists a UI resource declares for its sandbox.
 * The spec treats an empty list and an omitted one identically, so empty
 * lists are omitted from the encoded form.
 *
 * @implements Arrayable<array{
 *   connectDomains?: list<non-empty-string>,
 *   resourceDomains?: list<non-empty-string>,
 *   frameDomains?: list<non-empty-string>,
 *   baseUriDomains?: list<non-empty-string>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/ext-apps/blob/main/specification/2026-01-26/apps.mdx
 */
final readonly class UiResourceCsp implements Arrayable
{
    /**
     * @param null|list<non-empty-string> $connectDomains  Origins allowed for `connect-src`
     * @param null|list<non-empty-string> $resourceDomains Origins allowed for static assets (`img-src`, `script-src`, and friends)
     * @param null|list<non-empty-string> $frameDomains    Origins allowed for `frame-src`
     * @param null|list<non-empty-string> $baseUriDomains  Origins allowed for `base-uri`
     */
    public function __construct(
        public ?array $connectDomains = null,
        public ?array $resourceDomains = null,
        public ?array $frameDomains = null,
        public ?array $baseUriDomains = null,
    ) {
        self::assertDomainList($connectDomains, 'connectDomains');
        self::assertDomainList($resourceDomains, 'resourceDomains');
        self::assertDomainList($frameDomains, 'frameDomains');
        self::assertDomainList($baseUriDomains, 'baseUriDomains');
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            connectDomains: self::parseDomainList($data, 'connectDomains'),
            resourceDomains: self::parseDomainList($data, 'resourceDomains'),
            frameDomains: self::parseDomainList($data, 'frameDomains'),
            baseUriDomains: self::parseDomainList($data, 'baseUriDomains'),
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if (null !== $this->connectDomains && [] !== $this->connectDomains) {
            $data['connectDomains'] = $this->connectDomains;
        }

        if (null !== $this->resourceDomains && [] !== $this->resourceDomains) {
            $data['resourceDomains'] = $this->resourceDomains;
        }

        if (null !== $this->frameDomains && [] !== $this->frameDomains) {
            $data['frameDomains'] = $this->frameDomains;
        }

        if (null !== $this->baseUriDomains && [] !== $this->baseUriDomains) {
            $data['baseUriDomains'] = $this->baseUriDomains;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        return [] === $data ? new \stdClass() : $data;
    }

    /**
     * @param null|list<non-empty-string> $domains
     * @param non-empty-string            $slot
     */
    private static function assertDomainList(?array $domains, string $slot): void
    {
        if (null !== $domains) {
            Assert::that($domains)
                ->isList(\sprintf('"_meta.ui.csp.%s" must be a list, {type} given.', $slot))
                ->values()
                ->isNonEmptyString(\sprintf('each "_meta.ui.csp.%s" must be a non-empty string, {type} given.', $slot))
            ;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param non-empty-string     $slot
     *
     * @return null|list<non-empty-string>
     */
    private static function parseDomainList(array $data, string $slot): ?array
    {
        if (! isset($data[$slot])) {
            return null;
        }

        $domains = $data[$slot];
        Assert::that($domains)
            ->isList(\sprintf('"_meta.ui.csp.%s" must be a list, {type} given.', $slot))
            ->values()
            ->isNonEmptyString(\sprintf('each "_meta.ui.csp.%s" must be a non-empty string, {type} given.', $slot))
        ;

        return $domains;
    }
}
