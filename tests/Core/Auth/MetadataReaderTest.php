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

namespace Nexus\Mcp\Tests\Core\Auth;

use Nexus\Mcp\Core\Auth\MetadataReader;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(MetadataReader::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MetadataReaderTest extends AbstractMcpTestCase
{
    private const string LABEL = 'Test Metadata';

    public function testReadStringReturnsNullWhenTheFieldIsAbsent(): void
    {
        self::assertNull((new MetadataReader(self::LABEL))->readString([], 'issuer'));
    }

    public function testReadStringReturnsThePresentValue(): void
    {
        self::assertSame('https://auth.example.com', (new MetadataReader(self::LABEL))->readString(['issuer' => 'https://auth.example.com'], 'issuer'));
    }

    #[DataProvider('provideReadStringRejectsANonStringCases')]
    public function testReadStringRejectsANonString(mixed $value, string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Test Metadata "issuer" must be a non-empty string, %s given.', $type));

        (new MetadataReader(self::LABEL))->readString(['issuer' => $value], 'issuer');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideReadStringRejectsANonStringCases(): iterable
    {
        yield 'an empty string is not a value' => ['', 'string'];

        yield 'an integer is not a string' => [42, 'int'];

        yield 'null is not a string' => [null, 'null'];

        yield 'a list is not a string' => [['a'], 'array'];
    }

    public function testReadRequiredStringReturnsThePresentValue(): void
    {
        self::assertSame('https://auth.example.com', (new MetadataReader(self::LABEL))->readRequiredString(['issuer' => 'https://auth.example.com'], 'issuer'));
    }

    public function testReadRequiredStringRejectsAnAbsentField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Test Metadata must carry a "issuer" value.');

        (new MetadataReader(self::LABEL))->readRequiredString([], 'issuer');
    }

    public function testReadStringListReturnsNullWhenTheFieldIsAbsent(): void
    {
        self::assertNull((new MetadataReader(self::LABEL))->readStringList([], 'scopes_supported'));
    }

    public function testReadStringListReturnsAnEmptyList(): void
    {
        self::assertSame([], (new MetadataReader(self::LABEL))->readStringList(['scopes_supported' => []], 'scopes_supported'));
    }

    public function testReadStringListReturnsThePresentValues(): void
    {
        self::assertSame(
            ['files:read', 'files:write'],
            (new MetadataReader(self::LABEL))->readStringList(['scopes_supported' => ['files:read', 'files:write']], 'scopes_supported'),
        );
    }

    #[DataProvider('provideReadStringListRejectsANonListCases')]
    public function testReadStringListRejectsANonList(mixed $value, string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Test Metadata "scopes_supported" must be a list, %s given.', $type));

        (new MetadataReader(self::LABEL))->readStringList(['scopes_supported' => $value], 'scopes_supported');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideReadStringListRejectsANonListCases(): iterable
    {
        yield 'a string is not a list' => ['files:read', 'string'];

        yield 'a map is not a list' => [['a' => 'b'], 'array'];

        yield 'null is not a list' => [null, 'null'];
    }

    public function testReadStringListRejectsANonStringEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Test Metadata "scopes_supported" must hold only non-empty strings, int given.');

        (new MetadataReader(self::LABEL))->readStringList(['scopes_supported' => ['files:read', 42]], 'scopes_supported');
    }

    public function testReadIntReturnsNullWhenTheFieldIsAbsent(): void
    {
        self::assertNull((new MetadataReader(self::LABEL))->readInt([], 'expires_in'));
    }

    public function testReadIntReturnsThePresentValue(): void
    {
        self::assertSame(3_600, (new MetadataReader(self::LABEL))->readInt(['expires_in' => 3_600], 'expires_in'));
    }

    public function testReadIntKeepsAZeroLifetimeDistinctFromAnAbsentOne(): void
    {
        self::assertSame(0, (new MetadataReader(self::LABEL))->readInt(['expires_in' => 0], 'expires_in'));
    }

    #[DataProvider('provideReadIntRejectsANonIntegerCases')]
    public function testReadIntRejectsANonInteger(mixed $value, string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('Test Metadata "expires_in" must be an integer, %s given.', $type));

        (new MetadataReader(self::LABEL))->readInt(['expires_in' => $value], 'expires_in');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideReadIntRejectsANonIntegerCases(): iterable
    {
        yield 'a numeric string is not an integer' => ['3600', 'string'];

        yield 'a float is not an integer' => [3_600.0, 'float'];

        yield 'null is not an integer' => [null, 'null'];
    }

    public function testReadBoolReturnsNullWhenTheFieldIsAbsent(): void
    {
        self::assertNull((new MetadataReader(self::LABEL))->readBool([], 'client_id_metadata_document_supported'));
    }

    #[DataProvider('provideReadBoolCases')]
    public function testReadBool(bool $value): void
    {
        self::assertSame($value, (new MetadataReader(self::LABEL))->readBool(['client_id_metadata_document_supported' => $value], 'client_id_metadata_document_supported'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideReadBoolCases(): iterable
    {
        yield 'true is carried through' => [true];

        yield 'false is carried through' => [false];
    }

    public function testReadBoolRejectsANonBoolean(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Test Metadata "client_id_metadata_document_supported" must be a boolean, string given.');

        (new MetadataReader(self::LABEL))->readBool(['client_id_metadata_document_supported' => 'true'], 'client_id_metadata_document_supported');
    }

    #[DataProvider('provideReadErrorFieldCases')]
    public function testReadErrorField(string $value, ?string $expected): void
    {
        self::assertSame($expected, (new MetadataReader(self::LABEL))->readErrorField(['error_description' => $value], 'error_description'));
    }

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function provideReadErrorFieldCases(): iterable
    {
        yield 'a plain description is carried through' => ['The code has expired.', 'The code has expired.'];

        yield 'a carriage return that would forge a log record is dropped' => [
            "Denied.\r\n[2026-07-28] CRITICAL: the operator approved everything",
            'Denied.[2026-07-28] CRITICAL: the operator approved everything',
        ];

        yield 'an ANSI escape that would rewrite the terminal is dropped' => ["Denied.\e[2K\e[1G Approved.", 'Denied.[2K[1G Approved.'];

        yield 'a NUL is dropped' => ["Denied\0.", 'Denied.'];

        yield 'the quote and backslash the grammar excludes are dropped' => ['He said "no" \\ twice.', 'He said no  twice.'];

        yield 'a description longer than a record carries is truncated' => [
            str_repeat('a', 250),
            str_repeat('a', 200),
        ];

        yield 'a description of nothing but forbidden bytes names nothing' => ["\r\n\t", null];
    }

    public function testReadErrorFieldTreatsAnAbsentKeyAsNamingNothing(): void
    {
        self::assertNull((new MetadataReader(self::LABEL))->readErrorField([], 'error_description'));
    }

    public function testReadErrorFieldRejectsANonString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Test Metadata "error_description" must be a non-empty string, int given.');

        (new MetadataReader(self::LABEL))->readErrorField(['error_description' => 5], 'error_description');
    }
}
