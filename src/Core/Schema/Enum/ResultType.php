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

namespace Nexus\Mcp\Core\Schema\Enum;

/**
 * Indicates the type of a `Result` object, allowing the client to determine how to parse the response.
 *
 * complete - the request completed successfully and the result contains the final content.
 * input_required - the request requires additional input and the result contains an `InputRequiredResult`
 * object with instructions for the client to provide additional input before retrying the original request.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#resulttype
 */
enum ResultType: string
{
    case Complete = 'complete';
    case InputRequired = 'input_required';

    /**
     * Reserved by SEP-2663 for the tasks extension: the request was accepted
     * as a long-running task and the result carries a task handle.
     */
    case Task = 'task';
}
