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
use Amp\Http\Client\Response;
use Nexus\Mcp\Client\Auth\AuthorizationCallback;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\UserAuthorizationInterface;

/**
 * Completes Keycloak's login form without a browser or a human.
 *
 * A real client opens `$redirect->url` in a user-agent and reports where it
 * landed. This one plays the user-agent itself: it fetches the login page,
 * posts the demo credentials to the form, and reads the redirect Keycloak
 * answers with. The SDK still owns PKCE, `state`, and issuer validation, so
 * nothing here weakens the flow being demonstrated.
 */
final class KeycloakLogin implements UserAuthorizationInterface
{
    public function __construct(private readonly string $username, private readonly string $password)
    {
    }

    #[Override]
    public function authorize(AuthorizationRedirect $redirect, Cancellation $cancellation): AuthorizationCallback
    {
        // The redirect back to the client is the answer, so following it would
        // discard the thing being read.
        $client = (new HttpClientBuilder())->followRedirects(0)->build();

        $page = $client->request(new Request($redirect->url), $cancellation);

        // An SSO cookie from an earlier login skips the form entirely.
        $location = $page->getHeader('location');

        if (is_string($location) && '' !== $location) {
            return new AuthorizationCallback($location);
        }

        $html = $page->getBody()->buffer($cancellation);

        if (preg_match('/action="([^"]+)"/', $html, $matches) !== 1) {
            throw new RuntimeException(sprintf(
                'The authorization server answered %d without a login form to fill.',
                $page->getStatus(),
            ));
        }

        $login = new Request(html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5), 'POST');
        $login->setHeader('cookie', self::cookiesFrom($page));
        $login->setHeader('content-type', 'application/x-www-form-urlencoded');
        $login->setBody(http_build_query([
            'username' => $this->username,
            'password' => $this->password,
            'credentialId' => '',
        ]));

        $answer = $client->request($login, $cancellation);
        $location = $answer->getHeader('location');

        if (! is_string($location) || '' === $location) {
            throw new RuntimeException(sprintf(
                'Keycloak answered the login with %d instead of a redirect. Wrong credentials?',
                $answer->getStatus(),
            ));
        }

        return new AuthorizationCallback($location);
    }

    /**
     * The auth-session cookies the login form's POST must present back.
     */
    private static function cookiesFrom(Response $response): string
    {
        $pairs = [];

        foreach ($response->getHeaderArray('set-cookie') as $cookie) {
            $pairs[] = explode(';', $cookie, 2)[0];
        }

        return implode('; ', $pairs);
    }
}
