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
 * User-agent double that records each redirect it was handed and answers with a callback echoing the state
 * the SDK recorded, so the response validates without a browser.
 *
 * @internal
 */
final class ScriptedUserAuthorization implements UserAuthorizationInterface
{
    /**
     * @var list<AuthorizationRedirect>
     */
    public private(set) array $redirects = [];

    /**
     * The cancellation each redirect was handed.
     *
     * @var list<Cancellation>
     */
    public private(set) array $cancellations = [];

    /**
     * @param array<string, string> $parameters Callback parameters beyond the echoed `state`
     */
    public function __construct(private readonly array $parameters = ['code' => 'the-code'])
    {
    }

    #[\Override]
    public function authorize(AuthorizationRedirect $redirect, Cancellation $cancellation): AuthorizationCallback
    {
        $this->redirects[] = $redirect;
        $this->cancellations[] = $cancellation;

        return new AuthorizationCallback('http://localhost:3000/callback?'.http_build_query([
            'state' => $redirect->state,
            ...$this->parameters,
        ]));
    }

    /**
     * The recorded redirect at `$index`.
     */
    public function readRedirect(int $index = 0): AuthorizationRedirect
    {
        return $this->redirects[$index] ?? throw new \OutOfBoundsException(\sprintf('No redirect was recorded at index %d.', $index));
    }

    /**
     * The scopes the recorded redirect at `$index` asked for.
     *
     * @return list<non-empty-string>
     */
    public function readRequestedScopes(int $index = 0): array
    {
        return $this->readRedirect($index)->requestedScopes->values;
    }
}
