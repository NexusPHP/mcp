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

namespace Nexus\Mcp\Tests\Fixtures\Client\Http;

use Amp\Cancellation;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;

/**
 * Interceptor routing an `HttpClientBuilder`'s traffic to a fake client.
 *
 * @internal
 */
final class DelegatingInterceptor implements ApplicationInterceptor
{
    public function __construct(private readonly DelegateHttpClient $inner)
    {
    }

    #[\Override]
    public function request(Request $request, Cancellation $cancellation, DelegateHttpClient $httpClient): Response
    {
        return $this->inner->request($request, $cancellation);
    }
}
