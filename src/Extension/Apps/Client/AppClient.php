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

namespace Nexus\Mcp\Extension\Apps\Client;

use Nexus\Assert\Assert;
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Extension\Apps\Apps;
use Nexus\Mcp\Extension\Apps\Schema\UiResourceMeta;
use Nexus\Mcp\Extension\Apps\Schema\UiToolMeta;

/**
 * App-aware client surface for the MCP Apps extension.
 */
final readonly class AppClient implements AppClientInterface
{
    /**
     * @param list<non-empty-string> $mimeTypes The accepted UI mime types, matching the enabled extension's declaration
     */
    public function __construct(
        private Client $client,
        private array $mimeTypes = [Apps::MIME_TYPE],
    ) {
        Assert::that($mimeTypes)->isList('"mimeTypes" must be a list, {type} given.');
        Assert::that(\count($mimeTypes))->isPositiveInt('"mimeTypes" must not be empty.');
        Assert::that($mimeTypes)
            ->values()
            ->isNonEmptyString('each "mimeTypes" must be a non-empty string, {type} given.')
        ;
    }

    #[\Override]
    public function resolveToolMeta(Tool $tool): ?UiToolMeta
    {
        $extras = $tool->meta->extras;
        $nested = null;

        if (\array_key_exists(Apps::META_KEY, $extras)) {
            $meta = $extras[Apps::META_KEY];
            Assert::that($meta)
                ->isArray('tool "_meta.ui" must be an array, {type} given.')
                ->isMap('tool "_meta.ui" must be a string-keyed object.')
            ;
            $nested = UiToolMeta::fromArray($meta);

            if (null !== $nested->resourceUri) {
                return $nested;
            }
        }

        if (! \array_key_exists(Apps::DEPRECATED_RESOURCE_URI_KEY, $extras)) {
            return $nested;
        }

        $resourceUri = $extras[Apps::DEPRECATED_RESOURCE_URI_KEY];
        Assert::that($resourceUri)->isNonEmptyString(\sprintf('tool "_meta" key "%s" must be a non-empty string, {type} given.', Apps::DEPRECATED_RESOURCE_URI_KEY));

        return new UiToolMeta(resourceUri: $resourceUri, visibility: $nested?->visibility);
    }

    #[\Override]
    public function resolveResourceMeta(Resource|ResourceContents $source): ?UiResourceMeta
    {
        $extras = $source->meta->extras;

        if (! \array_key_exists(Apps::META_KEY, $extras)) {
            return null;
        }

        $meta = $extras[Apps::META_KEY];
        Assert::that($meta)
            ->isArray('resource "_meta.ui" must be an array, {type} given.')
            ->isMap('resource "_meta.ui" must be a string-keyed object.')
        ;

        return UiResourceMeta::fromArray($meta);
    }

    #[\Override]
    public function findAppTools(ListToolsResult $result): array
    {
        $appTools = [];

        foreach ($result->tools as $tool) {
            try {
                $uiMeta = $this->resolveToolMeta($tool);
            } catch (ExpectationFailedException) {
                continue;
            }

            if (null !== $uiMeta?->resourceUri) {
                $appTools[] = new AppTool(tool: $tool, uiMeta: $uiMeta);
            }
        }

        return $appTools;
    }

    #[\Override]
    public function readAppResource(string $uri, ?array $inputResponses = null, ?string $requestState = null): InputRequiredResult|ReadResourceResult
    {
        Assert::that($uri)->startsWith(Apps::URI_PREFIX, \sprintf('UI resource "uri" must start with "%s".', Apps::URI_PREFIX));

        $result = $this->client->readResource($uri, $inputResponses, $requestState);

        if ($result instanceof InputRequiredResult) {
            return $result;
        }

        if ([] === $result->contents) {
            throw new RuntimeException(\sprintf(
                'UI resource "%s" returned no contents.',
                SafeDisplay::sanitiseCause($uri),
            ));
        }

        foreach ($result->contents as $contents) {
            if (! \in_array($contents->mimeType, $this->mimeTypes, true)) {
                throw new RuntimeException(\sprintf(
                    'UI resource "%s" returned contents of mime type "%s", expected one of "%s".',
                    SafeDisplay::sanitiseCause($uri),
                    SafeDisplay::sanitise($contents->mimeType ?? ''),
                    implode('", "', $this->mimeTypes),
                ));
            }
        }

        return $result;
    }
}
