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

namespace Nexus\Mcp\Tests\Extension\Skills\Schema\Request;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Extension\Skills\Schema\Request\GetSkillRequest;
use Nexus\Mcp\Extension\Skills\Schema\RequestParams\GetSkillRequestParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GetSkillRequest::class)]
#[CoversClass(JsonRpcRequest::class)]
#[CoversClass(Request::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class GetSkillRequestTest extends AbstractMcpTestCase
{
    public function testMethodIsSkillsGet(): void
    {
        self::assertSame('skills/get', GetSkillRequest::getMethod());
    }

    public function testToArrayBuildsEnvelope(): void
    {
        $request = new GetSkillRequest(
            id: new RequestId(id: 1),
            params: new GetSkillRequestParams(uri: 'skill://git-workflow/SKILL.md', meta: RequestMetaObjectFactory::create()),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'skills/get',
                'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'uri' => 'skill://git-workflow/SKILL.md'],
            ],
            $request->toArray(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new GetSkillRequest(
            id: new RequestId(id: 'req-1'),
            params: new GetSkillRequestParams(uri: 'skill://git-workflow/SKILL.md', meta: RequestMetaObjectFactory::create()),
        );

        self::assertSame($original->toArray(), GetSkillRequest::fromArray($original->toArray())->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        GetSkillRequest::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing id' => [['params' => []], 'missing the required "id" key.'];

        yield 'id not int or string' => [['id' => [], 'params' => []], '"id" must be an int or non-empty string, array given.'];

        yield 'missing params' => [['id' => 1], 'missing the required "params" key.'];

        yield 'params not an object' => [['id' => 1, 'params' => 'bad'], '"params" must be an object, string given.'];

        yield 'params list-keyed' => [['id' => 1, 'params' => ['x']], '"params" must be a string-keyed object.'];
    }
}
