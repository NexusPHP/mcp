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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ResourceTemplateReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceTemplateReference::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceTemplateReferenceTest extends TestCase
{
    public function testConstructionStoresUri(): void
    {
        $reference = new ResourceTemplateReference('file:///tmp/{name}');

        self::assertSame('file:///tmp/{name}', $reference->uri);
    }

    public function testToArrayEmitsTypeAndUri(): void
    {
        $reference = new ResourceTemplateReference('file:///tmp/{name}');

        self::assertSame(
            ['type' => 'ref/resource', 'uri' => 'file:///tmp/{name}'],
            $reference->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $reference = new ResourceTemplateReference('file:///tmp/{name}');

        self::assertSame($reference->toArray(), $reference->jsonSerialize());
    }

    public function testFromArrayParses(): void
    {
        $reference = ResourceTemplateReference::fromArray([
            'type' => 'ref/resource',
            'uri' => 'file:///tmp/{name}',
        ]);

        self::assertSame('file:///tmp/{name}', $reference->uri);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ResourceTemplateReference('https://api.example.com/{user}/repos/{repo}');

        $rebuilt = ResourceTemplateReference::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsInvalidUriTemplate(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/\AResourceTemplateReference URI template must be a valid RFC 6570/');

        new ResourceTemplateReference('not-a-uri');
    }

    public function testConstructorRejectsEmptyUri(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ResourceTemplateReference URI template must be a non-empty string.');

        new ResourceTemplateReference('');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ResourceTemplateReference::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing type' => [
            ['uri' => 'file:///tmp/{name}'],
            'ResourceTemplateReference wire data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'ref/prompt', 'uri' => 'file:///tmp/{name}'],
            'ResourceTemplateReference wire "type" must be "ref/resource", \'ref/prompt\' given.',
        ];

        yield 'missing uri' => [
            ['type' => 'ref/resource'],
            'ResourceTemplateReference wire data missing "uri".',
        ];

        yield 'uri not a string' => [
            ['type' => 'ref/resource', 'uri' => 1],
            'ResourceTemplateReference wire "uri" must be a string, int given.',
        ];
    }
}
