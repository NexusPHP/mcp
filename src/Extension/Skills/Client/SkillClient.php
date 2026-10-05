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

namespace Nexus\Mcp\Extension\Skills\Client;

use Nexus\Assert\Assert;
use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Client\Exception\ServerCapabilityNotSupportedException;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Extension\Skills\Client\Exception\SkillVerificationFailedException;
use Nexus\Mcp\Extension\Skills\Frontmatter;
use Nexus\Mcp\Extension\Skills\Schema\Request\GetSkillRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\ListSkillsRequest;
use Nexus\Mcp\Extension\Skills\Schema\Request\ReadResourceDirectoryRequest;
use Nexus\Mcp\Extension\Skills\Schema\RequestParams\GetSkillRequestParams;
use Nexus\Mcp\Extension\Skills\Schema\RequestParams\ReadResourceDirectoryRequestParams;
use Nexus\Mcp\Extension\Skills\Schema\Result\ListSkillsResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\ReadResourceDirectoryResult;
use Nexus\Mcp\Extension\Skills\Schema\ResultResponse\GetSkillResultResponse;
use Nexus\Mcp\Extension\Skills\Schema\ResultResponse\ListSkillsResultResponse;
use Nexus\Mcp\Extension\Skills\Schema\ResultResponse\ReadResourceDirectoryResultResponse;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Schema\SkillResource;
use Nexus\Mcp\Extension\Skills\Skills;

/**
 * Skill-aware client surface over `Client::sendRequest()`.
 */
final readonly class SkillClient implements SkillClientInterface
{
    /**
     * @param int<1, max> $maxFileBytes Size in bytes above which a file is refused
     */
    public function __construct(
        private Client $client,
        private int $maxFileBytes = Skills::MAX_BYTES_PER_SKILL,
    ) {
        Assert::that($maxFileBytes)->isPositiveInt('Skill client file size limit must be a positive integer, {value} given.');
    }

    #[\Override]
    public function listSkills(?Cursor $cursor = null): ListSkillsResult
    {
        $this->readDeclaredSettings(ListSkillsRequest::getMethod());

        return $this->client->sendRequest(
            new ListSkillsRequest(
                id: $this->client->mintRequestId(),
                params: new PaginatedRequestParams(meta: $this->client->stampMeta(), cursor: $cursor),
            ),
            ListSkillsResultResponse::class,
        )->result;
    }

    #[\Override]
    public function getSkill(string $uri): Skill
    {
        $this->readDeclaredSettings(GetSkillRequest::getMethod());

        return $this->client->sendRequest(
            new GetSkillRequest(
                id: $this->client->mintRequestId(),
                params: new GetSkillRequestParams(uri: $uri, meta: $this->client->stampMeta()),
            ),
            GetSkillResultResponse::class,
        )->result->skill;
    }

    #[\Override]
    public function readSkillFile(Skill $skill, string $uri, ?array $inputResponses = null, ?string $requestState = null): InputRequiredResult|string
    {
        $listed = null;

        if (\is_array($skill->resources)) {
            $listed = $this->findListedResource($skill->resources, $uri)
                ?? throw new SkillVerificationFailedException($uri, 'is not listed in the manifest of the skill');

            if ($listed->size > $this->maxFileBytes) {
                throw new SkillVerificationFailedException($uri, \sprintf('is listed at %d bytes, above the limit of %d', $listed->size, $this->maxFileBytes));
            }
        }

        $result = $this->client->readResource($uri, $inputResponses, $requestState);

        if ($result instanceof InputRequiredResult) {
            return $result;
        }

        $bytes = $this->extractBytes($result, $uri);

        if ($this->maxFileBytes < \strlen($bytes)) {
            throw new SkillVerificationFailedException($uri, \sprintf('is %d bytes, above the limit of %d', \strlen($bytes), $this->maxFileBytes));
        }

        if (null !== $listed) {
            if (\strlen($bytes) !== $listed->size) {
                throw new SkillVerificationFailedException($uri, \sprintf('is %d bytes where the manifest lists %d', \strlen($bytes), $listed->size));
            }

            if (SkillResource::computeDigest($bytes) !== $listed->digest) {
                throw new SkillVerificationFailedException($uri, 'does not match the digest listed in the manifest');
            }
        }

        if ($uri === $skill->uri) {
            $this->verifyFrontmatter($skill, $bytes);
        }

        return $bytes;
    }

    #[\Override]
    public function readDirectory(string $uri, ?Cursor $cursor = null): ReadResourceDirectoryResult
    {
        $settings = $this->readDeclaredSettings(ReadResourceDirectoryRequest::getMethod());

        if (true !== ($settings[Skills::DIRECTORY_READ_SETTING] ?? null)) {
            throw new ServerCapabilityNotSupportedException(ReadResourceDirectoryRequest::getMethod());
        }

        return $this->client->sendRequest(
            new ReadResourceDirectoryRequest(
                id: $this->client->mintRequestId(),
                params: new ReadResourceDirectoryRequestParams(uri: $uri, meta: $this->client->stampMeta(), cursor: $cursor),
            ),
            ReadResourceDirectoryResultResponse::class,
        )->result;
    }

    /**
     * The settings declared by the server for the extension, discovering its capabilities first when none are held.
     *
     * @return array<string, mixed>
     *
     * @throws ServerCapabilityNotSupportedException
     */
    private function readDeclaredSettings(string $method): array
    {
        $capabilities = $this->client->getServerCapabilities() ?? $this->client->discover()->capabilities;

        return $capabilities->extensions[Skills::IDENTIFIER] ?? throw new ServerCapabilityNotSupportedException($method);
    }

    /**
     * @param list<SkillResource> $resources
     */
    private function findListedResource(array $resources, string $uri): ?SkillResource
    {
        foreach ($resources as $resource) {
            if ($resource->uri === $uri) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * @throws SkillVerificationFailedException
     */
    private function extractBytes(ReadResourceResult $result, string $uri): string
    {
        foreach ($result->contents as $contents) {
            if ($contents->uri !== $uri) {
                continue;
            }

            if ($contents instanceof TextResourceContents) {
                return $contents->text;
            }

            $bytes = base64_decode($contents->blob, true);

            if (false !== $bytes) {
                return $bytes;
            }
        }

        throw new SkillVerificationFailedException($uri, 'came back with no readable content');
    }

    /**
     * @throws SkillVerificationFailedException
     */
    private function verifyFrontmatter(Skill $skill, string $manifest): void
    {
        try {
            $frontmatter = Frontmatter::parse($manifest);
        } catch (\InvalidArgumentException) {
            throw new SkillVerificationFailedException($skill->uri, 'carries no readable frontmatter');
        }

        if (! Frontmatter::equals($frontmatter, $skill->frontmatter)) {
            throw new SkillVerificationFailedException($skill->uri, 'carries frontmatter that differs from the entry');
        }
    }
}
