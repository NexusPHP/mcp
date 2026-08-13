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

namespace Nexus\Mcp\Tests\Server\Exception;

use Nexus\Mcp\Core\Dispatch\ResponseSender;
use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error\MissingRequiredClientCapabilityError;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Server\Exception\MissingRequiredClientCapabilityException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(MissingRequiredClientCapabilityException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class MissingRequiredClientCapabilityExceptionTest extends AbstractMcpTestCase
{
    public function testNamesTheWithheldCapabilitiesInTheMessage(): void
    {
        $e = new MissingRequiredClientCapabilityException(new ClientCapabilities(elicitation: []));

        self::assertSame('This request requires client capabilities the client did not declare: elicitation.', $e->getMessage());
        self::assertNull($e->requestId);
    }

    public function testNamesEveryWithheldCapabilityIncludingOnesOutsideTheNamedSet(): void
    {
        $e = new MissingRequiredClientCapabilityException(
            new ClientCapabilities(elicitation: [], extras: ['sampling' => []]),
        );

        self::assertSame(
            'This request requires client capabilities the client did not declare: elicitation, sampling.',
            $e->getMessage(),
        );
    }

    public function testNamesTheNestedMembersOfAMapValuedSlot(): void
    {
        $e = new MissingRequiredClientCapabilityException(new ClientCapabilities(extensions: [
            'com.example/feature' => [],
            'com.example/other' => ['mode' => 'fast'],
        ]));

        self::assertSame(
            'This request requires client capabilities the client did not declare: extensions.com.example/feature, extensions.com.example/other.',
            $e->getMessage(),
        );
    }

    public function testAMapValuedSlotDoesNotStopTheRendering(): void
    {
        $e = new MissingRequiredClientCapabilityException(new ClientCapabilities(
            extensions: ['com.example/feature' => []],
            extras: ['sampling' => []],
        ));

        self::assertSame(
            'This request requires client capabilities the client did not declare: extensions.com.example/feature, sampling.',
            $e->getMessage(),
        );
    }

    public function testAListValuedSlotIsNamedPlainly(): void
    {
        $e = new MissingRequiredClientCapabilityException(
            new ClientCapabilities(extras: ['sampling' => ['tools', 'context']]),
        );

        self::assertSame(
            'This request requires client capabilities the client did not declare: sampling.',
            $e->getMessage(),
        );
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $capabilities = new ClientCapabilities(elicitation: []);
        $e = new MissingRequiredClientCapabilityException($capabilities, new RequestId(id: 42), $previous);

        self::assertSame($capabilities, $e->requiredCapabilities);
        self::assertSame(42, $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testReportsTheMissingCapabilityErrorCode(): void
    {
        self::assertSame(
            ProtocolErrorCode::MissingRequiredClientCapability,
            MissingRequiredClientCapabilityException::getErrorCode(),
        );
    }

    public function testTranslatesToTheTypedErrorCarryingTheRequiredCapabilities(): void
    {
        $exception = new MissingRequiredClientCapabilityException(new ClientCapabilities(elicitation: []));

        $error = ResponseSender::buildErrorResponse($exception, new RequestId(id: 7))->error;

        self::assertInstanceOf(MissingRequiredClientCapabilityError::class, $error);

        self::assertSame(['elicitation' => []], $error->requiredCapabilities->toArray());
    }

    public function testTheRequiredCapabilitiesEncodeAsAnObject(): void
    {
        $exception = new MissingRequiredClientCapabilityException(new ClientCapabilities(elicitation: []));

        $response = ResponseSender::buildErrorResponse($exception, new RequestId(id: 7));

        self::assertStringContainsString('"requiredCapabilities":{"elicitation":{}}', json_encode($response, \JSON_THROW_ON_ERROR));
    }
}
