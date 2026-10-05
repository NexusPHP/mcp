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

namespace Nexus\Mcp\Tests\Extension\Skills\Server;

use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Extension\Skills\Server\SkillFile;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SkillFile::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillFileTest extends AbstractMcpTestCase
{
    private const string URI = 'skill://refunds/SKILL.md';

    public function testConstruction(): void
    {
        $file = new SkillFile(self::URI, '# Refunds', 'text/markdown');

        self::assertSame(self::URI, $file->uri);
        self::assertSame('# Refunds', $file->contents);
        self::assertSame('text/markdown', $file->mimeType);
        self::assertNull((new SkillFile(self::URI, ''))->mimeType);
    }

    public function testAnEmptyUriIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('skill file "uri" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new SkillFile('', 'x');
    }

    public function testAnEmptyMimeTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('skill file "mimeType" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new SkillFile(self::URI, 'x', '');
    }

    #[DataProvider('provideTextIsServedAsTextCases')]
    public function testTextIsServedAsText(string $contents): void
    {
        $resource = (new SkillFile(self::URI, $contents, 'text/markdown'))->toResourceContents();

        self::assertInstanceOf(TextResourceContents::class, $resource);
        self::assertSame(self::URI, $resource->uri);
        self::assertSame($contents, $resource->text);
        self::assertSame('text/markdown', $resource->mimeType);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTextIsServedAsTextCases(): iterable
    {
        yield 'ascii' => ['# Refunds'];

        yield 'multibyte' => ['Rückerstattung ✓'];

        yield 'empty' => [''];
    }

    #[DataProvider('provideBytesThatAreNotTextAreServedAsABlobCases')]
    public function testBytesThatAreNotTextAreServedAsABlob(string $contents): void
    {
        $resource = (new SkillFile(self::URI, $contents, 'application/octet-stream'))->toResourceContents();

        self::assertInstanceOf(BlobResourceContents::class, $resource);
        self::assertSame(self::URI, $resource->uri);
        self::assertSame(base64_encode($contents), $resource->blob);
        self::assertSame('application/octet-stream', $resource->mimeType);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBytesThatAreNotTextAreServedAsABlobCases(): iterable
    {
        yield 'invalid UTF-8' => ["\xff\xfe"];

        yield 'valid UTF-8 holding a NUL' => ["ab\0cd"];
    }
}
