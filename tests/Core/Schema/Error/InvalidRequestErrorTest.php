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
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(InvalidRequestError::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InvalidRequestErrorTest extends AbstractMcpTestCase
{
    public function testInvalidRequestErrorHasCorrectDefaultMessage(): void
    {
        self::assertSame('Invalid request', InvalidRequestError::fromArray([])->message);
    }

    public function testInvalidRequestErrorCanOverrideMessage(): void
    {
        $error = new InvalidRequestError(message: 'Request is malformed');
        self::assertSame('Request is malformed', $error->message);
    }

    public function testInvalidRequestErrorHasCorrectCode(): void
    {
        $error = new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $error->code);
        self::assertSame(-32_600, $error->code);
    }

    public function testInvalidRequestErrorCanIncludeData(): void
    {
        $data = ['field' => 'method', 'reason' => 'missing'];
        $error = new InvalidRequestError(message: 'Invalid request', data: $data);
        self::assertSame($data, $error->data);
    }

    public function testInvalidRequestErrorFromArrayWithAllFields(): void
    {
        $data = [
            'message' => 'The request is invalid',
            'data' => ['validation' => 'errors'],
        ];

        $error = InvalidRequestError::fromArray($data);

        self::assertSame('The request is invalid', $error->message);
        self::assertSame(-32_600, $error->code);
        self::assertSame(['validation' => 'errors'], $error->data);
    }

    public function testInvalidRequestErrorFromArrayUsesDefaultMessage(): void
    {
        $data = [];
        $error = InvalidRequestError::fromArray($data);

        self::assertSame('Invalid request', $error->message);
    }

    public function testInvalidRequestErrorFromArrayWithoutData(): void
    {
        $data = ['message' => 'Custom message'];
        $error = InvalidRequestError::fromArray($data);

        self::assertNull($error->data);
    }

    public function testInvalidRequestErrorToArray(): void
    {
        $error = new InvalidRequestError(message: 'Bad request', data: ['why' => 'malformed']);
        $array = $error->toArray();

        self::assertSame([
            'code' => -32_600,
            'message' => 'Bad request',
            'data' => ['why' => 'malformed'],
        ], $array);
    }

    public function testInvalidRequestErrorJsonSerialize(): void
    {
        $error = new InvalidRequestError(message: 'Bad request');
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32_600,
            'message' => 'Bad request',
        ], $result);
    }

    public function testInvalidRequestErrorJsonSerializeWithData(): void
    {
        $data = ['field' => 'id'];
        $error = new InvalidRequestError(message: 'Bad request', data: $data);
        $result = $error->jsonSerialize();

        self::assertSame([
            'code' => -32_600,
            'message' => 'Bad request',
            'data' => $data,
        ], $result);
    }
}
