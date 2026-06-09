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

namespace Nexus\Mcp\Tests\Core\Schema\Error;

use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\UrlElicitationRequiredErrorPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UrlElicitationRequiredErrorPayload::class)]
#[CoversClass(Error::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class UrlElicitationRequiredErrorPayloadTest extends TestCase
{
    public function testDefaultsPopulateParentFields(): void
    {
        $payload = new UrlElicitationRequiredErrorPayload();

        self::assertSame(-32042, $payload->code);
        self::assertSame('URL elicitation required', $payload->message);
        self::assertNull($payload->data);
    }

    public function testFromArrayUsesProvidedMessage(): void
    {
        $payload = UrlElicitationRequiredErrorPayload::fromArray([
            'message' => 'Custom auth challenge',
            'data' => ['elicitations' => []],
        ]);

        self::assertSame('Custom auth challenge', $payload->message);
        self::assertSame(['elicitations' => []], $payload->data);
    }

    public function testFromArrayFallsBackToDefaultMessage(): void
    {
        $payload = UrlElicitationRequiredErrorPayload::fromArray([]);

        self::assertSame('URL elicitation required', $payload->message);
        self::assertNull($payload->data);
    }

    public function testToArrayIncludesData(): void
    {
        $payload = new UrlElicitationRequiredErrorPayload('Custom auth challenge', ['elicitations' => []]);

        self::assertSame([
            'code' => -32042,
            'message' => 'Custom auth challenge',
            'data' => ['elicitations' => []],
        ], $payload->toArray());
    }

    public function testToArrayOmitsEmptyData(): void
    {
        $payload = new UrlElicitationRequiredErrorPayload();

        self::assertSame([
            'code' => -32042,
            'message' => 'URL elicitation required',
        ], $payload->toArray());
    }
}
