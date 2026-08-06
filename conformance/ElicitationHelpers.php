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

use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Elicitation\PrimitiveSchemaDefinition;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Server\ServerContext;

/**
 * Elicitation scaffolding shared by the fixture servers: building
 * one-question `InputRequiredResult`s and reading the answers back.
 */
trait ElicitationHelpers
{
    /**
     * Builds a one-question `InputRequiredResult`.
     *
     * @param non-empty-string $key
     * @param non-empty-string $message
     * @param non-empty-string $field
     */
    private static function ask(
        string $key,
        string $message,
        string $field,
        ?PrimitiveSchemaDefinition $schema = null,
        ?string $state = null,
    ): InputRequiredResult {
        return new InputRequiredResult(
            inputRequests: [$key => self::buildInputRequest($message, $field, $schema ?? new StringSchema())],
            requestState: $state,
        );
    }

    /**
     * @param non-empty-string $message
     * @param non-empty-string $field
     */
    private static function buildInputRequest(string $message, string $field, PrimitiveSchemaDefinition $schema): ElicitRequest
    {
        return new ElicitRequest(params: new ElicitRequestFormParams(
            message: $message,
            requestedSchema: new ElicitRequestedSchema(
                properties: [$field => $schema],
                required: [$field],
            ),
        ));
    }

    /**
     * Whether the client supplied any answer at all under `$key`, accepted
     * or otherwise.
     */
    private static function hasAnswer(ServerContext $context, string $key): bool
    {
        return isset($context->inputResponses[$key]);
    }

    /**
     * The named field of an accepted elicitation answer, or null while the
     * client has not supplied one.
     */
    private static function readAnswer(ServerContext $context, string $key, string $field): mixed
    {
        $response = $context->inputResponses[$key] ?? null;

        if (! $response instanceof ElicitResult || ElicitAction::Accept !== $response->action) {
            return null;
        }

        return $response->content[$field] ?? null;
    }
}
