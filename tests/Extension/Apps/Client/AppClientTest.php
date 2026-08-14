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

namespace Nexus\Mcp\Tests\Extension\Apps\Client;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Extension\Apps\Client\AppClient;
use Nexus\Mcp\Extension\Apps\Client\AppsClientExtension;
use Nexus\Mcp\Extension\Apps\Schema\Enum\ToolVisibility;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(AppClient::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class AppClientTest extends AbstractMcpTestCase
{
    public function testResolvesTheNestedToolMeta(): void
    {
        [$app] = self::buildAppClient();
        $tool = self::buildTool(['ui' => ['resourceUri' => 'ui://demo/panel', 'visibility' => ['app']]]);

        $meta = $app->resolveToolMeta($tool);

        self::assertNotNull($meta);
        self::assertSame('ui://demo/panel', $meta->resourceUri);
        self::assertSame([ToolVisibility::App], $meta->visibility);
    }

    public function testFallsBackToTheDeprecatedFlatKey(): void
    {
        [$app] = self::buildAppClient();
        $tool = self::buildTool(['ui/resourceUri' => 'ui://demo/panel']);

        $meta = $app->resolveToolMeta($tool);

        self::assertNotNull($meta);
        self::assertSame('ui://demo/panel', $meta->resourceUri);
        self::assertNull($meta->visibility);
    }

    public function testTheNestedKeyWinsOverTheDeprecatedOne(): void
    {
        [$app] = self::buildAppClient();
        $tool = self::buildTool([
            'ui' => ['resourceUri' => 'ui://demo/nested'],
            'ui/resourceUri' => 'ui://demo/flat',
        ]);

        self::assertSame('ui://demo/nested', $app->resolveToolMeta($tool)?->resourceUri);
    }

    public function testResolvesNullForAToolWithoutUiMeta(): void
    {
        [$app] = self::buildAppClient();

        self::assertNull($app->resolveToolMeta(self::buildTool([])));
    }

    public function testRejectsANestedToolMetaThatIsNotAnObject(): void
    {
        [$app] = self::buildAppClient();
        $tool = self::buildTool(['ui' => 'ui://demo/panel']);

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('tool "_meta.ui" must be an array, string given.');

        $app->resolveToolMeta($tool);
    }

    public function testRejectsADeprecatedKeyThatIsNotAString(): void
    {
        [$app] = self::buildAppClient();
        $tool = self::buildTool(['ui/resourceUri' => 123]);

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('tool "_meta" key "ui/resourceUri" must be a non-empty string, int given.');

        $app->resolveToolMeta($tool);
    }

    public function testResolvesTheResourceMetaOffADescriptor(): void
    {
        [$app] = self::buildAppClient();
        $resource = new Resource(
            name: 'panel',
            uri: 'ui://demo/panel',
            mimeType: 'text/html;profile=mcp-app',
            meta: new PayloadMetaObject(extras: ['ui' => ['prefersBorder' => true]]),
        );

        $meta = $app->resolveResourceMeta($resource);

        self::assertNotNull($meta);
        self::assertTrue($meta->prefersBorder);
    }

    public function testResolvesTheResourceMetaOffAContentItem(): void
    {
        [$app] = self::buildAppClient();
        $contents = new TextResourceContents(
            uri: 'ui://demo/panel',
            text: '<!DOCTYPE html><html></html>',
            mimeType: 'text/html;profile=mcp-app',
            meta: new PayloadMetaObject(extras: ['ui' => ['domain' => 'abc.example.com']]),
        );

        $meta = $app->resolveResourceMeta($contents);

        self::assertNotNull($meta);
        self::assertSame('abc.example.com', $meta->domain);
    }

    public function testResolvesNullForAResourceWithoutUiMeta(): void
    {
        [$app] = self::buildAppClient();

        self::assertNull($app->resolveResourceMeta(new Resource(name: 'panel', uri: 'ui://demo/panel')));
    }

    public function testRejectsAResourceMetaThatIsNotAnObject(): void
    {
        [$app] = self::buildAppClient();
        $resource = new Resource(
            name: 'panel',
            uri: 'ui://demo/panel',
            meta: new PayloadMetaObject(extras: ['ui' => [true]]),
        );

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource "_meta.ui" must be a string-keyed object.');

        $app->resolveResourceMeta($resource);
    }

    public function testFindsTheToolsLinkingAUiResource(): void
    {
        [$app] = self::buildAppClient();
        $linked = self::buildTool(['ui' => ['resourceUri' => 'ui://demo/panel']], name: 'linked');
        $unlinked = self::buildTool([], name: 'unlinked');
        $emptyMeta = self::buildTool(['ui' => []], name: 'empty_meta');
        $legacy = self::buildTool(['ui/resourceUri' => 'ui://demo/legacy'], name: 'legacy');
        $result = new ListToolsResult(tools: [$linked, $unlinked, $emptyMeta, $legacy], ttlMs: 0, cacheScope: CacheScope::Private);

        $found = $app->findAppTools($result);

        self::assertCount(2, $found);
        self::assertSame($linked, $found[0]->tool);
        self::assertSame('ui://demo/panel', $found[0]->uiMeta->resourceUri);
        self::assertSame($legacy, $found[1]->tool);
        self::assertSame('ui://demo/legacy', $found[1]->uiMeta->resourceUri);
    }

    public function testSkipsAToolWithMalformedMetaWhenFiltering(): void
    {
        [$app] = self::buildAppClient();
        $malformed = self::buildTool(['ui' => 'oops'], name: 'malformed');
        $linked = self::buildTool(['ui' => ['resourceUri' => 'ui://demo/panel']], name: 'linked');
        $result = new ListToolsResult(tools: [$malformed, $linked], ttlMs: 0, cacheScope: CacheScope::Private);

        $found = $app->findAppTools($result);

        self::assertCount(1, $found);
        self::assertSame($linked, $found[0]->tool);
    }

    public function testMergesTheFlatKeyIntoAUriLessNestedObject(): void
    {
        [$app] = self::buildAppClient();
        $tool = self::buildTool([
            'ui' => ['visibility' => ['model']],
            'ui/resourceUri' => 'ui://demo/flat',
        ]);

        $meta = $app->resolveToolMeta($tool);

        self::assertNotNull($meta);
        self::assertSame('ui://demo/flat', $meta->resourceUri);
        self::assertSame([ToolVisibility::Model], $meta->visibility);
    }

    public function testReadsAConformingAppResource(): void
    {
        [$app, $transport] = self::buildAppClient();

        $read = async(static fn(): InputRequiredResult|ReadResourceResult => $app->readAppResource('ui://demo/panel'));
        $transport->nextSend()->await();

        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);
        self::assertSame('resources/read', $sent::getMethod());

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => [
                'contents' => [[
                    'uri' => 'ui://demo/panel',
                    'mimeType' => 'text/html;profile=mcp-app',
                    'text' => '<!DOCTYPE html><html></html>',
                ]],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ]);

        $result = $read->await();

        self::assertInstanceOf(ReadResourceResult::class, $result);
        self::assertCount(1, $result->contents);
        $transport->close();
    }

    public function testRejectsAReadThatReturnedNoContents(): void
    {
        [$app, $transport] = self::buildAppClient();

        $read = async(static fn(): InputRequiredResult|ReadResourceResult => $app->readAppResource('ui://demo/panel'));
        $transport->nextSend()->await();

        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => [
                'contents' => [],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ]);

        try {
            $read->await();
            self::fail('Expected the empty read to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('UI resource "ui://demo/panel" returned no contents.', $exception->getMessage());
        }

        $transport->close();
    }

    public function testRejectsAReadWhoseContentsAreNotTheUiProfile(): void
    {
        [$app, $transport] = self::buildAppClient();

        $read = async(static fn(): InputRequiredResult|ReadResourceResult => $app->readAppResource('ui://demo/panel'));
        $transport->nextSend()->await();

        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => [
                'contents' => [[
                    'uri' => 'ui://demo/panel',
                    'mimeType' => 'text/html',
                    'text' => '<!DOCTYPE html><html></html>',
                ]],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ]);

        try {
            $read->await();
            self::fail('Expected the non-conforming contents to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'UI resource "ui://demo/panel" returned contents of mime type "text/html", expected one of "text/html;profile=mcp-app".',
                $exception->getMessage(),
            );
        }

        $transport->close();
    }

    public function testAcceptsContentsMatchingAConfiguredMimeType(): void
    {
        [$app, $transport] = self::buildAppClient(mimeTypes: ['text/html;profile=mcp-app', 'text/html']);

        $read = async(static fn(): InputRequiredResult|ReadResourceResult => $app->readAppResource('ui://demo/panel'));
        $transport->nextSend()->await();

        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => [
                'contents' => [[
                    'uri' => 'ui://demo/panel',
                    'mimeType' => 'text/html',
                    'text' => '<!DOCTYPE html><html></html>',
                ]],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ]);

        self::assertInstanceOf(ReadResourceResult::class, $read->await());
        $transport->close();
    }

    public function testPassesAnInputRequiredResultThroughUnverified(): void
    {
        [$app, $transport] = self::buildAppClient();

        $read = async(static fn(): InputRequiredResult|ReadResourceResult => $app->readAppResource('ui://demo/panel'));
        $transport->nextSend()->await();

        self::assertArrayHasKey(0, $transport->sent);
        $sent = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sent->id->id,
            'result' => ['resultType' => 'input_required', 'requestState' => 'state-1'],
        ]);

        $result = $read->await();

        self::assertInstanceOf(InputRequiredResult::class, $result);
        self::assertSame('state-1', $result->requestState);
        $transport->close();
    }

    public function testRejectsAReadOutsideTheUiScheme(): void
    {
        [$app] = self::buildAppClient();

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('UI resource "uri" must start with "ui://".');

        $app->readAppResource('https://example.com/panel');
    }

    public function testRejectsANonListMimeTypeArray(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"mimeTypes" must be a list, array given.');

        // @phpstan-ignore argument.type
        new AppClient(self::buildBareClient(), mimeTypes: ['html' => 'text/html;profile=mcp-app']);
    }

    public function testRejectsAnEmptyMimeTypeList(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"mimeTypes" must not be empty.');

        new AppClient(self::buildBareClient(), mimeTypes: []);
    }

    public function testRejectsAMalformedMimeType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "mimeTypes" must be a non-empty string, string given.');

        // @phpstan-ignore argument.type
        new AppClient(self::buildBareClient(), mimeTypes: ['']);
    }

    /**
     * @param array<string, mixed> $extras
     * @param non-empty-string     $name
     */
    private static function buildTool(array $extras, string $name = 'demo_tool'): Tool
    {
        return new Tool(
            name: $name,
            inputSchema: ['type' => 'object'],
            meta: new PayloadMetaObject(extras: $extras),
        );
    }

    /**
     * @param null|list<non-empty-string> $mimeTypes
     *
     * @return array{AppClient, RecordingTransport}
     */
    private static function buildAppClient(?array $mimeTypes = null): array
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestTimeout(0.5)
            ->enableExtension(null === $mimeTypes ? new AppsClientExtension() : new AppsClientExtension(mimeTypes: $mimeTypes))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $app = null === $mimeTypes ? new AppClient($client) : new AppClient($client, mimeTypes: $mimeTypes);

        return [$app, $transport];
    }

    private static function buildBareClient(): Client
    {
        return (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
    }
}
