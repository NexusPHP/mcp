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
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;

/**
 * The parameters for a request to elicit information from the user via a URL in the client.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#elicitrequesturlparams
 */
final readonly class ElicitRequestUrlParams extends TaskAugmentedRequestParams implements ElicitRequestParams
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
        ?TaskMetadata $task = null,
        ?RequestMeta $meta = null,
    ) {
        Assert::that($elicitationId)->isNonEmptyString('ElicitRequestUrlParams elicitationId must be a non-empty string.');
        Assert::that($message)->isNonEmptyString('ElicitRequestUrlParams message must be a non-empty string.');
        Assert::that($url)->isNonEmptyString('ElicitRequestUrlParams url must be a non-empty string.')->isUrl('ElicitRequestUrlParams url must be a valid URL.');
        Assert::that($mode)->isIdentical(self::MODE, \sprintf('ElicitRequestUrlParams mode must be "%s", {value} given.', self::MODE));

        $this->elicitationId = $elicitationId;
        $this->message = $message;
        $this->url = $url;
        $this->mode = $mode;

        parent::__construct($task, $meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('elicitationId', 'ElicitRequestUrlParams wire data missing "elicitationId".');
        $elicitationId = $data['elicitationId'];
        Assert::that($elicitationId)->isString('ElicitRequestUrlParams wire "elicitationId" must be a string, {type} given.');

        Assert::that($data)->hasOffset('message', 'ElicitRequestUrlParams wire data missing "message".');
        $message = $data['message'];
        Assert::that($message)->isString('ElicitRequestUrlParams wire "message" must be a string, {type} given.');

        Assert::that($data)->hasOffset('mode', 'ElicitRequestUrlParams wire data missing "mode".');
        $mode = $data['mode'];
        Assert::that($mode)->isString('ElicitRequestUrlParams wire "mode" must be a string, {type} given.');

        Assert::that($data)->hasOffset('url', 'ElicitRequestUrlParams wire data missing "url".');
        $url = $data['url'];
        Assert::that($url)->isString('ElicitRequestUrlParams wire "url" must be a string, {type} given.');

        $task = TaskMetadata::parseFromWire($data, 'ElicitRequestUrlParams');
        $meta = RequestMeta::parseFromWire($data, 'Request params');

        return new self($elicitationId, $message, $mode, $url, $task, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
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
