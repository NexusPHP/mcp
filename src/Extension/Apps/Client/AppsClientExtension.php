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
use Nexus\Mcp\Client\ClientExtensionInterface;
use Nexus\Mcp\Extension\Apps\Apps;

/**
 * The official MCP Apps extension (`io.modelcontextprotocol/ui`, SEP-1865)
 * for the client: declares the renderable UI mime types on every request's
 * `_meta` capabilities.
 */
final readonly class AppsClientExtension implements ClientExtensionInterface
{
    /**
     * @param list<non-empty-string> $mimeTypes The UI resource mime types the host renders
     */
    public function __construct(private array $mimeTypes = [Apps::MIME_TYPE])
    {
        Assert::that($mimeTypes)->isList('"mimeTypes" must be a list, {type} given.');
        Assert::that(\count($mimeTypes))->isPositiveInt('"mimeTypes" must not be empty.');
        Assert::that($mimeTypes)
            ->values()
            ->isNonEmptyString('each "mimeTypes" must be a non-empty string, {type} given.')
        ;
    }

    #[\Override]
    public function getIdentifier(): string
    {
        return Apps::IDENTIFIER;
    }

    #[\Override]
    public function getSettings(): array
    {
        return ['mimeTypes' => $this->mimeTypes];
    }

    #[\Override]
    public function getRequests(): array
    {
        return [];
    }

    #[\Override]
    public function getNotifications(): array
    {
        return [];
    }

    #[\Override]
    public function getRequestHandlers(): array
    {
        return [];
    }

    #[\Override]
    public function getNotificationHandlers(): array
    {
        return [];
    }

    #[\Override]
    public function getOutboundRequests(): array
    {
        return [];
    }
}
