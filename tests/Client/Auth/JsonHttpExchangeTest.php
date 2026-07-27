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

namespace Nexus\Mcp\Tests\Client\Auth;

use Amp\Http\Client\Request;
use Nexus\Mcp\Client\Auth\JsonHttpExchange;
use Nexus\Mcp\Client\Exception\MalformedAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\RedirectRefusedException;
use Nexus\Mcp\Core\Exception\ResponseTooLargeException;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(JsonHttpExchange::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class JsonHttpExchangeTest extends TestCase
{
    private const string URL = 'https://auth.example.com/token';

    public function testItAsksForJsonAndReturnsTheStatusWithThePayload(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(['issuer' => 'https://auth.example.com'], 201);

        [$status, $payload] = new JsonHttpExchange($http)->send(new Request(self::URL));

        self::assertSame(201, $status);
        self::assertSame('{"issuer":"https:\/\/auth.example.com"}', $payload);
        self::assertSame('application/json', $http->readRequest()->getHeader('Accept'));
    }

    public function testTheTimeoutBoundsBothTheTransferAndTheStall(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson([]);

        new JsonHttpExchange($http, 2.5)->send(new Request(self::URL));

        self::assertSame(2.5, $http->readRequest()->getTransferTimeout());
        self::assertSame(2.5, $http->readRequest()->getInactivityTimeout());
    }

    public function testTheDefaultTimeoutIsTenSeconds(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson([]);

        new JsonHttpExchange($http)->send(new Request(self::URL));

        self::assertSame(10.0, $http->readRequest()->getTransferTimeout());
    }

    public function testAnAnswerFromAnotherUrlIsRefused(): void
    {
        $http = new RecordingHttpClient()->willAnswerFrom('http://127.0.0.1:6379/token');

        $this->expectException(RedirectRefusedException::class);
        $this->expectExceptionMessageIs('The request to "https://auth.example.com/token" was answered from "http://127.0.0.1:6379/token" after a redirect. Credentials are never carried across one.');

        new JsonHttpExchange($http)->send(new Request(self::URL));
    }

    public function testAnOversizedAnswerIsRefused(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(str_repeat('x', JsonHttpExchange::MAX_RESPONSE_BYTES + 1));

        $this->expectException(ResponseTooLargeException::class);
        $this->expectExceptionMessageIs('The response exceeded the 65536 byte limit the client accepts.');

        new JsonHttpExchange($http)->send(new Request(self::URL));
    }

    public function testAnAnswerAtTheByteCapIsRead(): void
    {
        $payload = str_pad('{"a":"', JsonHttpExchange::MAX_RESPONSE_BYTES - 2, 'x').'"}';
        $http = new RecordingHttpClient()->willAnswerJson($payload);

        self::assertSame($payload, new JsonHttpExchange($http)->send(new Request(self::URL))[1]);
    }

    public function testEveryMemberOfADecodedObjectIsReturned(): void
    {
        self::assertSame(
            ['issuer' => 'https://auth.example.com', 'token_endpoint' => self::URL, 'scopes_supported' => ['files:read']],
            JsonHttpExchange::decode(
                '{"issuer":"https://auth.example.com","token_endpoint":"https://auth.example.com/token","scopes_supported":["files:read"]}',
                'token endpoint',
            ),
        );
    }

    #[DataProvider('provideAPayloadThatIsNotAJsonObjectIsRefusedCases')]
    public function testAPayloadThatIsNotAJsonObjectIsRefused(string $payload): void
    {
        $this->expectException(MalformedAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The token endpoint answered with a payload that is not a JSON object.');

        JsonHttpExchange::decode($payload, 'token endpoint');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAPayloadThatIsNotAJsonObjectIsRefusedCases(): iterable
    {
        yield 'an HTML error page is not JSON' => ['<html><body>Bad Request</body></html>'];

        yield 'an empty body is not JSON' => [''];

        yield 'a JSON string is not an object' => ['"invalid_grant"'];

        yield 'a JSON array is not an object' => ['["invalid_grant"]'];
    }

    public function testANonJsonPayloadKeepsTheDecodingFailureAsItsCause(): void
    {
        try {
            JsonHttpExchange::decode('not json at all', 'token endpoint');
            self::fail('The payload should have been refused.');
        } catch (MalformedAuthorizationResponseException $e) {
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }
}
