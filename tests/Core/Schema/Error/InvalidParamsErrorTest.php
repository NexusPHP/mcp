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
use Nexus\Mcp\Core\Schema\Error\InvalidParamsError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InvalidParamsError::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InvalidParamsErrorTest extends TestCase
{
    public function testInvalidParamsErrorHasCorrectDefaultMessage(): void
    {
        self::assertSame('Invalid params', InvalidParamsError::fromArray([])->message);
    }

    public function testInvalidParamsErrorCanOverrideMessage(): void
    {
        $error = new InvalidParamsError('Parameters do not match method signature');
        self::assertSame('Parameters do not match method signature', $error->message);
    }

    public function testInvalidParamsErrorHasCorrectCode(): void
    {
        $error = new InvalidParamsError(InvalidParamsError::DEFAULT_MESSAGE);
        self::assertSame(ProtocolErrorCode::InvalidParams->value, $error->code);
        self::assertSame(-32602, $error->code);
    }

    public function testInvalidParamsErrorCanIncludeData(): void
    {
        $data = ['expected' => 'object', 'received' => 'string'];
        $error = new InvalidParamsError('Invalid params', $data);
        self::assertSame($data, $error->data);
    }

    public function testInvalidParamsErrorFromArrayWithAllFields(): void
    {
        $data = [
            'message' => 'Parameters are invalid',
            'data' => ['param' => 'uri', 'issue' => 'required'],
        ];

        $error = InvalidParamsError::fromArray($data);

        self::assertSame('Parameters are invalid', $error->message);
        self::assertSame(-32602, $error->code);
        self::assertSame(['param' => 'uri', 'issue' => 'required'], $error->data);
    }

    public function testInvalidParamsErrorFromArrayUsesDefaultMessage(): void
    {
        $data = [];
        $error = InvalidParamsError::fromArray($data);

        self::assertSame('Invalid params', $error->message);
    }

    public function testInvalidParamsErrorFromArrayWithoutData(): void
    {
        $data = ['message' => 'Custom message'];
        $error = InvalidParamsError::fromArray($data);

        self::assertNull($error->data);
    }

    public function testInvalidParamsErrorToArray(): void
    {
        $error = new InvalidParamsError('Missing arguments', ['missing' => ['arg1']]);
        $array = $error->toArray();

        self::assertSame([
            'code' => -32602,
            'message' => 'Missing arguments',
            'data' => ['missing' => ['arg1']],
        ], $array);
    }

    public function testInvalidParamsErrorJsonSerialize(): void
    {
        $error = new InvalidParamsError('Missing arguments');
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32602,
            'message' => 'Missing arguments',
        ], $result);
    }

    public function testInvalidParamsErrorJsonSerializeWithData(): void
    {
        $data = ['param' => 'timeout'];
        $error = new InvalidParamsError('Missing arguments', $data);
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32602,
            'message' => 'Missing arguments',
            'data' => $data,
        ], $result);
    }
}
