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

namespace Nexus\Mcp\Core\Extension;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\DuplicateExtensionException;
use Nexus\Mcp\Core\Exception\ExtensionMethodCollisionException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMethodRegistry;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\ClientRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Validation\ExtensionIdentifierValidator;
use Nexus\Mcp\Core\Validation\MethodClassValidator;

/**
 * Collects the extensions a builder enables, validates each declaration, and
 * owns the method-ownership ledgers. Declarations are snapshotted at `add()`
 * time, so the maps served to the builders are the ones that were validated.
 *
 * @internal
 *
 * @template TContext of AbstractContext
 */
final class ExtensionCollection
{
    /**
     * @var array<non-empty-string, array<string, mixed>>
     */
    private array $settings = [];

    /**
     * @var array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>
     */
    private array $requestClasses = [];

    /**
     * @var array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>>
     */
    private array $notificationClasses = [];

    /**
     * @var array<non-empty-string, array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, TContext>>>
     */
    private array $requestHandlerGroups = [];

    /**
     * @var array<non-empty-string, NotificationHandlerInterface<non-empty-string>>
     */
    private array $notificationHandlers = [];

    /**
     * @var array<non-empty-string, non-empty-string>
     */
    private array $requestOwners = [];

    /**
     * @var array<non-empty-string, non-empty-string>
     */
    private array $notificationOwners = [];

    /**
     * @var array<non-empty-string, non-empty-string>
     */
    private array $outboundOwners = [];

    /**
     * @param ExtensionInterface<TContext> $extension
     * @param list<non-empty-string>       $claimedRequests       Methods a builder-registered request handler already owns
     * @param list<non-empty-string>       $claimedNotifications  Methods a builder-registered notification handler already owns
     * @param list<non-empty-string>       $outboundRequests      Client-to-server methods the extension invokes
     * @param bool                         $requireClientRequests Require each request class to implement `ClientRequest`
     *
     * @throws DuplicateExtensionException
     * @throws ExtensionMethodCollisionException
     */
    public function add(
        ExtensionInterface $extension,
        array $claimedRequests = [],
        array $claimedNotifications = [],
        array $outboundRequests = [],
        bool $requireClientRequests = false,
    ): void {
        $identifier = $extension->getIdentifier();
        ExtensionIdentifierValidator::validate($identifier);

        if (\array_key_exists($identifier, $this->settings)) {
            throw new DuplicateExtensionException($identifier);
        }

        $settings = $extension->getSettings();
        $requests = $extension->getRequests();
        $notifications = $extension->getNotifications();
        $requestHandlers = $extension->getRequestHandlers();
        $notificationHandlers = $extension->getNotificationHandlers();

        Assert::that($settings)->isMap(\sprintf('Extension "%s" settings must be a string-keyed object.', $identifier));

        self::assertPairedKeys($identifier, array_keys($requests), array_keys($requestHandlers), 'request');
        self::assertPairedKeys($identifier, array_keys($notifications), array_keys($notificationHandlers), 'notification');

        foreach ($requests as $method => $requestClass) {
            MethodClassValidator::validate($requestClass, $method);

            if ($requireClientRequests) {
                // TODO: switch to Assert's isSubclassOf() once nexusphp/assert ships it.
                Assert::that(is_subclass_of($requestClass, ClientRequest::class))->isTrue(\sprintf(
                    'Extension "%s" request class "%s" must implement "%s" for the server to dispatch it.',
                    $identifier,
                    $requestClass,
                    ClientRequest::class,
                ));
            }

            $this->assertUnclaimedRequest($identifier, $method, $claimedRequests);
        }

        foreach ($notifications as $method => $notificationClass) {
            MethodClassValidator::validate($notificationClass, $method, isNotification: true);
            $this->assertUnclaimedNotification($identifier, $method, $claimedNotifications);
        }

        $specRequests = JsonRpcMethodRegistry::requests();

        foreach ($outboundRequests as $method) {
            $claimant = \sprintf('Extension "%s"', $identifier);

            if (\array_key_exists($method, $specRequests)) {
                throw new ExtensionMethodCollisionException($claimant, $method, 'the MCP specification');
            }

            if (\array_key_exists($method, $this->outboundOwners)) {
                throw new ExtensionMethodCollisionException($claimant, $method, \sprintf('extension "%s"', $this->outboundOwners[$method]));
            }
        }

        $this->settings[$identifier] = $settings;
        $this->requestHandlerGroups[$identifier] = $requestHandlers;
        $this->requestClasses = [...$this->requestClasses, ...$requests];
        $this->notificationClasses = [...$this->notificationClasses, ...$notifications];
        $this->notificationHandlers = [...$this->notificationHandlers, ...$notificationHandlers];

        foreach (array_keys($requests) as $method) {
            $this->requestOwners[$method] = $identifier;
        }

        foreach (array_keys($notifications) as $method) {
            $this->notificationOwners[$method] = $identifier;
        }

        foreach ($outboundRequests as $method) {
            $this->outboundOwners[$method] = $identifier;
        }
    }

