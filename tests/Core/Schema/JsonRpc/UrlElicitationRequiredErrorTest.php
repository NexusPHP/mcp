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

namespace Nexus\Mcp\Tests\Core\Schema\JsonRpc;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\UrlElicitationRequiredErrorPayload;
use Nexus\Mcp\Core\Schema\JsonRpc\UrlElicitationRequiredError;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestUrlParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UrlElicitationRequiredError::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class UrlElicitationRequiredErrorTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $error = new UrlElicitationRequiredError(
            new RequestId('r-1'),
            new UrlElicitationRequiredErrorPayload('Authorization required.', ['elicitations' => []]),
            [new ElicitRequestUrlParams('e-1', 'Sign in', 'url', 'https://example.com')],
        );

        self::assertNotNull($error->id);
        self::assertSame('r-1', $error->id->id);
        self::assertCount(1, $error->elicitations);
    }

    public function testToArrayWithNullId(): void
    {
        $error = new UrlElicitationRequiredError(
            null,
            new UrlElicitationRequiredErrorPayload('Authorization required.', ['elicitations' => []]),
            [],
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32042,
                    'message' => 'Authorization required.',
                    'data' => ['elicitations' => []],
                ],
            ],
            $error->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $error = new UrlElicitationRequiredError(
            new RequestId(42),
            new UrlElicitationRequiredErrorPayload('Authorization required.', [
                'elicitations' => [
                    [
                        'elicitationId' => 'e-1',
                        'message' => 'Sign in',
                        'mode' => 'url',
                        'url' => 'https://example.com',
                    ],
                ],
            ]),
            [new ElicitRequestUrlParams('e-1', 'Sign in', 'url', 'https://example.com')],
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 42,
                'error' => [
                    'code' => -32042,
                    'message' => 'Authorization required.',
                    'data' => [
                        'elicitations' => [
                            [
                                'elicitationId' => 'e-1',
                                'message' => 'Sign in',
                                'mode' => 'url',
                                'url' => 'https://example.com',
                            ],
                        ],
                    ],
                ],
            ],
            $error->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $error = new UrlElicitationRequiredError(
            new RequestId('r-1'),
            new UrlElicitationRequiredErrorPayload('msg', ['elicitations' => []]),
            [],
        );

        self::assertSame($error->toArray(), $error->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $elicitation = new ElicitRequestUrlParams('e-1', 'Sign in', 'url', 'https://example.com');
        $original = new UrlElicitationRequiredError(
            new RequestId('r-1'),
            new UrlElicitationRequiredErrorPayload('Authorization required.', [
                'elicitations' => [$elicitation->toArray()],
            ]),
            [$elicitation],
        );

        $rebuilt = UrlElicitationRequiredError::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsWrongErrorCode(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UrlElicitationRequiredError inner error code must be -32042, -32600 given.');

        new UrlElicitationRequiredError(
            new RequestId('r-1'),
            new InvalidRequestError('oops'),
            [],
        );
    }

    #[DataProvider('provideConstructorRejectsNonListElicitationsCases')]
    public function testConstructorRejectsNonListElicitations(mixed $elicitations): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UrlElicitationRequiredError elicitations must be a list, got non-list array.');

        new UrlElicitationRequiredError(
            null,
            new UrlElicitationRequiredErrorPayload('msg', ['elicitations' => []]),
            $elicitations, // @phpstan-ignore argument.type
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideConstructorRejectsNonListElicitationsCases(): iterable
    {
        yield 'string-keyed entry' => [['k' => new ElicitRequestUrlParams('e-1', 'm', 'url', 'https://example.com')]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        UrlElicitationRequiredError::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'id not an array key' => [
            ['id' => 1.5, 'error' => []],
            'UrlElicitationRequiredError "id" must be int, string, or null; float given.',
        ];

        yield 'missing error' => [
            [],
            'UrlElicitationRequiredError data missing "error".',
        ];

        yield 'error not an object' => [
            ['error' => 'oops'],
            'UrlElicitationRequiredError "error" must be an object, string given.',
        ];

        yield 'error list-keyed' => [
            ['error' => ['x']],
            'UrlElicitationRequiredError "error" must be a string-keyed object.',
        ];

        yield 'error missing data' => [
            ['error' => ['code' => -32042, 'message' => 'm']],
            'UrlElicitationRequiredError error data missing "data".',
        ];

        yield 'error data not an object' => [
            ['error' => ['code' => -32042, 'message' => 'm', 'data' => 'oops']],
            'UrlElicitationRequiredError error "data" must be an object, string given.',
        ];

        yield 'error data list-keyed' => [
            ['error' => ['code' => -32042, 'message' => 'm', 'data' => ['x']]],
            'UrlElicitationRequiredError error "data" must be a string-keyed object.',
        ];

        yield 'error data missing elicitations' => [
            ['error' => ['code' => -32042, 'message' => 'm', 'data' => []]],
            'UrlElicitationRequiredError error data missing "elicitations".',
        ];

        yield 'elicitations not a list' => [
            ['error' => ['code' => -32042, 'message' => 'm', 'data' => ['elicitations' => ['k' => []]]]],
            'UrlElicitationRequiredError "elicitations" must be a list, got non-list array.',
        ];

        yield 'elicitations entry not an object' => [
            ['error' => ['code' => -32042, 'message' => 'm', 'data' => ['elicitations' => ['oops']]]],
            'UrlElicitationRequiredError elicitations entry must be an object, string given.',
        ];

        yield 'elicitations entry list-keyed' => [
            ['error' => ['code' => -32042, 'message' => 'm', 'data' => ['elicitations' => [['x']]]]],
            'UrlElicitationRequiredError elicitations entry must be a string-keyed object.',
        ];

        yield 'error message not a string' => [
            ['error' => ['code' => -32042, 'data' => ['elicitations' => []]]],
            'UrlElicitationRequiredError error "message" must be a string, null given.',
        ];
    }
}
