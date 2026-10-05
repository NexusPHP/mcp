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

namespace Nexus\Mcp\Tests\Extension\Skills\Client;

use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Exception\ServerCapabilityNotSupportedException;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Extension\Skills\Client\Exception\SkillVerificationFailedException;
use Nexus\Mcp\Extension\Skills\Client\SkillClient;
use Nexus\Mcp\Extension\Skills\Client\SkillsClientExtension;
use Nexus\Mcp\Extension\Skills\Schema\Result\ListSkillsResult;
use Nexus\Mcp\Extension\Skills\Schema\Result\ReadResourceDirectoryResult;
use Nexus\Mcp\Extension\Skills\Schema\Skill;
use Nexus\Mcp\Extension\Skills\Schema\SkillResource;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(SkillClient::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillClientTest extends AbstractMcpTestCase
{
    private const string ROOT = 'skill://refunds';
    private const string MANIFEST_URI = self::ROOT.'/SKILL.md';
    private const string MANIFEST = "---\nname: refunds\ndescription: Process refunds.\nlicense: MIT\n---\n\n# Refunds\n";
    private const array FRONTMATTER = ['license' => 'MIT', 'description' => 'Process refunds.', 'name' => 'refunds'];
    private const array SKILLS_DECLARED = ['resources' => [], 'extensions' => ['io.modelcontextprotocol/skills' => ['directoryRead' => true]]];
    private const array ENTRY = [
        'uri' => self::MANIFEST_URI,
        'frontmatter' => ['name' => 'refunds', 'description' => 'Process refunds.'],
        'resources' => 'dynamic',
    ];

    public function testListSkillsDiscoversTheServerOnceThenSendsTheCursor(): void
    {
        [$skills, $transport] = $this->buildSkillClient();
        $page = ['resultType' => 'complete', 'skills' => [self::ENTRY], 'nextCursor' => 'next', 'ttlMs' => 0, 'cacheScope' => 'private'];

        $first = async(static fn(): ListSkillsResult => $skills->listSkills());
        $this->respondToDiscover($transport, 0, self::SKILLS_DECLARED);
        $sent = $this->respond($transport, 1, 'skills/list', $page);

        $result = $first->await();
        self::assertArrayNotHasKey('cursor', self::paramsOf($sent));
        self::assertInstanceOf(ListSkillsResult::class, $result);
        self::assertSame(['refunds'], array_map(static fn(Skill $skill): string => $skill->frontmatter['name'], $result->skills));

        $second = async(static fn(): ListSkillsResult => $skills->listSkills(new Cursor('next')));
        $sent = $this->respond($transport, 2, 'skills/list', $page);

        $result = $second->await();
        self::assertSame('next', self::paramsOf($sent)['cursor'] ?? null);
        self::assertInstanceOf(ListSkillsResult::class, $result);
        self::assertSame('next', $result->nextCursor?->cursor);
        self::assertCount(3, $transport->sent);
    }

    public function testAnEnabledClientExtensionIsAdvertisedOnTheRequest(): void
    {
        [$skills, $transport] = $this->buildSkillClient(enableExtension: true);

        $call = async(static fn(): Skill => $skills->getSkill(self::MANIFEST_URI));
        $this->respondToDiscover($transport, 0, self::SKILLS_DECLARED);
        $sent = $this->respond($transport, 1, 'skills/get', ['resultType' => 'complete', 'skill' => self::ENTRY, 'ttlMs' => 0, 'cacheScope' => 'private']);

        $entry = $call->await();
        self::assertStringContainsString(
            '"extensions":{"io.modelcontextprotocol/skills":{}}',
            json_encode($sent, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
        self::assertInstanceOf(Skill::class, $entry);
    }

    public function testGetSkillSendsTheUriAndReturnsTheEntry(): void
    {
        [$skills, $transport] = $this->buildSkillClient();

        $call = async(static fn(): Skill => $skills->getSkill(self::MANIFEST_URI));
        $this->respondToDiscover($transport, 0, self::SKILLS_DECLARED);
        $sent = $this->respond($transport, 1, 'skills/get', ['resultType' => 'complete', 'skill' => self::ENTRY, 'ttlMs' => 0, 'cacheScope' => 'private']);

        $entry = $call->await();
        self::assertSame(self::MANIFEST_URI, self::paramsOf($sent)['uri'] ?? null);
        self::assertInstanceOf(Skill::class, $entry);
        self::assertSame(self::ENTRY, $entry->toArray());
    }

    public function testReadDirectorySendsTheUriAndTheCursor(): void
    {
        [$skills, $transport] = $this->buildSkillClient();

        $call = async(static fn(): ReadResourceDirectoryResult => $skills->readDirectory(self::ROOT, new Cursor('next')));
        $this->respondToDiscover($transport, 0, self::SKILLS_DECLARED);
        $sent = $this->respond($transport, 1, 'resources/directory/read', [
            'resultType' => 'complete',
            'resources' => [['name' => 'refunds', 'uri' => self::MANIFEST_URI]],
        ]);

        $result = $call->await();
        self::assertSame(self::ROOT, self::paramsOf($sent)['uri'] ?? null);
        self::assertSame('next', self::paramsOf($sent)['cursor'] ?? null);
        self::assertInstanceOf(ReadResourceDirectoryResult::class, $result);
        self::assertSame([self::MANIFEST_URI], array_map(static fn(Resource $resource): string => $resource->uri, $result->resources));
    }

    /**
     * @param \Closure(SkillClient): mixed $call
     * @param array<string, mixed>         $capabilities
     */
    #[DataProvider('provideAMethodTheServerDidNotDeclareIsRefusedBeforeItIsSentCases')]
    public function testAMethodTheServerDidNotDeclareIsRefusedBeforeItIsSent(\Closure $call, array $capabilities, string $method): void
    {
        [$skills, $transport] = $this->buildSkillClient();

        $pending = async(static fn(): mixed => $call($skills));
        $this->respondToDiscover($transport, 0, $capabilities);

        try {
            $pending->await();
            self::fail('The call was not refused.');
        } catch (ServerCapabilityNotSupportedException $e) {
            self::assertSame(
                \sprintf('Request method "%s" requires a server capability that was not advertised by server/discover. Check getServerCapabilities() before calling.', $method),
                $e->getMessage(),
            );
            self::assertCount(1, $transport->sent);
        }
    }

    /**
     * @return iterable<string, array{\Closure(SkillClient): mixed, array<string, mixed>, string}>
     */
    public static function provideAMethodTheServerDidNotDeclareIsRefusedBeforeItIsSentCases(): iterable
    {
        $list = static fn(SkillClient $skills): mixed => $skills->listSkills();
        $get = static fn(SkillClient $skills): mixed => $skills->getSkill(self::MANIFEST_URI);
        $read = static fn(SkillClient $skills): mixed => $skills->readDirectory(self::ROOT);
        $declare = static fn(array $settings): array => ['extensions' => ['io.modelcontextprotocol/skills' => $settings]];

        yield 'skills/list without the extension' => [$list, ['resources' => []], 'skills/list'];

        yield 'skills/get without the extension' => [$get, ['resources' => []], 'skills/get'];

        yield 'directory read without the extension' => [$read, ['resources' => []], 'resources/directory/read'];

        yield 'directory read left undeclared' => [$read, $declare([]), 'resources/directory/read'];

        yield 'directory read declared off' => [$read, $declare(['directoryRead' => false]), 'resources/directory/read'];

        yield 'directory read declared with a value that is not true' => [$read, $declare(['directoryRead' => 'yes']), 'resources/directory/read'];
    }

    public function testAFileOfADynamicSkillIsReturnedAsServed(): void
    {
        [$skills, $transport] = $this->buildSkillClient();
        $uri = self::ROOT.'/notes.md';
        $entry = new Skill(self::MANIFEST_URI, self::FRONTMATTER, 'dynamic');

        $call = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, $uri));
        $sent = $this->respond($transport, 0, 'resources/read', self::textRead($uri, 'notes'));

        self::assertSame($uri, self::paramsOf($sent)['uri'] ?? null);
        self::assertSame('notes', $call->await());
    }

    public function testAListedFileThatMatchesItsManifestIsReturned(): void
    {
        [$skills, $transport] = $this->buildSkillClient();
        $uri = self::ROOT.'/logo.bin';
        $bytes = "\x89PNG\0\xff";
        $entry = self::entryListing($uri, $bytes);

        $call = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, $uri));
        $this->respond($transport, 0, 'resources/read', self::read([
            ['uri' => self::ROOT.'/other.bin', 'blob' => base64_encode('other')],
            ['uri' => $uri, 'blob' => base64_encode($bytes)],
        ]));

        self::assertSame($bytes, $call->await());
    }

    public function testAFileTheManifestDoesNotListIsRefusedWithoutBeingRead(): void
    {
        [$skills, $transport] = $this->buildSkillClient();
        $entry = self::entryListing(self::ROOT.'/notes.md', 'notes');

        try {
            $skills->readSkillFile($entry, self::ROOT.'/extra.md');
            self::fail('The file was not refused.');
        } catch (SkillVerificationFailedException $e) {
            self::assertSame(
                'Skill file "skill://refunds/extra.md" failed verification: it is not listed in the manifest of the skill.',
                $e->getMessage(),
            );
            self::assertSame([], $transport->sent);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    #[DataProvider('provideAListedFileThatDoesNotMatchIsRefusedCases')]
    public function testAListedFileThatDoesNotMatchIsRefused(array $result, string $reason): void
    {
        [$skills, $transport] = $this->buildSkillClient();
        $uri = self::ROOT.'/notes.md';
        $entry = self::entryListing($uri, 'notes!');

        $call = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, $uri));
        $this->respond($transport, 0, 'resources/read', $result);

        $this->expectException(SkillVerificationFailedException::class);
        $this->expectExceptionMessageIs(\sprintf('Skill file "skill://refunds/notes.md" failed verification: it %s.', $reason));

        $call->await();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideAListedFileThatDoesNotMatchIsRefusedCases(): iterable
    {
        $uri = self::ROOT.'/notes.md';

        yield 'shorter than listed' => [self::textRead($uri, 'notes'), 'is 5 bytes where the manifest lists 6'];

        yield 'longer than listed, the size being checked first' => [self::textRead($uri, 'notes!!'), 'is 7 bytes where the manifest lists 6'];

        yield 'same size, other bytes' => [self::textRead($uri, 'notes?'), 'does not match the digest listed in the manifest'];

        yield 'no content under the URI' => [self::textRead(self::ROOT.'/other.md', 'notes!'), 'came back with no readable content'];

        yield 'no content at all' => [self::read([]), 'came back with no readable content'];

        yield 'a blob that is not base64' => [self::read([['uri' => $uri, 'blob' => '***']]), 'came back with no readable content'];
    }

    public function testARequestForInputIsHandedBackAndItsAnswerReachesTheServer(): void
    {
        [$skills, $transport] = $this->buildSkillClient();
        $uri = self::ROOT.'/notes.md';
        $entry = self::entryListing($uri, 'notes');

        $first = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, $uri));
        $this->respond($transport, 0, 'resources/read', ['resultType' => 'input_required', 'requestState' => 'tok']);
        $pending = $first->await();

        self::assertInstanceOf(InputRequiredResult::class, $pending);
        self::assertSame('tok', $pending->requestState);

        $second = async(static fn(): InputRequiredResult|string => $skills->readSkillFile(
            $entry,
            $uri,
            ['confirm' => new ElicitResult(action: ElicitAction::Accept, content: ['ok' => true])],
            $pending->requestState,
        ));
        $sent = $this->respond($transport, 1, 'resources/read', self::textRead($uri, 'notes'));

        self::assertSame('notes', $second->await());
        self::assertStringContainsString(
            '"inputResponses":{"confirm":{"action":"accept","content":{"ok":true}}},"requestState":"tok"',
            json_encode($sent, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testAManifestWhoseFrontmatterMatchesTheEntryIsReturned(): void
    {
        [$skills, $transport] = $this->buildSkillClient();
        $entry = new Skill(self::MANIFEST_URI, self::FRONTMATTER, [
            new SkillResource(self::MANIFEST_URI, SkillResource::computeDigest(self::MANIFEST), \strlen(self::MANIFEST)),
        ]);

        $call = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, self::MANIFEST_URI));
        $this->respond($transport, 0, 'resources/read', self::textRead(self::MANIFEST_URI, self::MANIFEST));

        self::assertSame(self::MANIFEST, $call->await());
    }

    #[DataProvider('provideAManifestWhoseFrontmatterDoesNotMatchTheEntryIsRefusedCases')]
    public function testAManifestWhoseFrontmatterDoesNotMatchTheEntryIsRefused(string $served, string $reason): void
    {
        [$skills, $transport] = $this->buildSkillClient();
        $entry = new Skill(self::MANIFEST_URI, self::FRONTMATTER, 'dynamic');

        $call = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, self::MANIFEST_URI));
        $this->respond($transport, 0, 'resources/read', self::textRead(self::MANIFEST_URI, $served));

        $this->expectException(SkillVerificationFailedException::class);
        $this->expectExceptionMessageIs(\sprintf('Skill file "skill://refunds/SKILL.md" failed verification: it %s.', $reason));

        $call->await();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAManifestWhoseFrontmatterDoesNotMatchTheEntryIsRefusedCases(): iterable
    {
        yield 'a changed field' => [
            "---\nname: refunds\ndescription: Wire the money elsewhere.\nlicense: MIT\n---\n",
            'carries frontmatter that differs from the entry',
        ];

        yield 'an added field' => [
            "---\nname: refunds\ndescription: Process refunds.\nlicense: MIT\nallowed-tools: Bash\n---\n",
            'carries frontmatter that differs from the entry',
        ];

        yield 'no frontmatter' => ["# Refunds\n", 'carries no readable frontmatter'];
    }

    public function testAListedFileOverTheSizeLimitIsRefusedWithoutBeingRead(): void
    {
        [$skills, $transport] = $this->buildSkillClient(maxFileBytes: 6);
        $uri = self::ROOT.'/notes.md';
        $entry = self::entryListing($uri, 'notes!!');

        try {
            $skills->readSkillFile($entry, $uri);
            self::fail('The file was not refused.');
        } catch (SkillVerificationFailedException $e) {
            self::assertSame(
                'Skill file "skill://refunds/notes.md" failed verification: it is listed at 7 bytes, above the limit of 6.',
                $e->getMessage(),
            );
            self::assertSame([], $transport->sent);
        }
    }

    public function testAListedFileAtTheSizeLimitIsRead(): void
    {
        [$skills, $transport] = $this->buildSkillClient(maxFileBytes: 6);
        $uri = self::ROOT.'/notes.md';
        $entry = self::entryListing($uri, 'notes!');

        $call = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, $uri));
        $this->respond($transport, 0, 'resources/read', self::textRead($uri, 'notes!'));

        self::assertSame('notes!', $call->await());
    }

    public function testAFileOfADynamicSkillAtTheSizeLimitIsRead(): void
    {
        [$skills, $transport] = $this->buildSkillClient(maxFileBytes: 6);
        $uri = self::ROOT.'/notes.md';
        $entry = new Skill(self::MANIFEST_URI, self::FRONTMATTER, 'dynamic');

        $call = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, $uri));
        $this->respond($transport, 0, 'resources/read', self::textRead($uri, 'notes!'));

        self::assertSame('notes!', $call->await());
    }

    public function testAFileOfADynamicSkillOverTheSizeLimitIsRefused(): void
    {
        [$skills, $transport] = $this->buildSkillClient(maxFileBytes: 6);
        $uri = self::ROOT.'/notes.md';
        $entry = new Skill(self::MANIFEST_URI, self::FRONTMATTER, 'dynamic');

        $call = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, $uri));
        $this->respond($transport, 0, 'resources/read', self::textRead($uri, 'notes!!'));

        $this->expectException(SkillVerificationFailedException::class);
        $this->expectExceptionMessageIs('Skill file "skill://refunds/notes.md" failed verification: it is 7 bytes, above the limit of 6.');

        $call->await();
    }

    public function testANonPositiveSizeLimitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Skill client file size limit must be a positive integer, 0 given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new SkillClient((new ClientBuilder())->setClientInfo('demo', '1.0.0')->build(), 0);
    }

    public function testASupportingFileIsNotHeldToTheFrontmatter(): void
    {
        [$skills, $transport] = $this->buildSkillClient();
        $uri = self::ROOT.'/notes.md';
        $entry = new Skill(self::MANIFEST_URI, self::FRONTMATTER, 'dynamic');

        $call = async(static fn(): InputRequiredResult|string => $skills->readSkillFile($entry, $uri));
        $this->respond($transport, 0, 'resources/read', self::textRead($uri, "# Notes\n"));

        self::assertSame("# Notes\n", $call->await());
    }

    /**
     * @param null|int<1, max> $maxFileBytes
     *
     * @return array{SkillClient, RecordingTransport}
     */
    private function buildSkillClient(bool $enableExtension = false, ?int $maxFileBytes = null): array
    {
        $builder = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->setRequestTimeout(0.5);

        if ($enableExtension) {
            $builder->enableExtension(new SkillsClientExtension());
        }

        $client = $builder->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        return [null === $maxFileBytes ? new SkillClient($client) : new SkillClient($client, $maxFileBytes), $transport];
    }

    /**
     * @param non-empty-string $uri
     */
    private static function entryListing(string $uri, string $bytes): Skill
    {
        return new Skill(self::MANIFEST_URI, self::FRONTMATTER, [
            new SkillResource(self::MANIFEST_URI, SkillResource::computeDigest(self::MANIFEST), \strlen(self::MANIFEST)),
            new SkillResource($uri, SkillResource::computeDigest($bytes), \strlen($bytes)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function textRead(string $uri, string $text): array
    {
        return self::read([['uri' => $uri, 'text' => $text]]);
    }

    /**
     * @param list<array<string, string>> $contents
     *
     * @return array<string, mixed>
     */
    private static function read(array $contents): array
    {
        return ['resultType' => 'complete', 'contents' => $contents, 'ttlMs' => 0, 'cacheScope' => 'private'];
    }

    /**
     * @param JsonRpcRequest<non-empty-string> $request
     *
     * @return array<array-key, mixed>
     */
    private static function paramsOf(JsonRpcRequest $request): array
    {
        $params = $request->toArray()['params'] ?? null;
        self::assertIsArray($params);

        return $params;
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    private function respondToDiscover(RecordingTransport $transport, int $index, array $capabilities): void
    {
        $this->respond($transport, $index, 'server/discover', [
            '_meta' => [ResultMetaObject::SERVER_INFO_KEY => ['name' => 'srv', 'version' => '1']],
            'supportedVersions' => [ProtocolVersion::LATEST_VERSION],
            'protocolVersion' => ProtocolVersion::LATEST_VERSION,
            'capabilities' => $capabilities,
            'ttlMs' => 0,
            'cacheScope' => 'private',
        ]);
    }

    /**
     * Answers the request sent at the index once it is away.
     *
     * @param array<string, mixed> $result
     *
     * @return JsonRpcRequest<non-empty-string>
     */
    private function respond(RecordingTransport $transport, int $index, string $method, array $result): JsonRpcRequest
    {
        $deadline = hrtime(true) + 2_000_000_000;

        while (! \array_key_exists($index, $transport->sent)) {
            if ($deadline <= hrtime(true)) {
                self::fail(\sprintf('Timed out waiting for send %d.', $index));
            }

            delay(0.001);
        }

        self::assertArrayHasKey($index, $transport->sent);
        $sent = $transport->sent[$index]['message'];
        self::assertInstanceOf(JsonRpcRequest::class, $sent);
        self::assertSame($method, $sent::getMethod());

        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $sent->id->id, 'result' => $result]);

        return $sent;
    }
}
