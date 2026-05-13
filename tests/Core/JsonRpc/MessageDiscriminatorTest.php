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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\JsonRpc\MessageDiscriminator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MessageDiscriminator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MessageDiscriminatorTest extends TestCase
{
    public function testReadTypeReturnsTypeValue(): void
    {
        $type = MessageDiscriminator::readType(['type' => 'text', 'text' => 'hello'], 'PromptMessage content');

        self::assertSame('text', $type);
    }

    public function testReadTypeRejectsMissingType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('PromptMessage content data missing "type".');

        MessageDiscriminator::readType(['text' => 'hello'], 'PromptMessage content');
    }

    public function testReadTypeRejectsNonStringType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CompleteRequestParams ref "type" must be a string, int given.');

        MessageDiscriminator::readType(['type' => 1], 'CompleteRequestParams ref');
    }

    public function testUnknownTypeFormatsExceptionWithAllowedValues(): void
    {
        $exception = MessageDiscriminator::unknownType(
            'CompleteRequestParams ref',
            ['ref/prompt', 'ref/resource'],
            'unknown',
        );

        self::assertSame(
            'CompleteRequestParams ref "type" must be one of "ref/prompt", "ref/resource"; "unknown" given.',
            $exception->getMessage(),
        );
    }

    public function testUnknownTypeWithSingleAllowedValue(): void
    {
        $exception = MessageDiscriminator::unknownType('Foo', ['only'], 'other');

        self::assertSame(
            'Foo "type" must be one of "only"; "other" given.',
            $exception->getMessage(),
        );
    }
}
