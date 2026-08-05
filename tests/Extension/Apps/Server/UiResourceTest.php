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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Extension\Apps\Schema\UiResourceCsp;
use Nexus\Mcp\Extension\Apps\Schema\UiResourceMeta;
use Nexus\Mcp\Extension\Apps\Server\UiResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UiResource::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class UiResourceTest extends TestCase
{
    public function testComposesAResourceBoundToTheUiProfile(): void
    {
        $uiMeta = new UiResourceMeta(
            csp: new UiResourceCsp(connectDomains: ['https://api.openweathermap.org']),
            prefersBorder: true,
        );
        $uiResource = new UiResource(
            name: 'dashboard',
            uri: 'ui://weather-server/dashboard',
            title: 'Weather dashboard',
            description: 'Renders the forecast.',
            uiMeta: $uiMeta,
        );

        self::assertSame($uiMeta, $uiResource->uiMeta);

        $resource = $uiResource->resource;

        self::assertSame('dashboard', $resource->name);
        self::assertSame('ui://weather-server/dashboard', $resource->uri);
        self::assertSame('Weather dashboard', $resource->title);
        self::assertSame('Renders the forecast.', $resource->description);
        self::assertSame('text/html;profile=mcp-app', $resource->mimeType);
        self::assertSame([
            'ui' => [
                'csp' => ['connectDomains' => ['https://api.openweathermap.org']],
                'prefersBorder' => true,
            ],
        ], $resource->meta->extras);
    }

    public function testOmitsTheMetaSlotWithoutUiMeta(): void
    {
        $resource = new UiResource(name: 'panel', uri: 'ui://demo/panel')->resource;

        self::assertSame([], $resource->meta->extras);
        self::assertSame('text/html;profile=mcp-app', $resource->mimeType);
    }

    public function testOmitsTheMetaSlotForAnEmptyUiMeta(): void
    {
        $resource = new UiResource(name: 'panel', uri: 'ui://demo/panel', uiMeta: new UiResourceMeta())->resource;

        self::assertSame([], $resource->meta->extras);
    }

    public function testPassesTheOptionalResourceFieldsThrough(): void
    {
        $annotations = new Annotations(priority: 0.5);
        $resource = new UiResource(
            name: 'panel',
            uri: 'ui://demo/panel',
            annotations: $annotations,
            size: 2_048,
        )->resource;

        self::assertSame($annotations, $resource->annotations);
        self::assertSame(2_048, $resource->size);
    }

    public function testRejectsAUriOutsideTheUiScheme(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('UI resource "uri" must start with "ui://".');

        new UiResource(name: 'panel', uri: 'https://example.com/panel');
    }
}
