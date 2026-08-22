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

use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\MissingRequiredClientCapabilityError;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(MissingRequiredClientCapabilityError::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MissingRequiredClientCapabilityErrorTest extends AbstractMcpTestCase
{
    public function testHasCodeAndDefaultMessageAndCapabilities(): void
    {
        $capabilities = new ClientCapabilities(elicitation: []);
        $error = new MissingRequiredClientCapabilityError(requiredCapabilities: $capabilities);

        self::assertSame(-32_021, $error->code);
        self::assertSame(ProtocolErrorCode::MissingRequiredClientCapability->value, $error->code);
        self::assertSame('Missing required client capability', $error->message);
        self::assertSame($capabilities, $error->requiredCapabilities);
        self::assertSame(['requiredCapabilities' => ['elicitation' => []]], $error->data);
    }

    public function testCanOverrideMessage(): void
    {
        $error = new MissingRequiredClientCapabilityError(
            requiredCapabilities: new ClientCapabilities(),
            message: 'need elicitation',
        );

        self::assertSame('need elicitation', $error->message);
    }

    public function testToArrayEncodesCapabilitiesAsList(): void
    {
        $error = new MissingRequiredClientCapabilityError(
            requiredCapabilities: new ClientCapabilities(elicitation: []),
            message: 'need elicitation',
        );

        self::assertSame([
            'code' => -32_021,
            'message' => 'need elicitation',
            'data' => ['requiredCapabilities' => ['elicitation' => []]],
        ], $error->toArray());
    }

    public function testJsonSerializeSubstitutesEmptyCapabilityObject(): void
    {
        $error = new MissingRequiredClientCapabilityError(
            requiredCapabilities: new ClientCapabilities(elicitation: []),
            message: 'need elicitation',
        );

        self::assertSame(
            '{"code":-32021,"message":"need elicitation","data":{"requiredCapabilities":{"elicitation":{}}}}',
            json_encode($error, \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testFromArrayRoundTripsCapabilities(): void
    {
        $error = MissingRequiredClientCapabilityError::fromArray([
            'code' => -32_021,
            'message' => 'need elicitation',
            'data' => ['requiredCapabilities' => ['elicitation' => []]],
        ]);

        self::assertSame('need elicitation', $error->message);
        self::assertSame(['elicitation' => []], $error->requiredCapabilities->toArray());
    }

    public function testFromArrayUsesDefaultMessage(): void
    {
        $error = MissingRequiredClientCapabilityError::fromArray([
            'data' => ['requiredCapabilities' => []],
        ]);

        self::assertSame('Missing required client capability', $error->message);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        MissingRequiredClientCapabilityError::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'message not a string' => [
            ['message' => 1, 'data' => ['requiredCapabilities' => []]],
            'error "message" must be a non-empty string, int given.',
        ];

        yield 'missing data' => [
            [],
            'error "data" must be an object, null given.',
        ];

        yield 'data not an object' => [
            ['data' => 'x'],
            'error "data" must be an object, string given.',
        ];

        yield 'data is list-keyed' => [
            ['data' => ['a', 'b']],
            'error "data" must be a string-keyed object.',
        ];

        yield 'missing requiredCapabilities' => [
            ['data' => []],
            'error "data" is missing the required "requiredCapabilities" key.',
        ];

        yield 'requiredCapabilities not an object' => [
            ['data' => ['requiredCapabilities' => 'x']],
            'error "data.requiredCapabilities" must be an object, string given.',
        ];

        yield 'requiredCapabilities is list-keyed' => [
            ['data' => ['requiredCapabilities' => ['a', 'b']]],
            'error "data.requiredCapabilities" must be a string-keyed object.',
        ];
    }
}
