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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Auth\MetadataReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MetadataReader::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MetadataReaderTest extends TestCase
{
    private const string LABEL = 'Test Metadata';

    public function testReadStringReturnsNullWhenTheFieldIsAbsent(): void
    {
        self::assertNull(MetadataReader::readString([], 'issuer', self::LABEL));
    }

    public function testReadStringReturnsThePresentValue(): void
    {
        self::assertSame('https://auth.example.com', MetadataReader::readString(['issuer' => 'https://auth.example.com'], 'issuer', self::LABEL));
    }

    #[DataProvider('provideReadStringRejectsANonStringCases')]
    public function testReadStringRejectsANonString(mixed $value, string $type): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(\sprintf('Test Metadata "issuer" must be a non-empty string, %s given.', $type));

        MetadataReader::readString(['issuer' => $value], 'issuer', self::LABEL);
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
        self::assertSame('https://auth.example.com', MetadataReader::readRequiredString(['issuer' => 'https://auth.example.com'], 'issuer', self::LABEL));
    }

    public function testReadRequiredStringRejectsAnAbsentField(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Test Metadata must carry a "issuer" value.');

        MetadataReader::readRequiredString([], 'issuer', self::LABEL);
    }

    public function testReadStringListReturnsNullWhenTheFieldIsAbsent(): void
    {
        self::assertNull(MetadataReader::readStringList([], 'scopes_supported', self::LABEL));
    }

    public function testReadStringListReturnsAnEmptyList(): void
    {
        self::assertSame([], MetadataReader::readStringList(['scopes_supported' => []], 'scopes_supported', self::LABEL));
    }

    public function testReadStringListReturnsThePresentValues(): void
    {
        self::assertSame(
            ['files:read', 'files:write'],
            MetadataReader::readStringList(['scopes_supported' => ['files:read', 'files:write']], 'scopes_supported', self::LABEL),
        );
    }

    #[DataProvider('provideReadStringListRejectsANonListCases')]
    public function testReadStringListRejectsANonList(mixed $value, string $type): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(\sprintf('Test Metadata "scopes_supported" must be a list, %s given.', $type));

        MetadataReader::readStringList(['scopes_supported' => $value], 'scopes_supported', self::LABEL);
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
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Test Metadata "scopes_supported" must hold only non-empty strings, int given.');

        MetadataReader::readStringList(['scopes_supported' => ['files:read', 42]], 'scopes_supported', self::LABEL);
    }

    public function testReadBoolReturnsNullWhenTheFieldIsAbsent(): void
    {
        self::assertNull(MetadataReader::readBool([], 'client_id_metadata_document_supported', self::LABEL));
    }

    #[DataProvider('provideReadBoolCases')]
    public function testReadBool(bool $value): void
    {
        self::assertSame($value, MetadataReader::readBool(['client_id_metadata_document_supported' => $value], 'client_id_metadata_document_supported', self::LABEL));
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
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Test Metadata "client_id_metadata_document_supported" must be a boolean, string given.');

        MetadataReader::readBool(['client_id_metadata_document_supported' => 'true'], 'client_id_metadata_document_supported', self::LABEL);
    }
}
