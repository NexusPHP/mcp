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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;
use Nexus\Assert\ExpectationFailedException;

/**
 * Decode-side coercion for spec `"type": "number"` fields. JSON / JavaScript
 * do not distinguish int from float, so a sender may emit `5` or `5.0`
 * interchangeably. The SDK normalizes to PHP `float` internally while the
 * property/constructor type stays strict `float`.
 *
 * @internal
 */
trait ParsesNumber
{
    /**
     * @param non-empty-string $message
     *
     * @throws ExpectationFailedException
     */
    private static function parseNumber(mixed $value, string $message): float
    {
        if (\is_int($value)) {
            return (float) $value;
        }

        Assert::that($value)->isFloat($message);

        return $value;
    }
}
