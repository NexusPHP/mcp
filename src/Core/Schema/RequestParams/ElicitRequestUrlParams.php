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
 * @implements Arrayable<array{elicitationId: non-empty-string, message: non-empty-string, mode: 'url', url: non-empty-string}>
 * @implements ElicitRequestParams<array{elicitationId: non-empty-string, message: non-empty-string, mode: 'url', url: non-empty-string}>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#elicitrequesturlparams
 */
final readonly class ElicitRequestUrlParams implements Arrayable, ElicitRequestParams
{
    public const string MODE = 'url';

    /**
     * @var non-empty-string
     */
    public string $elicitationId;

    /**
     * @var non-empty-string
     */
    public string $message;

    /**
     * @var 'url'
     */
    public string $mode;

    /**
     * @var non-empty-string
     */
    public string $url;

    public function __construct(
        string $elicitationId,
        string $message,
        string $mode,
        string $url,
    ) {
        Assert::that($elicitationId)->isNonEmptyString('"params.elicitationId" must be a non-empty string.');
        Assert::that($message)->isNonEmptyString('"params.message" must be a non-empty string.');
        Assert::that($url)->isNonEmptyString('"params.url" must be a non-empty string.')->isUrl('"params.url" must be a valid URL.');
        Assert::that($mode)->isIdentical(self::MODE, '"params.mode" must be {other}, {value} given.');

        $this->elicitationId = $elicitationId;
        $this->message = $message;
        $this->url = $url;
        $this->mode = $mode;
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('elicitationId', 'missing the required "elicitationId" key.');
        $elicitationId = $data['elicitationId'];
        Assert::that($elicitationId)->isString('"params.elicitationId" must be a string, {type} given.');

        Assert::that($data)->hasOffset('message', 'missing the required "message" key.');
        $message = $data['message'];
        Assert::that($message)->isString('"params.message" must be a string, {type} given.');

        Assert::that($data)->hasOffset('mode', 'missing the required "mode" key.');
        $mode = $data['mode'];
        Assert::that($mode)->isString('"params.mode" must be a string, {type} given.');

        Assert::that($data)->hasOffset('url', 'missing the required "url" key.');
        $url = $data['url'];
        Assert::that($url)->isString('"params.url" must be a string, {type} given.');

        return new self(elicitationId: $elicitationId, message: $message, mode: $mode, url: $url);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'elicitationId' => $this->elicitationId,
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
