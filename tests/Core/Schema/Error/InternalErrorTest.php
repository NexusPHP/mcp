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
use Nexus\Mcp\Core\Schema\Error\InternalError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InternalError::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InternalErrorTest extends TestCase
{
    public function testInternalErrorHasCorrectDefaultMessage(): void
    {
        $error = new InternalError();
        self::assertSame('Internal error', $error->message);
    }

    public function testInternalErrorCanOverrideMessage(): void
    {
        $error = new InternalError('Something went wrong internally');
        self::assertSame('Something went wrong internally', $error->message);
    }

    public function testInternalErrorHasCorrectCode(): void
    {
        $error = new InternalError();
        self::assertSame(ProtocolErrorCode::InternalError->value, $error->code);
        self::assertSame(-32603, $error->code);
    }

    public function testInternalErrorCanIncludeData(): void
    {
        $data = ['exception' => \RuntimeException::class, 'line' => 42];
        $error = new InternalError('Internal error', $data);
        self::assertSame($data, $error->data);
    }

    public function testInternalErrorFromArrayWithAllFields(): void
    {
        $data = [
            'message' => 'Server encountered an error',
            'data' => ['trace_id' => 'uuid123'],
        ];
        $error = InternalError::fromArray($data);

        self::assertSame('Server encountered an error', $error->message);
        self::assertSame(-32603, $error->code);
        self::assertSame(['trace_id' => 'uuid123'], $error->data);
    }

    public function testInternalErrorFromArrayUsesDefaultMessage(): void
    {
        $data = [];
        $error = InternalError::fromArray($data);

        self::assertSame('Internal error', $error->message);
    }

    public function testInternalErrorFromArrayWithoutData(): void
    {
        $data = ['message' => 'Custom message'];
        $error = InternalError::fromArray($data);

        self::assertNull($error->data);
    }

    public function testInternalErrorToArray(): void
    {
        $error = new InternalError('Unexpected error', ['error_id' => '12345']);
        $array = $error->toArray();

        self::assertSame([
            'code' => -32603,
            'message' => 'Unexpected error',
            'data' => ['error_id' => '12345'],
        ], $array);
    }

    public function testInternalErrorJsonSerialize(): void
    {
        $error = new InternalError('Unexpected error');
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32603,
            'message' => 'Unexpected error',
        ], $result);
    }

    public function testInternalErrorJsonSerializeWithData(): void
    {
        $data = ['trace_id' => 'abc123'];
        $error = new InternalError('Unexpected error', $data);
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32603,
            'message' => 'Unexpected error',
            'data' => $data,
        ], $result);
    }
}
