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

namespace Nexus\Mcp\Tests\Server\Dispatch;

use Nexus\Mcp\Server\Dispatch\InitializationGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InitializationGate::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class InitializationGateTest extends TestCase
{
    public function testStartsUninitialized(): void
    {
        $gate = new InitializationGate();

        self::assertFalse($gate->isInitialized());
    }

    public function testAllowsInitializeBeforeHandshake(): void
    {
        $gate = new InitializationGate();

        self::assertTrue($gate->allowsRequest('initialize'));
    }

    public function testAllowsPingBeforeHandshake(): void
    {
        $gate = new InitializationGate();

        self::assertTrue($gate->allowsRequest('ping'));
    }

    public function testRejectsArbitraryRequestBeforeHandshake(): void
    {
        $gate = new InitializationGate();

        self::assertFalse($gate->allowsRequest('tools/list'));
        self::assertFalse($gate->allowsRequest('prompts/get'));
        self::assertFalse($gate->allowsRequest('resources/read'));
    }

    public function testMarkInitializedFlipsTheGate(): void
    {
        $gate = new InitializationGate();

        $gate->markInitialized();

        self::assertTrue($gate->isInitialized());
        self::assertTrue($gate->allowsRequest('tools/list'));
        self::assertTrue($gate->allowsRequest('prompts/get'));
    }

    public function testMarkInitializedIsIdempotent(): void
    {
        $gate = new InitializationGate();

        $gate->markInitialized();
        $gate->markInitialized();

        self::assertTrue($gate->isInitialized());
    }
}
