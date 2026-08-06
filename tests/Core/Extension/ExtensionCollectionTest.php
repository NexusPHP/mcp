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

namespace Nexus\Mcp\Tests\Core\Extension;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Exception\DuplicateExtensionException;
use Nexus\Mcp\Core\Exception\ExtensionMethodCollisionException;
use Nexus\Mcp\Core\Extension\ExtensionCollection;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Extension\StubExtension;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\TestClientRequest;
use Nexus\Mcp\Tests\Fixtures\Core\TestNotification;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use Nexus\Mcp\Tests\Fixtures\Core\TestSecondNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ExtensionCollection::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ExtensionCollectionTest extends AbstractMcpTestCase
{
    public function testAddSnapshotsTheDeclarationAndItsOwnership(): void
    {
        $requestHandler = self::buildRequestHandler();
        $notificationHandler = self::buildNotificationHandler();
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(
            identifier: 'com.example/feature',
            settings: ['flags' => ['beta']],
            requests: [TestRequest::getMethod() => TestRequest::class],
            notifications: [TestNotification::getMethod() => TestNotification::class],
            requestHandlers: [TestRequest::getMethod() => $requestHandler],
            notificationHandlers: [TestNotification::getMethod() => $notificationHandler],
        ), outboundRequests: ['acme/lookup']);

        self::assertSame('com.example/feature', $collection->findRequestOwner(TestRequest::getMethod()));
        self::assertSame('com.example/feature', $collection->findNotificationOwner(TestNotification::getMethod()));
        self::assertNull($collection->findRequestOwner('acme/unclaimed'));
        self::assertNull($collection->findNotificationOwner('acme/unclaimed'));
        self::assertSame(['com.example/feature' => ['flags' => ['beta']]], $collection->buildCapabilitySlot());
        self::assertSame([TestRequest::getMethod() => TestRequest::class], $collection->buildRequestClasses());
        self::assertSame([TestNotification::getMethod() => TestNotification::class], $collection->buildNotificationClasses());
        self::assertSame([TestRequest::getMethod() => $requestHandler], $collection->buildRequestHandlers());
        self::assertSame([TestNotification::getMethod() => $notificationHandler], $collection->buildNotificationHandlers());
        self::assertSame(['com.example/feature' => [TestRequest::getMethod() => $requestHandler]], $collection->getRequestHandlerGroups());
        self::assertSame(['acme/lookup' => 'com.example/feature'], $collection->getOutboundOwners());
        self::assertSame([], $collection->getRequestDecoratorGroups());
    }

    public function testAnEmptyCollectionBuildsNothing(): void
    {
        $collection = new ExtensionCollection();

        self::assertNull($collection->buildCapabilitySlot());
        self::assertSame([], $collection->buildRequestClasses());
        self::assertSame([], $collection->buildNotificationClasses());
        self::assertSame([], $collection->buildRequestHandlers());
        self::assertSame([], $collection->buildNotificationHandlers());
        self::assertSame([], $collection->getRequestHandlerGroups());
        self::assertSame([], $collection->getOutboundOwners());
        self::assertSame([], $collection->getRequestDecoratorGroups());
    }

    public function testAddStoresDecoratorGroupsInEnableOrder(): void
    {
        $first = static fn(RequestHandlerInterface $handler): RequestHandlerInterface => $handler;
        $second = static fn(RequestHandlerInterface $handler): RequestHandlerInterface => $handler;
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(identifier: 'com.example/first'), requestDecorators: [CallToolRequest::getMethod() => $first]);
        $collection->add(new StubExtension(identifier: 'com.example/second'), requestDecorators: [CallToolRequest::getMethod() => $second]);

        self::assertSame(
            [
                'com.example/first' => [CallToolRequest::getMethod() => $first],
                'com.example/second' => [CallToolRequest::getMethod() => $second],
            ],
            $collection->getRequestDecoratorGroups(),
        );
    }

    public function testAddRejectsADecoratorOnANonRegistryMethod(): void
    {
        $collection = new ExtensionCollection();

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Extension "com.example/feature" may only decorate spec-registry request methods, and \'acme/lookup\' is not one.');

        $collection->add(new StubExtension(identifier: 'com.example/feature'), requestDecorators: [
            'acme/lookup' => static fn(RequestHandlerInterface $handler): RequestHandlerInterface => $handler,
        ]);
    }

    public function testTwoExtensionsMergeTheirDeclarations(): void
    {
        $firstHandler = self::buildRequestHandler();
        $secondHandler = self::buildRequestHandler();
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::getMethod() => TestRequest::class],
            notifications: [TestNotification::getMethod() => TestNotification::class],
            requestHandlers: [TestRequest::getMethod() => $firstHandler],
            notificationHandlers: [TestNotification::getMethod() => self::buildNotificationHandler()],
        ));
        $collection->add(new StubExtension(
            identifier: 'com.example/other',
            requests: [TestClientRequest::getMethod() => TestClientRequest::class],
            notifications: [TestSecondNotification::getMethod() => TestSecondNotification::class],
            requestHandlers: [TestClientRequest::getMethod() => $secondHandler],
            notificationHandlers: [TestSecondNotification::getMethod() => self::buildNotificationHandler()],
        ));

        self::assertSame([
            'com.example/feature' => [],
            'com.example/other' => [],
        ], $collection->buildCapabilitySlot());
        self::assertSame([
            TestRequest::getMethod() => TestRequest::class,
            TestClientRequest::getMethod() => TestClientRequest::class,
        ], $collection->buildRequestClasses());
        self::assertSame([
            TestNotification::getMethod() => TestNotification::class,
            TestSecondNotification::getMethod() => TestSecondNotification::class,
        ], $collection->buildNotificationClasses());
        self::assertSame([
            TestRequest::getMethod() => $firstHandler,
            TestClientRequest::getMethod() => $secondHandler,
        ], $collection->buildRequestHandlers());
        self::assertCount(2, $collection->buildNotificationHandlers());
        self::assertSame(['com.example/feature', 'com.example/other'], array_keys($collection->getRequestHandlerGroups()));
    }

    public function testDeclarationOrderDoesNotAffectKeyPairing(): void
    {
        $this->expectNotToPerformAssertions();

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: [
                TestRequest::getMethod() => TestRequest::class,
                TestClientRequest::getMethod() => TestClientRequest::class,
            ],
            notifications: [
                TestSecondNotification::getMethod() => TestSecondNotification::class,
                TestNotification::getMethod() => TestNotification::class,
            ],
            requestHandlers: [
                TestRequest::getMethod() => self::buildRequestHandler(),
                TestClientRequest::getMethod() => self::buildRequestHandler(),
            ],
            notificationHandlers: [
                TestSecondNotification::getMethod() => self::buildNotificationHandler(),
                TestNotification::getMethod() => self::buildNotificationHandler(),
            ],
        ));
    }

    public function testAddRejectsAMalformedIdentifier(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Extension identifier must be "{vendor-prefix}/{name}" following the "_meta" key grammar with a mandatory prefix, \'tasks\' given.',
        );

        (new ExtensionCollection())->add(new StubExtension(identifier: 'tasks'));
    }

    public function testAddRejectsADuplicateIdentifier(): void
    {
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(identifier: 'com.example/feature'));

        $this->expectException(DuplicateExtensionException::class);
        $this->expectExceptionMessageIs('Extension "com.example/feature" is declared more than once.');

        $collection->add(new StubExtension(identifier: 'com.example/feature'));
    }

    public function testADuplicateIdentifierWithOutboundMethodsIsStillReportedAsADuplicate(): void
    {
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(identifier: 'com.example/feature'), outboundRequests: ['acme/lookup']);

        $this->expectException(DuplicateExtensionException::class);
        $this->expectExceptionMessageIs('Extension "com.example/feature" is declared more than once.');

        $collection->add(new StubExtension(identifier: 'com.example/feature'), outboundRequests: ['acme/lookup']);
    }

    public function testAddRejectsListShapedSettings(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Extension "com.example/feature" settings must be a string-keyed object.');

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            settings: ['fast', 'safe'], // @phpstan-ignore argument.type (drives the list into the runtime shape guard)
        ));
    }

    public function testAddRejectsARequestClassDeclaringADifferentMethod(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Request class "Nexus\Mcp\Tests\Fixtures\Core\TestClientRequest" must declare the method "acme/lookup" it is registered for, \'tests/test-client-request\' declared.',
        );

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: ['acme/lookup' => TestClientRequest::class],
            requestHandlers: ['acme/lookup' => self::buildRequestHandler()],
        ));
    }

    public function testAddRejectsANotificationClassDeclaringADifferentMethod(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Notification class "Nexus\Mcp\Tests\Fixtures\Core\TestNotification" must declare the method "acme/ping" it is registered for, \'tests/test-notification\' declared.',
        );

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            notifications: ['acme/ping' => TestNotification::class],
            notificationHandlers: ['acme/ping' => self::buildNotificationHandler()],
        ));
    }

    public function testAddRejectsARequestClassWithoutTheClientMarkerWhenRequired(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" request class "Nexus\Mcp\Tests\Fixtures\Core\TestRequest" must implement "Nexus\Mcp\Core\Schema\Request\ClientRequest" for the server to dispatch it.',
        );

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::getMethod() => TestRequest::class],
            requestHandlers: [TestRequest::getMethod() => self::buildRequestHandler()],
        ), requireClientRequests: true);
    }

    public function testAMarkerlessRequestClassIsAcceptedWhenNotRequired(): void
    {
        $this->expectNotToPerformAssertions();

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::getMethod() => TestRequest::class],
            requestHandlers: [TestRequest::getMethod() => self::buildRequestHandler()],
        ));
    }

    public function testAddRejectsARequestClassWithoutAHandler(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" must declare its request classes and request handlers under the same method keys.',
        );

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::getMethod() => TestRequest::class],
        ));
    }

    public function testAddRejectsARequestHandlerWithoutAClass(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" must declare its request classes and request handlers under the same method keys.',
        );

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            requestHandlers: [TestRequest::getMethod() => self::buildRequestHandler()],
        ));
    }

    public function testAddRejectsANotificationClassWithoutAHandler(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" must declare its notification classes and notification handlers under the same method keys.',
        );

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            notifications: [TestNotification::getMethod() => TestNotification::class],
        ));
    }

    public function testAddRejectsANotificationHandlerWithoutAClass(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" must declare its notification classes and notification handlers under the same method keys.',
        );

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            notificationHandlers: [TestNotification::getMethod() => self::buildNotificationHandler()],
        ));
    }

    public function testAddRejectsARequestMethodTheSpecOwns(): void
    {
        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" cannot claim the request method "tools/call" already owned by the MCP specification.',
        );

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: ['tools/call' => CallToolRequest::class],
            requestHandlers: ['tools/call' => self::buildRequestHandler()],
        ));
    }

    public function testAddRejectsANotificationMethodTheSpecOwns(): void
    {
        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" cannot claim the notification method "notifications/progress" already owned by the MCP specification.',
        );

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            notifications: ['notifications/progress' => ProgressNotification::class],
            notificationHandlers: ['notifications/progress' => self::buildNotificationHandler()],
        ));
    }

    public function testAddRejectsARequestMethodAnotherExtensionOwns(): void
    {
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::getMethod() => TestRequest::class],
            requestHandlers: [TestRequest::getMethod() => self::buildRequestHandler()],
        ));

        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Extension "com.example/other" cannot claim the request method "%s" already owned by extension "com.example/feature".',
            TestRequest::getMethod(),
        ));

        $collection->add(new StubExtension(
            identifier: 'com.example/other',
            requests: [TestRequest::getMethod() => TestRequest::class],
            requestHandlers: [TestRequest::getMethod() => self::buildRequestHandler()],
        ));
    }

    public function testAddRejectsANotificationMethodAnotherExtensionOwns(): void
    {
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(
            identifier: 'com.example/feature',
            notifications: [TestNotification::getMethod() => TestNotification::class],
            notificationHandlers: [TestNotification::getMethod() => self::buildNotificationHandler()],
        ));

        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Extension "com.example/other" cannot claim the notification method "%s" already owned by extension "com.example/feature".',
            TestNotification::getMethod(),
        ));

        $collection->add(new StubExtension(
            identifier: 'com.example/other',
            notifications: [TestNotification::getMethod() => TestNotification::class],
            notificationHandlers: [TestNotification::getMethod() => self::buildNotificationHandler()],
        ));
    }

    public function testAddRejectsARequestMethodABuilderHandlerClaimed(): void
    {
        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Extension "com.example/feature" cannot claim the request method "%s" already owned by a builder-registered handler.',
            TestRequest::getMethod(),
        ));

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::getMethod() => TestRequest::class],
            requestHandlers: [TestRequest::getMethod() => self::buildRequestHandler()],
        ), claimedRequests: [TestRequest::getMethod()]);
    }

    public function testAddRejectsANotificationMethodABuilderHandlerClaimed(): void
    {
        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Extension "com.example/feature" cannot claim the notification method "%s" already owned by a builder-registered handler.',
            TestNotification::getMethod(),
        ));

        (new ExtensionCollection())->add(new StubExtension(
            identifier: 'com.example/feature',
            notifications: [TestNotification::getMethod() => TestNotification::class],
            notificationHandlers: [TestNotification::getMethod() => self::buildNotificationHandler()],
        ), claimedNotifications: [TestNotification::getMethod()]);
    }

    public function testAddRejectsAnOutboundSpecMethod(): void
    {
        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/feature" cannot claim the request method "tools/call" already owned by the MCP specification.',
        );

        (new ExtensionCollection())->add(new StubExtension(identifier: 'com.example/feature'), outboundRequests: ['tools/call']);
    }

    public function testAddRejectsAnOutboundMethodAnotherExtensionOwns(): void
    {
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(identifier: 'com.example/feature'), outboundRequests: ['acme/lookup']);

        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(
            'Extension "com.example/other" cannot claim the request method "acme/lookup" already owned by extension "com.example/feature".',
        );

        $collection->add(new StubExtension(identifier: 'com.example/other'), outboundRequests: ['acme/lookup']);
    }

    public function testAssertNotOwnedThrowsForAnExtensionOwnedRequestMethod(): void
    {
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(
            identifier: 'com.example/feature',
            requests: [TestRequest::getMethod() => TestRequest::class],
            requestHandlers: [TestRequest::getMethod() => self::buildRequestHandler()],
        ));

        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'A builder-registered handler cannot claim the request method "%s" already owned by extension "com.example/feature".',
            TestRequest::getMethod(),
        ));

        $collection->assertNotOwned(TestRequest::getMethod());
    }

    public function testAssertNotOwnedThrowsForAnExtensionOwnedNotificationMethod(): void
    {
        $collection = new ExtensionCollection();
        $collection->add(new StubExtension(
            identifier: 'com.example/feature',
            notifications: [TestNotification::getMethod() => TestNotification::class],
            notificationHandlers: [TestNotification::getMethod() => self::buildNotificationHandler()],
        ));

        $this->expectException(ExtensionMethodCollisionException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'A builder-registered handler cannot claim the notification method "%s" already owned by extension "com.example/feature".',
            TestNotification::getMethod(),
        ));

        $collection->assertNotOwned(TestNotification::getMethod(), isNotification: true);
    }

    public function testAssertNotOwnedPassesAnUnownedMethod(): void
    {
        $this->expectNotToPerformAssertions();

        $collection = new ExtensionCollection();
        $collection->assertNotOwned('acme/unclaimed');
        $collection->assertNotOwned('acme/unclaimed', isNotification: true);
    }

    public function testARejectedExtensionLeavesNoOwnershipBehind(): void
    {
        $collection = new ExtensionCollection();

        try {
            $collection->add(new StubExtension(
                identifier: 'com.example/feature',
                requests: [
                    TestRequest::getMethod() => TestRequest::class,
                    'tools/call' => TestClientRequest::class,
                ],
                requestHandlers: [
                    TestRequest::getMethod() => self::buildRequestHandler(),
                    'tools/call' => self::buildRequestHandler(),
                ],
            ), outboundRequests: ['acme/lookup']);
            self::fail('The spec-owned method must be rejected.');
        } catch (ExpectationFailedException) {
            // Rejected as a whole: "tools/call" cannot be keyed to a class declaring another method.
        }

        self::assertNull($collection->findRequestOwner(TestRequest::getMethod()));
        self::assertNull($collection->buildCapabilitySlot());
        self::assertSame([], $collection->getOutboundOwners());
        self::assertSame([], $collection->getRequestHandlerGroups());
    }

    private static function buildRequestHandler(): ClosureRequestHandler
    {
        return new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult());
    }

    private static function buildNotificationHandler(): ClosureNotificationHandler
    {
        return new ClosureNotificationHandler(static function (): void {});
    }
}
