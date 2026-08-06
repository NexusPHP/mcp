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
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;

/**
 * The parameters for a request to elicit non-sensitive information from the user via a form in the client.
 *
 * @implements Arrayable<array{mode?: 'form', message: non-empty-string, requestedSchema: array<string, mixed>}>
 * @implements ElicitRequestParams<array{mode?: 'form', message: non-empty-string, requestedSchema: array<string, mixed>}>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#elicitrequestformparams
 */
final readonly class ElicitRequestFormParams implements Arrayable, ElicitRequestParams
{
    public const string MODE = 'form';

    /**
     * @param non-empty-string $message
     * @param 'form'           $mode
     */
    public function __construct(
        public string $message,
        public ElicitRequestedSchema $requestedSchema,
        public string $mode = self::MODE,
    ) {
        Assert::that($message)->isNonEmptyString('"params.message" must be a non-empty string.');
        Assert::that($mode)->isIdentical(self::MODE, '"params.mode" must be {other}, {value} given.');
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $mode = $data['mode'] ?? self::MODE;
        Assert::that($mode)->isIdentical(self::MODE, '"params.mode" must be {other}, {value} given.');

        Assert::that($data)->hasOffset('message', '"params" is missing the required "message" key.');
        $message = $data['message'];
        Assert::that($message)->isNonEmptyString('"params.message" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('requestedSchema', '"params" is missing the required "requestedSchema" key.');
        Assert::that($data['requestedSchema'])
            ->isArray('"params.requestedSchema" must be an object, {type} given.')
            ->isMap('"params.requestedSchema" must be a string-keyed object.')
        ;
        $requestedSchema = ElicitRequestedSchema::fromArray($data['requestedSchema']);

        return new self(message: $message, requestedSchema: $requestedSchema, mode: $mode);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'message' => $this->message,
            'requestedSchema' => $this->requestedSchema->toArray(),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
