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

namespace Nexus\Mcp\Tests\Extension\Apps\Client\Exception;

use Nexus\Mcp\Extension\Apps\Client\Exception\InvalidUiResourceContentsException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(InvalidUiResourceContentsException::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class InvalidUiResourceContentsExceptionTest extends AbstractMcpTestCase
{
    public function testNamesTheUriAndBothSides(): void
    {
        $exception = new InvalidUiResourceContentsException('ui://demo/panel', 'text/plain', ['text/html;profile=mcp-app', 'text/html']);

        self::assertSame(
            'UI resource "ui://demo/panel" returned contents of mime type "text/plain", expected one of "text/html;profile=mcp-app", "text/html".',
            $exception->getMessage(),
        );
    }

    public function testRendersAMissingMimeTypeAsEmpty(): void
    {
        self::assertSame(
            'UI resource "ui://demo/panel" returned contents of mime type "", expected one of "text/html;profile=mcp-app".',
            (new InvalidUiResourceContentsException('ui://demo/panel', null, ['text/html;profile=mcp-app']))->getMessage(),
        );
    }
}
