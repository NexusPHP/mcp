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

use Nexus\Mcp\Core\Schema\Result\InputResponse;

/**
 * Params that may continue an exchange a prior `InputRequiredResult` suspended. The spec
 * spells this as `InputResponseRequestParams`, which `ReadResourceRequestParams` also extends
 * without being able to inherit from it in PHP.
 */
interface InputResponseCarrierInterface
{
    /**
     * @return null|array<int|non-empty-string, InputResponse>
     */
    public function getInputResponses(): ?array;

    public function getRequestState(): ?string;
}
