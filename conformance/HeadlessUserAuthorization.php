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

use Amp\Cancellation;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Nexus\Mcp\Client\Auth\AuthorizationCallback;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\UserAuthorizationInterface;

/**
 * Completes the authorization step without a browser or a human.
 *
 * The referee's mock authorization server grants consent on the first request
 * and answers with a redirect, so following the URL once with redirects
 * disabled and reading `Location` yields the callback the SDK expects. A real
 * implementation opens the URL in a user-agent instead.
 */
final class HeadlessUserAuthorization implements UserAuthorizationInterface
{
    #[Override]
    public function authorize(AuthorizationRedirect $redirect, Cancellation $cancellation): AuthorizationCallback
    {
        // The redirect *is* the answer, so following it would discard the thing
        // being read. `buildDefault()` follows up to 10, hence the explicit 0.
        $client = new HttpClientBuilder()->followRedirects(0)->build();

        $response = $client->request(new Request($redirect->url), $cancellation);
        $location = $response->getHeader('location');

        if (! is_string($location) || '' === $location) {
            throw new RuntimeException(sprintf(
                'The authorization server answered %d without a Location header, so there is no callback to read.',
                $response->getStatus(),
            ));
        }

        return new AuthorizationCallback($location);
    }
}
