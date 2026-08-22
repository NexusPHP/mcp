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
use Nexus\Mcp\Extension\Apps\Apps;
use Nexus\Mcp\Extension\Apps\Schema\Enum\ToolVisibility;

/**
 * The `_meta.ui` object on a tool, linking it to the UI resource that renders its results.
 *
 * @implements Arrayable<array{
 *   resourceUri?: non-empty-string,
 *   visibility?: list<'app'|'model'>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/ext-apps/blob/main/specification/2026-01-26/apps.mdx
 */
final readonly class UiToolMeta implements Arrayable
{
    /**
     * @param null|non-empty-string     $resourceUri
     * @param null|list<ToolVisibility> $visibility  Omission means the spec default `["model", "app"]`
     */
    public function __construct(
        public ?string $resourceUri = null,
        public ?array $visibility = null,
    ) {
        if (null !== $resourceUri) {
            Assert::that($resourceUri)->startsWith(Apps::URI_PREFIX, \sprintf('"_meta.ui.resourceUri" must start with "%s".', Apps::URI_PREFIX));
        }

        if (null !== $visibility) {
            Assert::that($visibility)
                ->isList('"_meta.ui.visibility" must be a list, {type} given.')
                ->values()
                ->isInstanceOf(ToolVisibility::class, 'each "_meta.ui.visibility" must be a tool visibility, {type} given.')
            ;
        }
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $resourceUri = $data['resourceUri'] ?? null;
        Assert::that($resourceUri)->nullOr()->isNonEmptyString('"_meta.ui.resourceUri" must be a non-empty string or null, {type} given.');

        $visibility = null;

        if (isset($data['visibility'])) {
            Assert::that($data['visibility'])->isList('"_meta.ui.visibility" must be a list, {type} given.');
            $visibility = array_map(
                static function (mixed $entry): ToolVisibility {
                    Assert::that($entry)->isOneOf(array_column(ToolVisibility::cases(), 'value'), 'each "_meta.ui.visibility" must be one of {choices}, {value} given.');

                    return ToolVisibility::from($entry);
                },
                $data['visibility'],
            );
        }

        return new self(resourceUri: $resourceUri, visibility: $visibility);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];

        if (null !== $this->resourceUri) {
            $data['resourceUri'] = $this->resourceUri;
        }

        if (null !== $this->visibility) {
            $data['visibility'] = array_map(static fn(ToolVisibility $visibility): string => $visibility->value, $this->visibility);
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
