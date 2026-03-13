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

namespace Nexus\Mcp\Tests\Core\Schema\Error;

use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ParseError::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ParseErrorTest extends TestCase
{
    public function testParseErrorHasCorrectDefaultMessage(): void
    {
        $error = new ParseError();

        self::assertSame('Parse error', $error->message);
    }

    public function testParseErrorCanOverrideMessage(): void
    {
        $error = new ParseError('Custom parse error message');

        self::assertSame('Custom parse error message', $error->message);
    }

    public function testParseErrorHasCorrectCode(): void
    {
        $error = new ParseError();

        self::assertSame(ProtocolErrorCode::ParseError->value, $error->code);
        self::assertSame(-32700, $error->code);
    }

    public function testParseErrorCanIncludeData(): void
    {
        $data = ['line' => 1, 'column' => 5];
        $error = new ParseError('Parse failed', $data);

        self::assertSame($data, $error->data);
    }

    public function testParseErrorFromArrayWithAllFields(): void
    {
        $data = [
            'message' => 'Custom parse error',
            'data' => ['details' => 'test'],
        ];
        $error = ParseError::fromArray($data);

        self::assertSame('Custom parse error', $error->message);
        self::assertSame(-32700, $error->code);
        self::assertSame(['details' => 'test'], $error->data);
    }

    public function testParseErrorFromArrayWithoutData(): void
    {
        $data = ['message' => 'Custom message'];
        $error = ParseError::fromArray($data);

        self::assertNull($error->data);
    }

    public function testParseErrorToArray(): void
    {
        $error = new ParseError('Test parse error', ['metadata' => 'value']);
        $array = $error->toArray();

        self::assertSame([
            'code' => -32700,
            'message' => 'Test parse error',
            'data' => ['metadata' => 'value'],
        ], $array);
    }

    public function testParseErrorJsonSerialize(): void
    {
        $error = new ParseError('Test parse error');
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32700,
            'message' => 'Test parse error',
        ], $result);
    }

    public function testParseErrorJsonSerializeWithData(): void
    {
        $data = ['context' => 'test'];
        $error = new ParseError('Test parse error', $data);
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32700,
            'message' => 'Test parse error',
            'data' => $data,
        ], $result);
    }
}
