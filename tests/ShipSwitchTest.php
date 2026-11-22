<?php

declare(strict_types=1);

namespace ShipSwitch\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipSwitch\ShipSwitch;
use ShipSwitch\ShipSwitchException;

/** Stands in for the HTTP transport and records what was sent. */
final class FakeApi
{
    /** @var list<array{url: string, headers: array<string, string>, body: mixed, timeout: float}> */
    public array $calls = [];

    public function __construct(
        public array $flags = [],
        public int $status = 200,
        public ?string $body = null,
        public ?\Throwable $error = null,
    ) {
    }

    public function __invoke(string $url, array $headers, string $body, float $timeout): array
    {
        $this->calls[] = [
            'url' => $url,
            'headers' => $headers,
            'body' => json_decode($body, true),
            'raw' => $body,
            'timeout' => $timeout,
        ];
        if ($this->error !== null) {
            throw $this->error;
        }
        if ($this->body !== null) {
            return [$this->status, $this->body];
        }
        if (str_ends_with($url, '/errors')) {
            return [204, ''];
        }
        return [$this->status, json_encode(['flags' => (object) $this->flags])];
    }
}

/** The two methods of a PSR-16 cache that the SDK uses. */
final class ArrayCache
{
    public array $items = [];
    public bool $broken = false;

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->broken) {
            throw new \RuntimeException('cache down');
        }
        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if ($this->broken) {
            throw new \RuntimeException('cache down');
        }
        if (preg_match('/[{}()\/\\\\@:]/', $key)) {
            throw new \InvalidArgumentException("Illegal PSR-16 key: $key");
        }
        $this->items[$key] = $value;
        return true;
    }
}

final class ShipSwitchTest extends TestCase
{
    private function client(FakeApi $api, mixed ...$options): ShipSwitch
    {
        return new ShipSwitch('ss_client_test', 'https://flags.test/', ...$options, transport: $api);
    }

    public function testSendsTheKeyAndTheContext(): void
    {
        $api = new FakeApi(['new-checkout' => true]);

        self::assertTrue($this->client($api)->isEnabled('new-checkout', ['userId' => 'u1', 'plan' => null]));

        $call = $api->calls[0];
        self::assertSame('https://flags.test/api/v1/client/evaluate', $call['url']);
        self::assertSame('Bearer ss_client_test', $call['headers']['Authorization']);
        // Attributes without a value are not sent.
        self::assertSame(['context' => ['userId' => 'u1']], $call['body']);
        self::assertSame(3.0, $call['timeout']);
    }

    public function testAnEmptyContextIsSentAsAnObject(): void
    {
        $api = new FakeApi();
        $this->client($api)->getAllFlags();
        self::assertSame('{"context":{}}', $api->calls[0]['raw']);
    }