    /**
     * Refuses a builder-registered handler for a method an enabled extension owns.
     *
     * @param non-empty-string $method
     *
     * @throws ExtensionMethodCollisionException
     */
    public function assertNotOwned(string $method, bool $isNotification = false): void
    {
        $owner = $isNotification ? $this->findNotificationOwner($method) : $this->findRequestOwner($method);

        if (null !== $owner) {
            throw new ExtensionMethodCollisionException(
                'A builder-registered handler',
                $method,
                \sprintf('extension "%s"', $owner),
                isNotification: $isNotification,
            );
        }
    }

    /**
     * The identifier of the enabled extension owning `$method` as a request, if any.
     *
     * @return null|non-empty-string
     */
    public function findRequestOwner(string $method): ?string
    {
        return $this->requestOwners[$method] ?? null;
    }

    /**
     * The identifier of the enabled extension owning `$method` as a notification, if any.
     *
     * @return null|non-empty-string
     */
    public function findNotificationOwner(string $method): ?string
    {
        return $this->notificationOwners[$method] ?? null;
    }

    /**
     * The `extensions` capability slot, or null when no extension is enabled.
     *
     * @return null|array<non-empty-string, array<string, mixed>>
     */
    public function buildCapabilitySlot(): ?array
    {
        if ([] === $this->settings) {
            return null;
        }

        return $this->settings;
    }

    /**
     * @return array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>
     */
    public function buildRequestClasses(): array
    {
        return $this->requestClasses;
    }

    /**
     * @return array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>>
     */
    public function buildNotificationClasses(): array
    {
        return $this->notificationClasses;
    }

    /**
     * The validated request handlers of every enabled extension, merged.
     *
     * @return array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, TContext>>
     */
    public function buildRequestHandlers(): array
    {
        $handlers = [];

        foreach ($this->requestHandlerGroups as $group) {
            $handlers = [...$handlers, ...$group];
        }

        return $handlers;
    }

    /**
     * The validated notification handlers of every enabled extension, merged.
     *
     * @return array<non-empty-string, NotificationHandlerInterface<non-empty-string>>
     */
    public function buildNotificationHandlers(): array
    {
        return $this->notificationHandlers;
    }

    /**
     * The validated request handlers keyed by owning identifier, for per-extension decoration.
     *
     * @return array<non-empty-string, array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, TContext>>>
     */
    public function getRequestHandlerGroups(): array
    {
        return $this->requestHandlerGroups;
    }

    /**
     * Outbound method to owning identifier, for the client's send gate.
     *
     * @return array<non-empty-string, non-empty-string>
     */
    public function getOutboundOwners(): array
    {
        return $this->outboundOwners;
    }

    /**
     * @param list<non-empty-string> $classMethods
     * @param list<non-empty-string> $handlerMethods
     * @param non-empty-string       $kind
     */
    private static function assertPairedKeys(string $identifier, array $classMethods, array $handlerMethods, string $kind): void
    {
        sort($classMethods);
        sort($handlerMethods);

        Assert::that($handlerMethods)->isIdentical($classMethods, \sprintf(
            'Extension "%s" must declare its %s classes and %s handlers under the same method keys.',
            $identifier,
            $kind,
            $kind,
        ));
    }

    /**
     * @param non-empty-string       $identifier
     * @param non-empty-string       $method
     * @param list<non-empty-string> $claimed
     */
    private function assertUnclaimedRequest(string $identifier, string $method, array $claimed): void
    {
        $claimant = \sprintf('Extension "%s"', $identifier);

        if (\array_key_exists($method, JsonRpcMethodRegistry::requests())) {
            throw new ExtensionMethodCollisionException($claimant, $method, 'the MCP specification');
        }

        if (\array_key_exists($method, $this->requestOwners)) {
            throw new ExtensionMethodCollisionException($claimant, $method, \sprintf('extension "%s"', $this->requestOwners[$method]));
        }

        if (\in_array($method, $claimed, true)) {
            throw new ExtensionMethodCollisionException($claimant, $method, 'a builder-registered handler');
        }
    }

    /**
     * @param non-empty-string       $identifier
     * @param non-empty-string       $method
     * @param list<non-empty-string> $claimed
     */
    private function assertUnclaimedNotification(string $identifier, string $method, array $claimed): void
    {
        $claimant = \sprintf('Extension "%s"', $identifier);

        if (\array_key_exists($method, JsonRpcMethodRegistry::notifications())) {
            throw new ExtensionMethodCollisionException($claimant, $method, 'the MCP specification', isNotification: true);
        }

        if (\array_key_exists($method, $this->notificationOwners)) {
            throw new ExtensionMethodCollisionException($claimant, $method, \sprintf('extension "%s"', $this->notificationOwners[$method]), isNotification: true);
        }

        if (\in_array($method, $claimed, true)) {
            throw new ExtensionMethodCollisionException($claimant, $method, 'a builder-registered handler', isNotification: true);
        }
    }
}
