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

namespace Nexus\Mcp\Tests\Server\Attribute;

use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Server\Attribute\AsResourceTemplate;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AsResourceTemplate::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class AsResourceTemplateTest extends AbstractMcpTestCase
{
    public function testDefaultsToNullExceptUriTemplate(): void
    {
        $template = new AsResourceTemplate(uriTemplate: 'file:///{path}');

        self::assertSame('file:///{path}', $template->uriTemplate);
        self::assertNull($template->name);
        self::assertNull($template->title);
        self::assertNull($template->description);
        self::assertNull($template->mimeType);
        self::assertNull($template->annotations);
        self::assertNull($template->icons);
        self::assertNull($template->meta);
    }

    public function testStoresAllValues(): void
    {
        $annotations = new Annotations(priority: 0.5);
        $icon = new Icon(src: 'https://example.test/icon.svg');

        $template = new AsResourceTemplate(
            uriTemplate: 'file:///{path}',
            name: 'files',
            title: 'Files',
            description: 'Files by path.',
            mimeType: 'text/plain',
            annotations: $annotations,
            icons: [$icon],
            meta: ['vendor' => 'acme'],
        );

        self::assertSame('file:///{path}', $template->uriTemplate);
        self::assertSame('files', $template->name);
        self::assertSame('Files', $template->title);
        self::assertSame('Files by path.', $template->description);
        self::assertSame('text/plain', $template->mimeType);
        self::assertSame($annotations, $template->annotations);
        self::assertSame([$icon], $template->icons);
        self::assertSame(['vendor' => 'acme'], $template->meta);
    }
}
