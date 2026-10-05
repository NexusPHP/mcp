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

namespace Nexus\Mcp\Tests\Extension\Skills\Schema\ResultResponse;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Extension\Skills\Schema\ResultResponse\ListSkillsResultResponse;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListSkillsResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ListSkillsResultResponseTest extends AbstractMcpTestCase
{
    private const array PAYLOAD = [
        'jsonrpc' => '2.0',
        'id' => 7,
        'result' => [
            'resultType' => 'complete',
            'skills' => [[
                'uri' => 'skill://refunds/SKILL.md',
                'frontmatter' => ['name' => 'refunds', 'description' => 'Process refunds.'],
                'resources' => 'dynamic',
            ]],
            'ttlMs' => 0,
            'cacheScope' => 'private',
        ],
    ];

    public function testDecodesTheSkills(): void
    {
        $response = ListSkillsResultResponse::fromArray(self::PAYLOAD);

        self::assertSame(7, $response->id->id);
        self::assertArrayHasKey(0, $response->result->skills);
        self::assertSame('refunds', $response->result->skills[0]->frontmatter['name']);
    }

    public function testToArrayRoundTripsTheEnvelope(): void
    {
        self::assertSame(self::PAYLOAD, ListSkillsResultResponse::fromArray(self::PAYLOAD)->toArray());
    }

    public function testRejectsAnInputRequiredEnvelope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" returned "input_required" for a method that does not support it.');

        ListSkillsResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);
    }
}
