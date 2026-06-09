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

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;

use function PHPStan\Testing\assertType;

$response = new JsonRpcResultResponse(id: new RequestId(id: 1), result: new EmptyResult());

assertType(
    'Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse<Nexus\Mcp\Core\Schema\Result\EmptyResult>',
    $response,
);
assertType(EmptyResult::class, $response->result);
