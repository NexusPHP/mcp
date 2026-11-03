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

namespace Nexus\Mcp\Tests\Fixtures\Client\Auth;

use Amp\Cancellation;
use Nexus\Mcp\Client\Auth\AuthorizationCallback;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\UserAuthorizationInterface;

/**
 * User-authorization double that answers every redirect with exactly the callback parameters it was given.
 *
 * @internal
 */
final readonly class RawCallbackUserAuthorization implements UserAuthorizationInterface
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(private array $parameters)
    {
    }

    #[\Override]
    public function authorize(AuthorizationRedirect $redirect, Cancellation $cancellation): AuthorizationCallback
    {
        return new AuthorizationCallback('http://localhost:3000/callback?'.http_build_query($this->parameters));
    }
}