    public function testRequiresAnApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShipSwitch('');
    }

    public function testUnknownFlagsGetTheDefault(): void
    {
        $flags = $this->client(new FakeApi());
        self::assertFalse($flags->isEnabled('nope'));
        self::assertTrue($flags->isEnabled('nope', default: true));
        self::assertSame(20, $flags->getValue('nope', 20));
    }

    public function testAValueOfAnotherTypeGetsTheDefault(): void
    {
        $flags = $this->client(new FakeApi([
            'limit' => 'many', 'ratio' => 2, 'on' => 1, 'theme' => ['dark' => true], 'tags' => ['a'],
        ]));
        self::assertSame(20, $flags->getValue('limit', 20));
        self::assertSame(2, $flags->getValue('ratio', 1.5));
        self::assertFalse($flags->isEnabled('on'));
        self::assertSame(['dark' => true], $flags->getValue('theme', []));
        self::assertSame(['a'], $flags->getValue('tags', []));
        self::assertSame('many', $flags->getValue('limit', null));
        self::assertSame('x', $flags->getValue('theme', 'x'));
    }

    public function testOneRequestPerContextWhileFresh(): void
    {
        $api = new FakeApi(['a' => true]);
        $flags = $this->client($api);
        $flags->isEnabled('a', ['userId' => 'u1', 'plan' => 'pro']);
        $flags->isEnabled('a', ['plan' => 'pro', 'userId' => 'u1']); // same, reordered
        self::assertCount(1, $api->calls);
        $flags->isEnabled('a', ['userId' => 'u2']);
        self::assertCount(2, $api->calls);
    }

    public function testAsksAgainOnceStale(): void
    {
        $api = new FakeApi(['a' => true]);
        $flags = $this->client($api, cacheTtl: 0.0);
        $flags->isEnabled('a');
        $api->flags = ['a' => false];
        self::assertFalse($flags->isEnabled('a'));
        self::assertCount(2, $api->calls);
    }

    public function testRefreshIgnoresTheCacheAndUpdatesIt(): void
    {
        $api = new FakeApi(['a' => true]);
        $flags = $this->client($api);
        $flags->isEnabled('a');
        $api->flags = ['a' => false];
        self::assertSame(['a' => false], $flags->refresh());
        self::assertFalse($flags->isEnabled('a'));
        self::assertCount(2, $api->calls);
    }

    public function testASharedCacheSparesTheNextRequest(): void
    {
        $api = new FakeApi(['a' => true]);
        $cache = new ArrayCache();
        // Two clients: two PHP requests of the same application.
        self::assertTrue($this->client($api, cache: $cache)->isEnabled('a', ['userId' => 'u1']));
        self::assertTrue($this->client($api, cache: $cache)->isEnabled('a', ['userId' => 'u1']));
        self::assertCount(1, $api->calls);
        self::assertCount(1, $cache->items);
    }

    public function testASharedCacheKeepsTheLastKnownValuesForOutages(): void
    {
        $api = new FakeApi(['a' => true]);
        $cache = new ArrayCache();
        $this->client($api, cacheTtl: 0.0, cache: $cache)->isEnabled('a');

        $api->error = new \RuntimeException('down');
        self::assertTrue($this->client($api, cacheTtl: 0.0, cache: $cache)->isEnabled('a'));
    }

    public function testABrokenCacheDoesNotBreakReads(): void
    {
        $cache = new ArrayCache();
        $cache->broken = true;
        self::assertTrue($this->client(new FakeApi(['a' => true]), cache: $cache)->isEnabled('a'));
    }

    public function testFallsBackToTheLastKnownValues(): void
    {
        $api = new FakeApi(['a' => true]);
        $seen = [];
        $flags = $this->client($api, cacheTtl: 0.0, onError: function (ShipSwitchException $e) use (&$seen) {
            $seen[] = $e->reason;
        });
        self::assertTrue($flags->isEnabled('a'));

        $api->error = new \RuntimeException('down');
        self::assertTrue($flags->isEnabled('a'));
        self::assertSame([ShipSwitchException::NETWORK], $seen);
    }

    public function testFallsBackToTheDefaultWithoutKnownValues(): void
    {
        $flags = $this->client(new FakeApi(error: new \RuntimeException('down')));
        self::assertFalse($flags->isEnabled('a'));
        self::assertSame(20, $flags->getValue('limit', 20));
        self::assertSame([], $flags->getAllFlags());
    }

    public static function failures(): array
    {
        return [
            'timeout' => [new FakeApi(error: new ShipSwitchException(ShipSwitchException::TIMEOUT, 'slow')), 'TIMEOUT', null],
            'network' => [new FakeApi(error: new \RuntimeException('no route')), 'NETWORK', null],
            '401' => [new FakeApi(status: 401, body: '{}'), 'UNAUTHORIZED', 401],
            '403' => [new FakeApi(status: 403, body: '{}'), 'UNAUTHORIZED', 403],
            '500' => [new FakeApi(status: 500, body: 'oops'), 'HTTP', 500],
            '429' => [new FakeApi(status: 429, body: '{}'), 'HTTP', 429],
            'not json' => [new FakeApi(body: '<html>'), 'INVALID_RESPONSE', null],
            'flags as a list' => [new FakeApi(body: '{"flags": [1, 2]}'), 'INVALID_RESPONSE', null],
            'no flags' => [new FakeApi(body: '{}'), 'INVALID_RESPONSE', null],
        ];
    }

    #[DataProvider('failures')]
    public function testNamesWhatWentWrong(FakeApi $api, string $reason, ?int $status): void
    {
        try {
            $this->client($api)->refresh();
            self::fail('refresh() should have thrown');
        } catch (ShipSwitchException $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame($status, $error->status);
        }
    }

    public function testAFailingErrorHandlerDoesNotBreakReads(): void
    {
        $flags = $this->client(
            new FakeApi(error: new \RuntimeException('down')),
            onError: fn () => throw new \LogicException('handler bug'),
        );
        self::assertFalse($flags->isEnabled('a'));
    }

    public function testReportsAnErrorWithTheFlagAndTheUser(): void
    {
        $api = new FakeApi();
        $this->client($api)->reportError('new-checkout', ['userId' => 'u1', 'plan' => null]);
        self::assertSame('https://flags.test/api/v1/client/errors', $api->calls[0]['url']);
        self::assertSame(
            ['flagKey' => 'new-checkout', 'context' => ['userId' => 'u1']],
            $api->calls[0]['body'],
        );
    }

    public function testReportingNeverThrows(): void
    {
        $seen = [];
        $flags = $this->client(
            new FakeApi(error: new \RuntimeException('down')),
            onError: function (ShipSwitchException $e) use (&$seen) {
                $seen[] = $e->reason;
            },
        );
        $flags->reportError('new-checkout', ['userId' => 'u1']);
        self::assertSame(['NETWORK'], $seen);
    }
}
