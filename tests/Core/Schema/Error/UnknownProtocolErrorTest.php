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
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\UnknownProtocolError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnknownProtocolError::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class UnknownProtocolErrorTest extends TestCase
{
    public function testRawIntCodeIsPreserved(): void
    {
        $error = new UnknownProtocolError(code: -32099, message: 'Upstream rejected');

        self::assertSame(-32099, $error->code);
        self::assertSame('Upstream rejected', $error->message);
        self::assertNull($error->data);
    }

    public function testRejectsEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('error "message" must be a non-empty string.');

        new UnknownProtocolError(code: -32099, message: '');
    }

    public function testRejectsCodeThatMapsToKnownProtocolErrorCase(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('code -32603 maps to a known protocol error code.');

        new UnknownProtocolError(code: -32603, message: 'Internal error');
    }

    public function testToArrayEmitsRawCode(): void
    {
        $error = new UnknownProtocolError(code: -32099, message: 'Upstream rejected', data: ['trace' => 'abc']);

        self::assertSame(
            ['code' => -32099, 'message' => 'Upstream rejected', 'data' => ['trace' => 'abc']],
            $error->toArray(),
        );
    }

    public function testToArrayOmitsEmptyData(): void
    {
        $error = new UnknownProtocolError(code: -32099, message: 'Upstream rejected', data: []);

        self::assertSame(
            ['code' => -32099, 'message' => 'Upstream rejected'],
            $error->toArray(),
        );
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new UnknownProtocolError(code: -32099, message: 'Upstream rejected', data: ['trace' => 'abc']);
        $rebuilt = UnknownProtocolError::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"error" is missing the required "message" key.');

        UnknownProtocolError::fromArray(['code' => 42]);
    }

    public function testFromArrayRejectsNonStringMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"error.message" must be a string, int given.');

        UnknownProtocolError::fromArray(['code' => 42, 'message' => 1]);
    }

    public function testFromArrayRejectsMissingCode(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"error" is missing the required "code" key.');

        UnknownProtocolError::fromArray([]);
    }

    public function testFromArrayRejectsNonIntCode(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"error.code" must be an integer, string given.');

        UnknownProtocolError::fromArray(['code' => 'oops']);
    }
}
