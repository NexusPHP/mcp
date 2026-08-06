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
use Nexus\Mcp\Core\Schema\Error\MethodNotFoundError;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(MethodNotFoundError::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MethodNotFoundErrorTest extends AbstractMcpTestCase
{
    public function testMethodNotFoundErrorHasCorrectDefaultMessage(): void
    {
        self::assertSame('Method not found', MethodNotFoundError::fromArray([])->message);
    }

    public function testMethodNotFoundErrorCanOverrideMessage(): void
    {
        $error = new MethodNotFoundError(message: 'Unknown method: something');
        self::assertSame('Unknown method: something', $error->message);
    }

    public function testMethodNotFoundErrorHasCorrectCode(): void
    {
        $error = new MethodNotFoundError(message: MethodNotFoundError::DEFAULT_MESSAGE);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $error->code);
        self::assertSame(-32_601, $error->code);
    }

    public function testMethodNotFoundErrorCanIncludeData(): void
    {
        $data = ['method' => 'nonexistent_method'];
        $error = new MethodNotFoundError(message: 'Method not found', data: $data);
        self::assertSame($data, $error->data);
    }

    public function testMethodNotFoundErrorFromArrayWithAllFields(): void
    {
        $data = [
            'message' => 'The method does not exist',
            'data' => ['requested' => 'unknown_rpc'],
        ];

        $error = MethodNotFoundError::fromArray($data);

        self::assertSame('The method does not exist', $error->message);
        self::assertSame(-32_601, $error->code);
        self::assertSame(['requested' => 'unknown_rpc'], $error->data);
    }

    public function testMethodNotFoundErrorFromArrayUsesDefaultMessage(): void
    {
        $data = [];
        $error = MethodNotFoundError::fromArray($data);

        self::assertSame('Method not found', $error->message);
    }

    public function testMethodNotFoundErrorFromArrayWithoutData(): void
    {
        $data = ['message' => 'Custom message'];
        $error = MethodNotFoundError::fromArray($data);

        self::assertNull($error->data);
    }

    public function testMethodNotFoundErrorToArray(): void
    {
        $error = new MethodNotFoundError(message: 'No such method', data: ['available' => ['tools/list']]);
        $array = $error->toArray();

        self::assertSame([
            'code' => -32_601,
            'message' => 'No such method',
            'data' => ['available' => ['tools/list']],
        ], $array);
    }

    public function testMethodNotFoundErrorJsonSerialize(): void
    {
        $error = new MethodNotFoundError(message: 'No such method');
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32_601,
            'message' => 'No such method',
        ], $result);
    }

    public function testMethodNotFoundErrorJsonSerializeWithData(): void
    {
        $data = ['method' => 'xyz'];
        $error = new MethodNotFoundError(message: 'No such method', data: $data);
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32_601,
            'message' => 'No such method',
            'data' => $data,
        ], $result);
    }
}
