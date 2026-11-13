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

namespace Nexus\Mcp\Extension\Apps\Server;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Extension\Apps\Apps;
use Nexus\Mcp\Extension\Apps\Schema\UiResourceMeta;
use Nexus\Mcp\Extension\Apps\Schema\UiToolMeta;
use Nexus\Mcp\Server\Extension\RegistrationValidatorInterface;
use Nexus\Mcp\Server\Extension\ServerExtensionInterface;

/**
 * The official MCP Apps extension (`io.modelcontextprotocol/ui`, SEP-1865) for the server.
 */
final readonly class AppsServerExtension implements RegistrationValidatorInterface, ServerExtensionInterface
{
    #[\Override]
    public function validateRegistrations(array $tools, array $resources, array $templates): void
    {
        foreach ($tools as $tool) {
            self::verifyUiMeta($tool->meta->extras, \sprintf('Tool "%s"', $tool->name), UiToolMeta::fromArray(...));
        }

        foreach ($resources as $resource) {
            $subject = \sprintf('Resource "%s"', $resource->uri);
            self::verifyUiPairing($resource->uri, $resource->mimeType, $subject);
            self::verifyUiMeta($resource->meta->extras, $subject, UiResourceMeta::fromArray(...));
        }

        foreach ($templates as $template) {
            self::verifyUiPairing(
                $template->uriTemplate,
                $template->mimeType,
                \sprintf('Resource template "%s"', $template->uriTemplate),
            );
        }
    }

    #[\Override]
    public function getIdentifier(): string
    {
        return Apps::IDENTIFIER;
    }

    #[\Override]
    public function getSettings(): array
    {
        return [];
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

    /**
     * SEP-1865: a UI resource MUST use the `ui://` scheme and the `text/html;profile=mcp-app` mime type.
     *
     * @throws LogicException
     */
    private static function verifyUiPairing(string $uri, ?string $mimeType, string $subject): void
    {
        if (str_starts_with($uri, Apps::URI_PREFIX) === (Apps::MIME_TYPE === $mimeType)) {
            return;
        }

        throw new LogicException(\sprintf(
            '%s must pair the "%s" scheme with the "%s" mime type, or use neither.',
            $subject,
            Apps::URI_PREFIX,
            Apps::MIME_TYPE,
        ));
    }

    /**
     * @param array<array-key, mixed>               $extras
     * @param \Closure(array<string, mixed>): mixed $decode
     *
     * @throws LogicException
     */
    private static function verifyUiMeta(array $extras, string $subject, \Closure $decode): void
    {
        if (! \array_key_exists(Apps::META_KEY, $extras)) {
            return;
        }

        try {
            $meta = $extras[Apps::META_KEY];
            Assert::that($meta)->isMap('"_meta.ui" must be a string-keyed object.');
            $decode($meta);
        } catch (\InvalidArgumentException $e) {
            throw new LogicException(\sprintf('%s carries an invalid "_meta.ui": %s', $subject, $e->getMessage()));
        }
    }
}
