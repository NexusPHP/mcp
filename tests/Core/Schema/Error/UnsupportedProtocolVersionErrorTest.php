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
use Nexus\Mcp\Core\Schema\Error\UnsupportedProtocolVersionError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnsupportedProtocolVersionError::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class UnsupportedProtocolVersionErrorTest extends TestCase
{
    public function testHasCodeAndDefaultMessageAndTypedData(): void
    {
        $error = new UnsupportedProtocolVersionError(requested: '2099-01-01', supported: ['DRAFT-2026-v1', '2025-11-25']);

        self::assertSame(-32022, $error->code);
        self::assertSame(ProtocolErrorCode::UnsupportedProtocolVersion->value, $error->code);
        self::assertSame('Unsupported protocol version', $error->message);
        self::assertSame('2099-01-01', $error->requested);
        self::assertSame(['DRAFT-2026-v1', '2025-11-25'], $error->supported);
        self::assertSame(['supported' => ['DRAFT-2026-v1', '2025-11-25'], 'requested' => '2099-01-01'], $error->data);
    }

    public function testCanOverrideMessage(): void
    {
        $error = new UnsupportedProtocolVersionError(requested: 'x', supported: [], message: 'bad version');

        self::assertSame('bad version', $error->message);
    }

    public function testToArray(): void
    {
        $error = new UnsupportedProtocolVersionError(
            requested: '2099-01-01',
            supported: ['DRAFT-2026-v1', '2025-11-25'],
            message: 'bad version',
        );

        self::assertSame([
            'code' => -32022,
            'message' => 'bad version',
            'data' => ['supported' => ['DRAFT-2026-v1', '2025-11-25'], 'requested' => '2099-01-01'],
        ], $error->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $error = new UnsupportedProtocolVersionError(requested: '2099-01-01', supported: ['DRAFT-2026-v1']);

        self::assertSame($error->toArray(), $error->jsonSerialize());
    }

    public function testFromArrayRoundTripsAllFields(): void
    {
        $error = UnsupportedProtocolVersionError::fromArray([
            'code' => -32022,
            'message' => 'bad version',
            'data' => ['supported' => ['DRAFT-2026-v1'], 'requested' => '2099-01-01'],
        ]);

        self::assertSame('bad version', $error->message);
        self::assertSame('2099-01-01', $error->requested);
        self::assertSame(['DRAFT-2026-v1'], $error->supported);
    }

    public function testFromArrayUsesDefaultMessage(): void
    {
        $error = UnsupportedProtocolVersionError::fromArray([
            'data' => ['supported' => ['DRAFT-2026-v1'], 'requested' => '2099-01-01'],
        ]);

        self::assertSame('Unsupported protocol version', $error->message);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        UnsupportedProtocolVersionError::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'message not a string' => [
            ['message' => 1, 'data' => ['supported' => ['DRAFT-2026-v1'], 'requested' => '2099-01-01']],
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

        yield 'missing requested' => [
            ['data' => ['supported' => ['DRAFT-2026-v1']]],
            'error "data" is missing the required "requested" key.',
        ];

        yield 'requested not a string' => [
            ['data' => ['requested' => 1, 'supported' => ['DRAFT-2026-v1']]],
            'error "data.requested" must be a string, int given.',
        ];

        yield 'missing supported' => [
            ['data' => ['requested' => '2099-01-01']],
            'error "data" is missing the required "supported" key.',
        ];

        yield 'supported not a list' => [
            ['data' => ['requested' => '2099-01-01', 'supported' => ['k' => 'v']]],
            'error "data.supported" must be a list of strings, array given.',
        ];

        yield 'supported element not a string' => [
            ['data' => ['requested' => '2099-01-01', 'supported' => [1]]],
            'each error "data.supported" must be a string, int given.',
        ];
    }
}
