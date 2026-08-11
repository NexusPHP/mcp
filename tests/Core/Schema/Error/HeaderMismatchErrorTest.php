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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\HeaderMismatchError;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(HeaderMismatchError::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class HeaderMismatchErrorTest extends AbstractMcpTestCase
{
    public function testHasCodeAndDefaultMessage(): void
    {
        $error = new HeaderMismatchError();

        self::assertSame(-32_020, $error->code);
        self::assertSame(ProtocolErrorCode::HeaderMismatch->value, $error->code);
        self::assertSame('Header mismatch', $error->message);
        self::assertNull($error->data);
    }

    public function testCanOverrideMessage(): void
    {
        $error = new HeaderMismatchError(message: 'The "Mcp-Method" header does not match the request body.');

        self::assertSame('The "Mcp-Method" header does not match the request body.', $error->message);
    }

    public function testToArrayOmitsData(): void
    {
        $error = new HeaderMismatchError(message: 'header mismatch');

        self::assertSame(['code' => -32_020, 'message' => 'header mismatch'], $error->toArray());
    }

    public function testCanIncludeData(): void
    {
        $data = ['header' => 'Mcp-Method'];
        $error = new HeaderMismatchError(message: 'header mismatch', data: $data);

        self::assertSame($data, $error->data);
    }

    public function testToArrayCarriesData(): void
    {
        $error = new HeaderMismatchError(message: 'header mismatch', data: ['header' => 'Mcp-Method']);

        self::assertSame([
            'code' => -32_020,
            'message' => 'header mismatch',
            'data' => ['header' => 'Mcp-Method'],
        ], $error->toArray());
    }

    public function testJsonSerializeCarriesData(): void
    {
        $data = ['header' => 'Mcp-Method'];
        $error = new HeaderMismatchError(message: 'header mismatch', data: $data);

        self::assertSame([
            'code' => -32_020,
            'message' => 'header mismatch',
            'data' => $data,
        ], $error->jsonSerialize());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $error = new HeaderMismatchError(message: 'header mismatch');

        self::assertSame($error->toArray(), $error->jsonSerialize());
    }

    public function testFromArrayUsesDefaultMessage(): void
    {
        self::assertSame('Header mismatch', HeaderMismatchError::fromArray([])->message);
    }

    public function testFromArrayReadsMessage(): void
    {
        self::assertSame('boom', HeaderMismatchError::fromArray(['message' => 'boom'])->message);
    }

    public function testFromArrayReadsData(): void
    {
        $error = HeaderMismatchError::fromArray(['message' => 'boom', 'data' => ['header' => 'Mcp-Method']]);

        self::assertSame(['header' => 'Mcp-Method'], $error->data);
    }

    public function testFromArrayWithoutDataReadsNull(): void
    {
        self::assertNull(HeaderMismatchError::fromArray(['message' => 'boom'])->data);
    }

    public function testFromArrayRejectsNonStringMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('error "message" must be a non-empty string, int given.');

        HeaderMismatchError::fromArray(['message' => 1]);
    }
}
