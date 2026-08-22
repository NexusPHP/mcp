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

namespace Nexus\Mcp\Core\Auth;

use Nexus\Assert\Assert;

/**
 * What a validated bearer token grants, as reported by the host's token validator.
 *
 * @see https://datatracker.ietf.org/doc/html/draft-ietf-oauth-v2-1-13#section-5.2
 */
final readonly class VerifiedAccessToken
{
    /**
     * PSR-7 request attribute a validated token travels on.
     */
    public const string REQUEST_ATTRIBUTE = 'nexus.mcp.access_token';

    /**
     * @param list<string>           $audience  Resources the token was issued for, at least one of which must be this server
     * @param int<1, max>            $expiresAt Unix timestamp the token expires at
     * @param list<non-empty-string> $scopes    Scopes the token was granted
     * @param null|non-empty-string  $subject   Principal the token acts for, absent when it carries no non-empty `sub` claim
     * @param null|non-empty-string  $clientId  OAuth client the token was issued to, absent when it names none
     */
    public function __construct(
        public array $audience,
        public int $expiresAt,
        public array $scopes = [],
        public ?string $subject = null,
        public ?string $clientId = null,
    ) {
        Assert::that($expiresAt)->isPositiveInt('Verified access token expiry must be a positive Unix timestamp, {value} given.');
    }
}
