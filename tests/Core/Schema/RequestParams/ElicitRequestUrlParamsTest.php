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

namespace Nexus\Mcp\Tests\Core\Schema\RequestParams;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestUrlParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ElicitRequestUrlParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitRequestUrlParamsTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $params = new ElicitRequestUrlParams(
            message: 'Sign in',
            mode: 'url',
            url: 'https://auth.example.com',
        );

        self::assertSame('Sign in', $params->message);
        self::assertSame('url', $params->mode);
        self::assertSame('https://auth.example.com', $params->url);
    }

    public function testToArrayMinimal(): void
    {
        $params = new ElicitRequestUrlParams(
            message: 'Sign in',
            mode: 'url',
            url: 'https://auth.example.com',
        );

        self::assertSame(
            [
                'message' => 'Sign in',
                'mode' => 'url',
                'url' => 'https://auth.example.com',
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new ElicitRequestUrlParams(
            message: 'Sign in',
            mode: 'url',
            url: 'https://auth.example.com',
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitRequestUrlParams(
            message: 'Sign in',
            mode: 'url',
            url: 'https://auth.example.com',
        );

        $rebuilt = ElicitRequestUrlParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsEmptyMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.message" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ElicitRequestUrlParams(message: '', mode: 'url', url: 'https://example.com');
    }

    public function testConstructorRejectsEmptyUrl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.url" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ElicitRequestUrlParams(message: 'm', mode: 'url', url: '');
    }

    public function testConstructorRejectsInvalidUrl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.url" must be a valid URL.');

        new ElicitRequestUrlParams(message: 'm', mode: 'url', url: 'not a url');
    }

    public function testConstructorRejectsWrongMode(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"params.mode" must be \'url\', \'form\' given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ElicitRequestUrlParams(message: 'm', mode: 'form', url: 'https://example.com');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ElicitRequestUrlParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing message' => [
            ['mode' => 'url', 'url' => 'https://example.com'],
            '"params" is missing the required "message" key.',
        ];

        yield 'message not a string' => [
            ['message' => 1, 'mode' => 'url', 'url' => 'https://example.com'],
            '"params.message" must be a non-empty string, int given.',
        ];

        yield 'missing mode' => [
            ['message' => 'm', 'url' => 'https://example.com'],
            '"params" is missing the required "mode" key.',
        ];

        yield 'mode not a string' => [
            ['message' => 'm', 'mode' => 1, 'url' => 'https://example.com'],
            '"params.mode" must be \'url\', 1 given.',
        ];

        yield 'missing url' => [
            ['message' => 'm', 'mode' => 'url'],
            '"params" is missing the required "url" key.',
        ];

        yield 'url not a string' => [
            ['message' => 'm', 'mode' => 'url', 'url' => 1],
            '"params.url" must be a non-empty string, int given.',
        ];
    }
}
