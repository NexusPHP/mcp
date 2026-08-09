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
 * The `_meta.ui` object on a UI resource, carried by both its `resources/list`
 * descriptor and its `resources/read` contents.
 *
 * @implements Arrayable<array{
 *   csp?: array{
 *     connectDomains?: list<non-empty-string>,
 *     resourceDomains?: list<non-empty-string>,
 *     frameDomains?: list<non-empty-string>,
 *     baseUriDomains?: list<non-empty-string>,
 *   },
 *   permissions?: array{
 *     camera?: \stdClass,
 *     microphone?: \stdClass,
 *     geolocation?: \stdClass,
 *     clipboardWrite?: \stdClass,
 *   },
 *   domain?: non-empty-string,
 *   prefersBorder?: bool,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/ext-apps/blob/main/specification/2026-01-26/apps.mdx
 */
final readonly class UiResourceMeta implements Arrayable
{
    public ?UiResourceCsp $csp;
    public ?UiResourcePermissions $permissions;

    /**
     * @param null|non-empty-string $domain        Host-defined dedicated origin for the view sandbox
     * @param null|bool             $prefersBorder Whether the host should draw a visible border and background
     */
    public function __construct(
        ?UiResourceCsp $csp = null,
        ?UiResourcePermissions $permissions = null,
        public ?string $domain = null,
        public ?bool $prefersBorder = null,
    ) {
        Assert::that($domain)->nullOr()->isNonEmptyString('"_meta.ui.domain" must be a non-empty string or null.');

        $this->csp = null === $csp || [] === $csp->toArray() ? null : $csp;
        $this->permissions = null === $permissions || [] === $permissions->toArray() ? null : $permissions;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $csp = null;

        if (isset($data['csp'])) {
            Assert::that($data['csp'])
                ->isArray('"_meta.ui.csp" must be an array, {type} given.')
                ->isMap('"_meta.ui.csp" must be a string-keyed object.')
            ;
            $csp = UiResourceCsp::fromArray($data['csp']);
        }

        $permissions = null;

        if (isset($data['permissions'])) {
            Assert::that($data['permissions'])
                ->isArray('"_meta.ui.permissions" must be an array, {type} given.')
                ->isMap('"_meta.ui.permissions" must be a string-keyed object.')
            ;
            $permissions = UiResourcePermissions::fromArray($data['permissions']);
        }

        $domain = $data['domain'] ?? null;
        Assert::that($domain)->nullOr()->isNonEmptyString('"_meta.ui.domain" must be a non-empty string or null.');

        $prefersBorder = $data['prefersBorder'] ?? null;
        Assert::that($prefersBorder)->nullOr()->isBool('"_meta.ui.prefersBorder" must be a bool or null, {type} given.');

        return new self(csp: $csp, permissions: $permissions, domain: $domain, prefersBorder: $prefersBorder);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if (null !== $this->csp) {
            $data['csp'] = $this->csp->toArray();
        }

        if (null !== $this->permissions) {
            $data['permissions'] = $this->permissions->toArray();
        }

        if (null !== $this->domain) {
            $data['domain'] = $this->domain;
        }

        if (null !== $this->prefersBorder) {
            $data['prefersBorder'] = $this->prefersBorder;
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
