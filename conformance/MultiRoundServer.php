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

use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Elicitation\BooleanSchema;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Server\Attribute\AsPrompt;
use Nexus\Mcp\Server\Attribute\AsTool;
use Nexus\Mcp\Server\RequestStateSigner;
use Nexus\Mcp\Server\ServerContext;

/**
 * The multi-round-trip half of the conformance fixture: every method here
 * answers over two or more rounds.
 *
 * Each method returns an `InputRequiredResult` while it still needs input from the
 * client, and a complete result once it has what it asked for. A client resends only
 * the newest round's `inputResponses`, so anything a later round needs travels in the
 * opaque `requestState` rather than in the answers.
 */
final class MultiRoundServer
{
    use ElicitationHelpers;

    /**
     * A per-process signing key.
     */
    private readonly RequestStateSigner $states;

    public function __construct()
    {
        $this->states = RequestStateSigner::generate();
    }

    #[AsTool(name: 'test_input_required_result_elicitation', description: 'Asks for a name, then greets it.')]
    public function elicitation(ServerContext $context): CallToolResult|InputRequiredResult
    {
        $name = self::readAnswer($context, 'user_name', 'name');

        if (! is_string($name) || '' === $name) {
            return self::ask('user_name', 'What is your name?', 'name');
        }

        return new CallToolResult(content: [new TextContent(text: sprintf('Hello, %s!', $name))]);
    }

    #[AsTool(name: 'test_input_required_result_request_state', description: 'Carries an opaque continuation token across the round trip.')]
    public function requestState(ServerContext $context): CallToolResult|InputRequiredResult
    {
        $state = $context->requestState;

        if (null === $state) {
            return self::ask('confirm', 'Please confirm', 'ok', new BooleanSchema(), $this->states->sign('confirm'));
        }

        if ($this->states->verify($state) === null) {
            throw new InvalidParamsException($context->requestId, 'The "requestState" failed its integrity check.');
        }

        return new CallToolResult(content: [new TextContent(text: 'state-ok')]);
    }

    #[AsTool(name: 'test_input_required_result_multi_round', description: 'Asks two questions in sequence, evolving the continuation token.')]
    public function multiRound(ServerContext $context): CallToolResult|InputRequiredResult
    {
        $round = null === $context->requestState ? null : $this->states->verify($context->requestState);

        if (null === $round) {
            return self::ask('step1', 'Step 1: What is your name?', 'name', state: $this->states->sign('round-1'));
        }

        if (str_starts_with($round, 'round-1')) {
            $name = self::readAnswer($context, 'step1', 'name');
            // The client will not resend this answer, so the next round carries it in the state.
            $carried = is_string($name) ? $name : 'friend';

            return self::ask('step2', 'Step 2: Which colour do you like best?', 'color', state: $this->states->sign('round-2|'.$carried));
        }

        $color = self::readAnswer($context, 'step2', 'color');

        return new CallToolResult(content: [new TextContent(text: sprintf(
            '%s likes %s.',
            substr($round, strlen('round-2|')),
            is_string($color) ? $color : 'nothing',
        ))]);
    }

    #[AsTool(name: 'test_input_required_result_tampered_state', description: 'Refuses a continuation token that fails its integrity check.')]
    public function tamperedState(ServerContext $context): CallToolResult|InputRequiredResult
    {
        $state = $context->requestState;

        if (null === $state) {
            return self::ask('confirm', 'Please confirm', 'ok', new BooleanSchema(), $this->states->sign('confirm'));
        }

        if ($this->states->verify($state) === null) {
            throw new InvalidParamsException($context->requestId, 'The "requestState" failed its integrity check.');
        }

        return new CallToolResult(content: [new TextContent(text: 'State verified.')]);
    }

    /**
     * `InputRequiredResult` is not tool-only: the same shape answers `prompts/get`.
     */
    #[AsPrompt(name: 'test_input_required_result_prompt', description: 'Asks for context, then renders a message using it.')]
    public function prompt(ServerContext $context): GetPromptResult|InputRequiredResult
    {
        $userContext = self::readAnswer($context, 'user_context', 'context');

        if (! is_string($userContext) || '' === $userContext) {
            return self::ask('user_context', 'What context should the prompt use?', 'context');
        }

        return new GetPromptResult(messages: [
            new PromptMessage(role: Role::User, content: new TextContent(text: sprintf('Context: %s', $userContext))),
        ]);
    }
}
