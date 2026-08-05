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

use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Extension\Apps\Client\Exception\InvalidUiResourceContentsException;
use Nexus\Mcp\Extension\Apps\Schema\UiResourceMeta;
use Nexus\Mcp\Extension\Apps\Schema\UiToolMeta;

/**
 * Client-side surface of the MCP Apps extension: typed readers for the
 * `_meta.ui` metadata on tools and resources, and a verified read of a
 * `ui://` resource.
 */
interface AppClientInterface
{
    /**
     * Reads a tool's `_meta.ui` object, tolerating the deprecated flat
     * `_meta["ui/resourceUri"]` key as a fallback when the nested form is
     * absent or carries no `resourceUri`.
     */
    public function resolveToolMeta(Tool $tool): ?UiToolMeta;

    /**
     * Reads the `_meta.ui` object off a resource descriptor or a read
     * content item.
     */
    public function resolveResourceMeta(Resource|ResourceContents $source): ?UiResourceMeta;

    /**
     * The tools whose `_meta.ui` links a UI resource, paired with their
     * resolved metadata. A tool whose metadata cannot be decoded is skipped.
     *
     * @return list<AppTool>
     */
    public function findAppTools(ListToolsResult $result): array;

    /**
     * Reads a `ui://` resource, verifying every returned content item carries
     * one of the accepted UI mime types.
     *
     * @param non-empty-string                  $uri
     * @param null|array<string, InputResponse> $inputResponses Answers to a prior `InputRequiredResult`, keyed as its `inputRequests` were
     * @param null|string                       $requestState   Echoed verbatim from the `InputRequiredResult` being answered
     *
     * @throws InvalidUiResourceContentsException
     */
    public function readAppResource(string $uri, ?array $inputResponses = null, ?string $requestState = null): InputRequiredResult|ReadResourceResult;
}
