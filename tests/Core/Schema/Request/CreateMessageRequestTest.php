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

namespace Nexus\Mcp\Tests\Core\Schema\Request;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\Request\CreateMessageRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\CreateMessageRequestParams;
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CreateMessageRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CreateMessageRequestTest extends TestCase
{
    public function testMethodIsSamplingCreateMessage(): void
    {
        self::assertSame('sampling/createMessage', CreateMessageRequest::method());
    }

    public function testToArray(): void
    {
        $req = new CreateMessageRequest(
            new RequestId('r-1'),
            new CreateMessageRequestParams(
                maxTokens: 100,
                messages: [new SamplingMessage(Role::User, new TextContent('hi'))],
            ),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'r-1',
                'method' => 'sampling/createMessage',
                'params' => [
                    'maxTokens' => 100,
                    'messages' => [
                        ['role' => 'user', 'content' => ['text' => 'hi', 'type' => 'text']],
                    ],
                ],
            ],
            $req->toArray(),
        );
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new CreateMessageRequest(
            new RequestId('r-1'),
            new CreateMessageRequestParams(
                maxTokens: 100,
                messages: [new SamplingMessage(Role::User, new TextContent('hi'))],
            ),
        );

        $rebuilt = CreateMessageRequest::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('missing the required "id" key.');

        CreateMessageRequest::fromArray(['method' => 'sampling/createMessage', 'params' => ['maxTokens' => 1, 'messages' => []]]);
    }

    public function testFromArrayRejectsNonScalarId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"id" must be int or string, array given.');

        CreateMessageRequest::fromArray(['id' => [], 'method' => 'sampling/createMessage', 'params' => ['maxTokens' => 1, 'messages' => []]]);
    }

    public function testFromArrayRejectsMissingParams(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('missing the required "params" key.');

        CreateMessageRequest::fromArray(['id' => 'r-1', 'method' => 'sampling/createMessage']);
    }

    public function testFromArrayRejectsNonObjectParams(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"params" must be an object, string given.');

        CreateMessageRequest::fromArray(['id' => 'r-1', 'method' => 'sampling/createMessage', 'params' => 'oops']);
    }
}
