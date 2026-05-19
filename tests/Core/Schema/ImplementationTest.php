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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\BaseMetadata;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Implementation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Implementation::class)]
#[CoversClass(BaseMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ImplementationTest extends TestCase
{
    public function testMinimalConstructionExposesNameAndVersion(): void
    {
        $implementation = new Implementation('Nexus MCP', '1.0.0');

        self::assertSame('Nexus MCP', $implementation->name);
        self::assertSame('1.0.0', $implementation->version);
        self::assertNull($implementation->title);
        self::assertNull($implementation->description);
        self::assertNull($implementation->websiteUrl);
        self::assertNull($implementation->icons);
    }

    public function testFullConstructionExposesAllFields(): void
    {
        $icon = new Icon('https://example.com/icon.png');

        $implementation = new Implementation(
            'Nexus MCP',
            '1.0.0',
            'Nexus MCP Server',
            'A Model Context Protocol server.',
            'https://nexus.example.com',
            [$icon],
        );

        self::assertSame('Nexus MCP Server', $implementation->title);
        self::assertSame('A Model Context Protocol server.', $implementation->description);
        self::assertSame('https://nexus.example.com', $implementation->websiteUrl);
        self::assertSame([$icon], $implementation->icons);
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Implementation name must be a non-empty string.');

        new Implementation('', '1.0.0');
    }

    public function testEmptyVersionIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"version" must be a non-empty string.');

        new Implementation('Nexus MCP', '');
    }

    public function testEmptyTitleIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Implementation title must be a non-empty string or null.');

        new Implementation('Nexus MCP', '1.0.0', '');
    }

    public function testEmptyDescriptionIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"description" must be a non-empty string or null.');

        new Implementation('Nexus MCP', '1.0.0', null, '');
    }

    public function testEmptyWebsiteUrlIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"websiteUrl" must be a non-empty string or null.');

        new Implementation('Nexus MCP', '1.0.0', null, null, '');
    }

    public function testNonHttpWebsiteUrlIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"websiteUrl" must be an HTTP or HTTPS URL.');

        new Implementation('Nexus MCP', '1.0.0', null, null, 'ftp://example.com');
    }

    public function testNonIconInIconsIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);

        new Implementation('Nexus MCP', '1.0.0', null, null, null, ['not-an-icon']); // @phpstan-ignore argument.type
    }

    public function testToArrayMinimal(): void
    {
        $implementation = new Implementation('Nexus MCP', '1.0.0');

        self::assertSame(
            ['name' => 'Nexus MCP', 'version' => '1.0.0'],
            $implementation->toArray(),
        );
    }

    public function testToArrayFull(): void
    {
        $icon = new Icon('https://example.com/icon.png', 'image/png');

        $implementation = new Implementation(
            'Nexus MCP',
            '1.0.0',
            'Nexus MCP Server',
            'A Model Context Protocol server.',
            'https://nexus.example.com',
            [$icon],
        );

        self::assertSame(
            [
                'name' => 'Nexus MCP',
                'version' => '1.0.0',
                'title' => 'Nexus MCP Server',
                'description' => 'A Model Context Protocol server.',
                'websiteUrl' => 'https://nexus.example.com',
                'icons' => [['src' => 'https://example.com/icon.png', 'mimeType' => 'image/png']],
            ],
            $implementation->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $implementation = new Implementation('Nexus MCP', '1.0.0');

        self::assertSame($implementation->toArray(), $implementation->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $implementation = Implementation::fromArray(['name' => 'Nexus MCP', 'version' => '1.0.0']);

        self::assertSame('Nexus MCP', $implementation->name);
        self::assertSame('1.0.0', $implementation->version);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new Implementation(
            'Nexus MCP',
            '1.0.0',
            'Nexus MCP Server',
            'A Model Context Protocol server.',
            'https://nexus.example.com',
            [new Icon('https://example.com/icon.png', 'image/png')],
        );

        $rebuilt = Implementation::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayMissingNameIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('missing the required "name" key.');

        Implementation::fromArray(['version' => '1.0.0']);
    }

    public function testFromArrayMissingVersionIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('missing the required "version" key.');

        Implementation::fromArray(['name' => 'Nexus MCP']);
    }

    public function testFromArrayBadNameTypeIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"name" must be a string, int given.');

        Implementation::fromArray(['name' => 42, 'version' => '1.0.0']);
    }

    public function testFromArrayBadVersionTypeIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"version" must be a string, int given.');

        Implementation::fromArray(['name' => 'Nexus MCP', 'version' => 42]);
    }

    public function testFromArrayBadTitleTypeIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"title" must be a string or null, int given.');

        Implementation::fromArray(['name' => 'Nexus MCP', 'version' => '1.0.0', 'title' => 42]);
    }

    public function testFromArrayBadDescriptionTypeIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"description" must be a string or null, int given.');

        Implementation::fromArray(['name' => 'Nexus MCP', 'version' => '1.0.0', 'description' => 42]);
    }

    public function testFromArrayBadWebsiteUrlTypeIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"websiteUrl" must be a string or null, int given.');

        Implementation::fromArray(['name' => 'Nexus MCP', 'version' => '1.0.0', 'websiteUrl' => 42]);
    }

    public function testFromArrayBadIconsTypeIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"icons" must be a list, string given.');

        Implementation::fromArray(['name' => 'Nexus MCP', 'version' => '1.0.0', 'icons' => 'oops']);
    }

    public function testFromArrayBadIconEntryTypeIsRejected(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('each implementation "icons" must be an object, string given.');

        Implementation::fromArray(['name' => 'Nexus MCP', 'version' => '1.0.0', 'icons' => ['not-an-object']]);
    }

    public function testDisplayNameReturnsTitleWhenSet(): void
    {
        $implementation = new Implementation('nexus-mcp', '1.0.0', 'Nexus MCP Server');

        self::assertSame('Nexus MCP Server', $implementation->displayName());
    }

    public function testDisplayNameFallsBackToNameWhenTitleNull(): void
    {
        $implementation = new Implementation('nexus-mcp', '1.0.0');

        self::assertSame('nexus-mcp', $implementation->displayName());
    }
}
