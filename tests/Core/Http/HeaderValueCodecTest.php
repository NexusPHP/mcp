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

namespace Nexus\Mcp\Tests\Core\Http;

use Nexus\Mcp\Core\Http\HeaderValueCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(HeaderValueCodec::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class HeaderValueCodecTest extends TestCase
{
    #[DataProvider('provideEncodeCases')]
    public function testEncode(string $input, string $expected): void
    {
        self::assertSame($expected, HeaderValueCodec::encode($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEncodeCases(): iterable
    {
        yield 'plain ASCII passes through unchanged' => ['us-west1', 'us-west1'];

        yield 'interior space stays plain' => ['a b c', 'a b c'];

        yield 'interior tab (0x09) stays plain' => ["a\tb", "a\tb"];

        yield 'lower boundary of the visible range (0x21) stays plain' => ['!', '!'];

        yield 'upper boundary of the visible range (0x7E) stays plain' => ['~', '~'];

        yield 'space boundary (0x20) stays plain when interior' => ['a b', 'a b'];

        yield 'empty string is wrapped' => ['', '=?base64??='];

        yield 'leading and trailing whitespace is wrapped' => [' padded ', '=?base64?IHBhZGRlZCA=?='];

        yield 'leading tab is wrapped' => ["\tindent", '=?base64?CWluZGVudA==?='];

        yield 'non-ASCII is wrapped' => ['Hello, 世界', '=?base64?SGVsbG8sIOS4lueVjA==?='];

        yield 'newline is wrapped' => ["line1\nline2", '=?base64?bGluZTEKbGluZTI=?='];

        yield 'control byte just below tab (0x08) is wrapped' => ["a\x08b", '=?base64?YQhi?='];

        yield 'control byte just below space (0x1F) is wrapped' => ["a\x1Fb", '=?base64?YR9i?='];

        yield 'DEL byte just above the visible range (0x7F) is wrapped' => ["a\x7Fb", '=?base64?YX9i?='];

        yield 'leading control byte outside the trim set is wrapped' => ["\x01abc", '=?base64?AWFiYw==?='];

        yield 'a value already matching the sentinel is self-encoded' => ['=?base64?literal?=', '=?base64?PT9iYXNlNjQ/bGl0ZXJhbD89?='];
    }

    #[DataProvider('provideDecodeCases')]
    public function testDecode(string $input, ?string $expected): void
    {
        self::assertSame($expected, HeaderValueCodec::decode($input));
    }

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function provideDecodeCases(): iterable
    {
        yield 'a value without the sentinel passes through' => ['us-west1', 'us-west1'];

        yield 'the sentinel prefix without the suffix passes through' => ['=?base64?abc', '=?base64?abc'];

        yield 'the sentinel suffix without the prefix passes through' => ['abc?=', 'abc?='];

        yield 'an uppercase sentinel prefix is a literal value' => ['=?BASE64?SGVsbG8=?=', '=?BASE64?SGVsbG8=?='];

        yield 'a mixed-case sentinel prefix is a literal value' => ['=?Base64?SGVsbG8=?=', '=?Base64?SGVsbG8=?='];

        yield 'an empty payload decodes to an empty string' => ['=?base64??=', ''];

        yield 'a non-ASCII payload round-trips' => ['=?base64?SGVsbG8sIOS4lueVjA==?=', 'Hello, 世界'];

        yield 'a padded payload round-trips' => ['=?base64?IHBhZGRlZCA=?=', ' padded '];

        yield 'a self-encoded sentinel round-trips' => ['=?base64?PT9iYXNlNjQ/bGl0ZXJhbD89?=', '=?base64?literal?='];

        yield 'a payload with an invalid alphabet character is rejected' => ['=?base64?SGVsbG8*?=', null];

        yield 'a payload with non-canonical padding is rejected' => ['=?base64?SGVsbG8?=', null];

        yield 'a payload with embedded whitespace is rejected' => ['=?base64?SGVs bG8=?=', null];

        yield 'a payload decoding to invalid UTF-8 is rejected' => ['=?base64?/w==?=', null];
    }

    #[DataProvider('provideRoundTripCases')]
    public function testRoundTrip(string $value): void
    {
        self::assertSame($value, HeaderValueCodec::decode(HeaderValueCodec::encode($value)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRoundTripCases(): iterable
    {
        yield 'plain ASCII' => ['us-west1'];

        yield 'non-ASCII' => ['Hello, 世界'];

        yield 'four-byte emoji' => ['party 🎉'];

        yield 'leading and trailing whitespace' => [' padded '];

        yield 'embedded newline' => ["line1\nline2"];

        yield 'sentinel-looking value' => ['=?base64?literal?='];

        yield 'empty string' => [''];

        yield 'interior tab' => ["a\tb"];

        yield 'embedded NUL byte' => ["a\0b"];

        yield 'leading control byte' => ["\x01abc"];
    }
}
