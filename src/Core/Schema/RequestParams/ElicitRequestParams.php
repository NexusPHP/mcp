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

namespace Nexus\Mcp\Core\Schema\RequestParams;

use Nexus\Mcp\Core\Schema\RequestParamsInterface;

/**
 * The parameters for a request to elicit additional information from the user via the client.
 *
 * @template-covariant T of array<string, mixed>
 *
 * @extends RequestParamsInterface<T>
 *
 * @phpstan-sealed ElicitRequestUrlParams|ElicitRequestFormParams
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#elicitrequestparams
 */
interface ElicitRequestParams extends RequestParamsInterface
{
}
