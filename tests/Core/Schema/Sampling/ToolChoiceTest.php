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

namespace Nexus\Mcp\Tests\Core\Schema\Sampling;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\ToolChoiceMode;
use Nexus\Mcp\Core\Schema\Sampling\ToolChoice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolChoice::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ToolChoiceTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $choice = new ToolChoice();

        self::assertNull($choice->mode);
    }

    public function testConstructionWithMode(): void
    {
        $choice = new ToolChoice(ToolChoiceMode::Required);

        self::assertSame(ToolChoiceMode::Required, $choice->mode);
    }

    public function testToArrayEmitsMode(): void
    {
        self::assertSame(['mode' => 'auto'], new ToolChoice(ToolChoiceMode::Auto)->toArray());
    }

    public function testToArrayOmitsAbsentMode(): void
    {
        self::assertSame([], new ToolChoice()->toArray());
    }

    public function testJsonSerializeStdClassWhenEmpty(): void
    {
        self::assertInstanceOf(\stdClass::class, new ToolChoice()->jsonSerialize());
        self::assertSame('{}', json_encode(new ToolChoice()));
    }

    public function testJsonSerializeWhenSet(): void
    {
        self::assertSame(['mode' => 'auto'], new ToolChoice(ToolChoiceMode::Auto)->jsonSerialize());
    }

    public function testFromArrayParsesMode(): void
    {
        $choice = ToolChoice::fromArray(['mode' => 'required']);

        self::assertSame(ToolChoiceMode::Required, $choice->mode);
    }

    public function testFromArrayWithoutMode(): void
    {
        $choice = ToolChoice::fromArray([]);

        self::assertNull($choice->mode);
    }

    public function testFromArrayRejectsNonStringMode(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"toolChoice.mode" must be one of [\'auto\', \'none\', \'required\'], 7 given.');

        ToolChoice::fromArray(['mode' => 7]);
    }

    public function testFromArrayRejectsUnknownMode(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('"toolChoice.mode" must be one of [\'auto\', \'none\', \'required\'], \'unknown\' given.');

        ToolChoice::fromArray(['mode' => 'unknown']);
    }
}
