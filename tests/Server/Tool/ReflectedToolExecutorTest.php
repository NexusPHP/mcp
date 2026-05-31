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

namespace Nexus\Mcp\Tests\Server\Tool;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ReflectedToolExecutor;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReflectedToolExecutor::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReflectedToolExecutorTest extends TestCase
{
    public function testReturnsCallToolResultUnchanged(): void
    {
        $result = self::execute('toolResult');

        self::assertTrue($result->isError);
        self::assertSame('done', self::firstText($result));
    }

    public function testWrapsStringReturnAsTextContent(): void
    {
        $result = self::execute('requiredString', ['name' => 'Ada']);

        self::assertSame('Hello, Ada', self::firstText($result));
        self::assertNull($result->structuredContent);
        self::assertNull($result->isError);
    }

    public function testWrapsArrayReturnAsStructuredContent(): void
    {
        $result = self::execute('toolStructured');

        self::assertSame([], $result->content);
        self::assertSame(['ok' => true, 'count' => 2], $result->structuredContent);
    }

    public function testWrapsSingleContentBlock(): void
    {
        $result = self::execute('toolSingleBlock');

        self::assertCount(1, $result->content);
        self::assertInstanceOf(ImageContent::class, $result->content[0]);
        self::assertNull($result->structuredContent);
    }

    public function testWrapsListOfContentBlocks(): void
    {
        $result = self::execute('toolBlockList');

        self::assertCount(5, $result->content);
        self::assertNull($result->structuredContent);
    }

    public function testTreatsMapOfBlocksAsStructuredContent(): void
    {
        $result = self::execute('toolMapOfBlocks');

        self::assertSame([], $result->content);
        self::assertSame(['main'], array_keys($result->structuredContent ?? []));
    }

    public function testTreatsEmptyArrayAsStructuredContent(): void
    {
        $result = self::execute('toolEmpty');

        self::assertSame([], $result->content);
        self::assertSame([], $result->structuredContent);
    }

    public function testRejectsNonContentBlockList(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessage(ReflectedHandlers::class.'::toolList() must return a '.CallToolResult::class.', a string, content blocks, or an array, array given.');

        self::execute('toolList');
    }

    public function testRejectsIntegerKeyedArray(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessage(ReflectedHandlers::class.'::toolIntKeyedArray() must return a '.CallToolResult::class.', a string, content blocks, or an array, array given.');

        self::execute('toolIntKeyedArray');
    }

    public function testThrowsOnUnsupportedReturn(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessage(ReflectedHandlers::class.'::toolUnsupported() must return a '.CallToolResult::class.', a string, content blocks, or an array, int given.');

        self::execute('toolUnsupported');
    }

    public function testBindsArgumentsHydratesEnumAndWrapsString(): void
    {
        $result = self::execute('backedString', ['color' => 'a']);

        self::assertSame('a', self::firstText($result));
    }

    public function testInjectsContextWithNullArguments(): void
    {
        $result = self::execute('contextOnly', null);

        self::assertSame('session-1', self::firstText($result));
    }

    /**
     * @param null|array<string, mixed> $arguments
     */
    private static function execute(string $method, ?array $arguments = ['ignored' => true]): CallToolResult
    {
        $executor = new ReflectedToolExecutor(new ReflectedHandlers(), new \ReflectionMethod(ReflectedHandlers::class, $method));

        return $executor->execute($arguments, self::makeContext());
    }

    private static function firstText(CallToolResult $result): string
    {
        $block = $result->content[0] ?? null;

        if (! $block instanceof TextContent) {
            self::fail('Expected a TextContent block.');
        }

        return $block->text;
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(7),
            new NullCancellation(),
            new RequestMetaObject(),
            'session-1',
            new RecordingSender(),
        );
    }
}
