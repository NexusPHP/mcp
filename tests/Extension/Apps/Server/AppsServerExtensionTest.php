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

namespace Nexus\Mcp\Tests\Extension\Apps\Server;

use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Extension\Apps\Apps;
use Nexus\Mcp\Extension\Apps\Server\AppsServerExtension;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AppsServerExtension::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class AppsServerExtensionTest extends AbstractMcpTestCase
{
    public function testDeclaresTheOfficialIdentifierWithEmptySettings(): void
    {
        $extension = new AppsServerExtension();

        self::assertSame('io.modelcontextprotocol/ui', $extension->getIdentifier());
        self::assertSame([], $extension->getSettings());
        self::assertSame([], $extension->getRequests());
        self::assertSame([], $extension->getNotifications());
        self::assertSame([], $extension->getRequestHandlers());
        self::assertSame([], $extension->getNotificationHandlers());
    }

    public function testAcceptsWellFormedRegistrations(): void
    {
        $this->expectNotToPerformAssertions();

        (new AppsServerExtension())->validateRegistrations(
            [
                new Tool(name: 'plain', inputSchema: ['type' => 'object']),
                new Tool(name: 'linked', inputSchema: ['type' => 'object'], meta: new PayloadMetaObject(
                    extras: ['ui' => ['resourceUri' => 'ui://demo/view']],
                )),
            ],
            [
                new Resource(name: 'notes', uri: 'file:///notes.txt', mimeType: 'text/plain'),
                new Resource(name: 'unnamed', uri: 'file:///unnamed'),
                new Resource(name: 'view', uri: 'ui://demo/view', mimeType: Apps::MIME_TYPE, meta: new PayloadMetaObject(
                    extras: ['ui' => ['prefersBorder' => true]],
                )),
            ],
            [
                new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                new ResourceTemplate(name: 'views', uriTemplate: 'ui://demo/{view}', mimeType: Apps::MIME_TYPE),
            ],
        );
    }

    /**
     * @param list<Tool>             $tools
     * @param list<Resource>         $resources
     * @param list<ResourceTemplate> $templates
     */
    #[DataProvider('provideRefusesAMalformedRegistrationCases')]
    public function testRefusesAMalformedRegistration(
        array $tools,
        array $resources,
        array $templates,
        string $message,
    ): void {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs($message);

        (new AppsServerExtension())->validateRegistrations($tools, $resources, $templates);
    }

    /**
     * @return iterable<string, array{list<Tool>, list<Resource>, list<ResourceTemplate>, string}>
     */
    public static function provideRefusesAMalformedRegistrationCases(): iterable
    {
        $pairing = 'must pair the "ui://" scheme with the "text/html;profile=mcp-app" mime type, or use neither.';

        yield 'a ui:// resource without the mime type' => [
            [],
            [new Resource(name: 'view', uri: 'ui://demo/view', mimeType: 'text/html')],
            [],
            \sprintf('Resource "ui://demo/view" %s', $pairing),
        ];

        yield 'the mime type outside ui://' => [
            [],
            [new Resource(name: 'view', uri: 'https://demo.example/view', mimeType: Apps::MIME_TYPE)],
            [],
            \sprintf('Resource "https://demo.example/view" %s', $pairing),
        ];

        yield 'a ui:// template without the mime type' => [
            [],
            [],
            [new ResourceTemplate(name: 'views', uriTemplate: 'ui://demo/{view}')],
            \sprintf('Resource template "ui://demo/{view}" %s', $pairing),
        ];

        yield 'a tool linking a non-ui:// view' => [
            [new Tool(name: 'weather', inputSchema: ['type' => 'object'], meta: new PayloadMetaObject(
                extras: ['ui' => ['resourceUri' => 'https://demo.example/view']],
            ))],
            [],
            [],
            'Tool "weather" carries an invalid "_meta.ui": "_meta.ui.resourceUri" must start with "ui://".',
        ];

        yield 'a tool whose _meta.ui is not an object' => [
            [new Tool(name: 'weather', inputSchema: ['type' => 'object'], meta: new PayloadMetaObject(
                extras: ['ui' => 'ui://demo/view'],
            ))],
            [],
            [],
            'Tool "weather" carries an invalid "_meta.ui": "_meta.ui" must be a string-keyed object.',
        ];

        yield 'a ui:// resource with malformed metadata' => [
            [],
            [new Resource(name: 'view', uri: 'ui://demo/view', mimeType: Apps::MIME_TYPE, meta: new PayloadMetaObject(
                extras: ['ui' => ['domain' => '']],
            ))],
            [],
            'Resource "ui://demo/view" carries an invalid "_meta.ui": "_meta.ui.domain" must be a non-empty string or null.',
        ];
    }

    public function testBuildRefusesAMalformedRegistration(): void
    {
        $builder = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(new AppsServerExtension())
            ->addResource(
                new Resource(name: 'view', uri: 'ui://demo/view', mimeType: 'text/html'),
                static fn(): ReadResourceResult => new ReadResourceResult(
                    contents: [],
                    ttlMs: 0,
                    cacheScope: CacheScope::Private,
                ),
            )
        ;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            'Resource "ui://demo/view" must pair the "ui://" scheme with the "text/html;profile=mcp-app" mime type, or use neither.',
        );

        $builder->build();
    }

    public function testBuildIgnoresAResourceThatACustomStoreOverrides(): void
    {
        $this->expectNotToPerformAssertions();

        (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(new AppsServerExtension())
            ->addResource(
                new Resource(name: 'view', uri: 'ui://demo/view', mimeType: 'text/html'),
                static fn(): ReadResourceResult => new ReadResourceResult(
                    contents: [],
                    ttlMs: 0,
                    cacheScope: CacheScope::Private,
                ),
            )
            ->setResourceStore(new ResourceStore())
            ->build()
        ;
    }

    public function testBuildsWithNoMethodsOfItsOwn(): void
    {
        $this->expectNotToPerformAssertions();

        (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(new AppsServerExtension())
            ->build()
        ;
    }
}
