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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * The parameters for a request to elicit information from the user via a URL in the client.
 *
 * @implements Arrayable<array{message: non-empty-string, mode: 'url', url: non-empty-string}>
 * @implements ElicitRequestParams<array{message: non-empty-string, mode: 'url', url: non-empty-string}>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#elicitrequesturlparams
 */
final readonly class ElicitRequestUrlParams implements Arrayable, ElicitRequestParams
{
    public const string MODE = 'url';

    /**
     * @param non-empty-string $message
     * @param 'url'            $mode
     * @param non-empty-string $url
     */
    public function __construct(
        public string $message,
        public string $mode,
        public string $url,
    ) {
        Assert::that($message)->isNonEmptyString('"params.message" must be a non-empty string.');
        Assert::that($url)->isNonEmptyString('"params.url" must be a non-empty string.')->isUrl('"params.url" must be a valid URL.');
        Assert::that($mode)->isIdentical(self::MODE, '"params.mode" must be {other}, {value} given.');
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('message', '"params" is missing the required "message" key.');
        $message = $data['message'];
        Assert::that($message)->isNonEmptyString('"params.message" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('mode', '"params" is missing the required "mode" key.');
        $mode = $data['mode'];
        Assert::that($mode)->isIdentical(self::MODE, '"params.mode" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('url', '"params" is missing the required "url" key.');
        $url = $data['url'];
        Assert::that($url)->isNonEmptyString('"params.url" must be a non-empty string, {type} given.');

        return new self(message: $message, mode: $mode, url: $url);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'mode' => $this->mode,
            'url' => $this->url,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
