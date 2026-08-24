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

namespace Nexus\Mcp\Tests\Client\Auth;

use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\EncryptedFileTokenStore;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(EncryptedFileTokenStore::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class EncryptedFileTokenStoreTest extends AbstractMcpTestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';

    /**
     * @var non-empty-string
     */
    private string $directory;

    /**
     * @var non-empty-string
     */
    private string $path;

    /**
     * @var non-empty-string
     */
    private string $key;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = \sprintf('%s/nexus-mcp-token-store-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        mkdir($this->directory, 0o700);
        $this->path = $this->directory.'/tokens.enc';
        $this->key = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    #[\Override]
    protected function tearDown(): void
    {
        chmod($this->directory, 0o700);
        $files = glob($this->directory.'/*');

        foreach (false === $files ? [] : $files as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    public function testATokenRoundTripsThroughTheFile(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken(
            value: 'opaque-token',
            issuer: 'https://issuer.example.com',
            expiresAt: 1_756_000_000,
            refreshToken: 'refresh-me',
            scopes: ['mcp:read', 'mcp:write'],
        ));

        $token = $store->read(self::RESOURCE);

        self::assertInstanceOf(AccessToken::class, $token);
        self::assertSame('opaque-token', $token->value);
        self::assertSame('https://issuer.example.com', $token->issuer);
        self::assertSame(1_756_000_000, $token->expiresAt);
        self::assertSame('refresh-me', $token->refreshToken);
        self::assertSame(['mcp:read', 'mcp:write'], $token->scopes);
    }

    public function testTheFileIsEncrypted(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));

        $raw = file_get_contents($this->path);

        self::assertIsString($raw);
        self::assertStringNotContainsString('opaque-token', $raw);
    }

    public function testTheFileIsOwnerOnly(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('Windows ACLs ignore POSIX modes, so the owner-only bits cannot be read back.');
        }

        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));

        self::assertSame(0o600, fileperms($this->path) & 0o777);
    }

    public function testATokenWithNoLifetimeAndNoRefreshRoundTrips(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));

        $token = $store->read(self::RESOURCE);

        self::assertInstanceOf(AccessToken::class, $token);
        self::assertNull($token->expiresAt);
        self::assertNull($token->refreshToken);
        self::assertSame([], $token->scopes);
    }

    public function testASecondInstanceReadsWhatTheFirstWrote(): void
    {
        (new EncryptedFileTokenStore($this->path, $this->key))
            ->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'))
        ;

        $token = (new EncryptedFileTokenStore($this->path, $this->key))->read(self::RESOURCE);

        self::assertInstanceOf(AccessToken::class, $token);
        self::assertSame('opaque-token', $token->value);
    }

    public function testARewriteReplacesTheStoredToken(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('stale', 'https://issuer.example.com'));
        $store->write(self::RESOURCE, new AccessToken('fresh', 'https://issuer.example.com'));

        $token = $store->read(self::RESOURCE);

        self::assertInstanceOf(AccessToken::class, $token);
        self::assertSame('fresh', $token->value);
    }

    public function testAMissingFileReadsAsNoToken(): void
    {
        self::assertNull((new EncryptedFileTokenStore($this->path, $this->key))->read(self::RESOURCE));
    }

    public function testAnUnknownResourceReadsAsNoToken(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));

        self::assertNull($store->read('https://other.example.com/mcp'));
    }

    public function testForgettingOneResourceKeepsTheOthers(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));
        $store->write('https://other.example.com/mcp', new AccessToken('other-token', 'https://issuer.example.com'));

        $store->forget(self::RESOURCE);

        self::assertNull($store->read(self::RESOURCE));
        self::assertInstanceOf(AccessToken::class, $store->read('https://other.example.com/mcp'));
    }

    public function testForgettingTheLastResourceRemovesTheFile(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));

        $store->forget(self::RESOURCE);

        self::assertFileDoesNotExist($this->path);
    }

    public function testForgettingWithNoFileIsANoOp(): void
    {
        (new EncryptedFileTokenStore($this->path, $this->key))->forget(self::RESOURCE);

        self::assertFileDoesNotExist($this->path);
    }

    public function testAFileWrittenWithAnotherKeyIsRefused(): void
    {
        (new EncryptedFileTokenStore($this->path, random_bytes(32)))
            ->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'))
        ;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Encrypted token store file "%s" is not a token map written with the configured key.',
            $this->path,
        ));

        (new EncryptedFileTokenStore($this->path, $this->key))->read(self::RESOURCE);
    }

    public function testAnUnreadableFileRefusesTheRead(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('Windows ACLs ignore POSIX modes, so the refusal cannot be provoked with chmod.');
        }

        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));
        chmod($this->path, 0o000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf('Encrypted token store could not read "%s".', $this->path));

        $store->read(self::RESOURCE);
    }

    public function testAGarbageFileIsRefused(): void
    {
        file_put_contents($this->path, 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Encrypted token store file "%s" is not a token map written with the configured key.',
            $this->path,
        ));

        (new EncryptedFileTokenStore($this->path, $this->key))->read(self::RESOURCE);
    }

    public function testATamperedFileIsRefused(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));

        $raw = file_get_contents($this->path);
        self::assertIsString($raw);
        $raw[\strlen($raw) - 1] = $raw[\strlen($raw) - 1] === "\x00" ? "\x01" : "\x00";
        file_put_contents($this->path, $raw);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Encrypted token store file "%s" is not a token map written with the configured key.',
            $this->path,
        ));

        $store->read(self::RESOURCE);
    }

    /**
     * @param array<array-key, mixed>|string $payload
     */
    #[DataProvider('provideAPayloadOffTheStoresShapeIsRefusedCases')]
    public function testAPayloadOffTheStoresShapeIsRefused(array|string $payload): void
    {
        $this->encryptForeignPayload(\is_string($payload) ? $payload : json_encode($payload, \JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Encrypted token store file "%s" is not a token map written with the configured key.',
            $this->path,
        ));

        (new EncryptedFileTokenStore($this->path, $this->key))->read(self::RESOURCE);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>|string}>
     */
    public static function provideAPayloadOffTheStoresShapeIsRefusedCases(): iterable
    {
        yield 'not a JSON array' => ['"scalar"'];

        yield 'entry not a map' => [[self::RESOURCE => 'not-a-map']];

        yield 'value not a string' => [[self::RESOURCE => ['value' => 1, 'issuer' => 'https://issuer.example.com']]];

        yield 'issuer not a string' => [[self::RESOURCE => ['value' => 'opaque-token', 'issuer' => 1]]];

        yield 'expiry not an int' => [[self::RESOURCE => ['value' => 'opaque-token', 'issuer' => 'https://issuer.example.com', 'expiresAt' => 'soon']]];

        yield 'refresh token not a string' => [[self::RESOURCE => ['value' => 'opaque-token', 'issuer' => 'https://issuer.example.com', 'refreshToken' => 1]]];

        yield 'scopes not a list' => [[self::RESOURCE => ['value' => 'opaque-token', 'issuer' => 'https://issuer.example.com', 'scopes' => 'mcp:read']]];

        yield 'scope not a string' => [[self::RESOURCE => ['value' => 'opaque-token', 'issuer' => 'https://issuer.example.com', 'scopes' => [1]]]];

        yield 'scope empty' => [[self::RESOURCE => ['value' => 'opaque-token', 'issuer' => 'https://issuer.example.com', 'scopes' => ['']]]];
    }

    public function testAnUnwritableDirectoryRefusesTheWrite(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('Windows ACLs ignore POSIX modes, so the refusal cannot be provoked with chmod.');
        }

        $store = new EncryptedFileTokenStore($this->path, $this->key);
        chmod($this->directory, 0o500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf('Encrypted token store could not write "%s".', $this->path));

        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));
    }

    public function testAFailedReplaceLeavesNoTempFileBehind(): void
    {
        mkdir($this->path);
        $store = new EncryptedFileTokenStore($this->path, $this->key);

        try {
            $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));
            self::fail('The write should have been refused.');
        } catch (RuntimeException $e) {
            self::assertSame(\sprintf('Encrypted token store could not write "%s".', $this->path), $e->getMessage());
        }

        self::assertSame([], glob($this->directory.'/*.tmp'));
    }

    public function testATokenThatCannotBeEncodedRefusesTheWrite(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);

        try {
            $store->write(self::RESOURCE, new AccessToken("\xB1\x31", 'https://issuer.example.com'));
            self::fail('The write should have been refused.');
        } catch (RuntimeException $e) {
            self::assertSame(\sprintf('Encrypted token store could not write "%s".', $this->path), $e->getMessage());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testAnUndeletableFileRefusesTheForget(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('Windows ACLs ignore POSIX modes, so the refusal cannot be provoked with chmod.');
        }

        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->write(self::RESOURCE, new AccessToken('opaque-token', 'https://issuer.example.com'));
        chmod($this->directory, 0o500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf('Encrypted token store could not write "%s".', $this->path));

        $store->forget(self::RESOURCE);
    }

    public function testAnEmptyPathIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Encrypted token store path must be a non-empty string.');

        // @phpstan-ignore argument.type (the empty path exercises the runtime guard)
        new EncryptedFileTokenStore('', $this->key);
    }

    public function testAKeyOffThirtyTwoBytesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Encrypted token store key must be exactly 32 bytes long, 5 given.');

        new EncryptedFileTokenStore($this->path, 'short');
    }

    private function encryptForeignPayload(string $payload): void
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($payload, '', $nonce, $this->key);

        file_put_contents($this->path, $nonce.$cipher);
    }
}
