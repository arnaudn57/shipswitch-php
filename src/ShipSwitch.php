<?php

declare(strict_types=1);

namespace ShipSwitch;

/**
 * Client of the ShipSwitch evaluation API.
 *
 * Flags are evaluated by the server: the client sends the user context and
 * receives values. It never lets a failure reach the application: when the
 * API cannot be reached, reads fall back to the last known values, then to
 * the defaults given by the caller.
 *
 * Values are kept for `cacheTtl` seconds. Without a cache they only live as
 * long as the PHP process, which for a web request means one call to the
 * API per user and per request. Pass any PSR-16 cache (Laravel's
 * `Cache::store()`, a Symfony `Psr16Cache`, APCu...) to share them between
 * requests:
 *
 *     $flags = new ShipSwitch(getenv('SHIPSWITCH_KEY'), cache: $cache);
 *
 *     if ($flags->isEnabled('new-checkout', ['userId' => $user->id])) {
 *         renderNewCheckout();
 *     }
 */
final class ShipSwitch
{
    /** The hosted ShipSwitch. Pass `baseUrl` to use an installation of your own. */
    public const DEFAULT_BASE_URL = 'https://shipswitch.io';

    /** How long a shared cache keeps values that are past `cacheTtl`, as a fallback. */
    private const STALE_SECONDS = 86400;

    private readonly string $endpoint;
    private readonly string $errorsEndpoint;
    /** @var \Closure(string, array<string, string>, string, float): array{0: int, 1: string} */
    private readonly \Closure $transport;
    private readonly ?\Closure $onError;
    /** @var array<string, array{flags: array<string, mixed>, at: float}> */
    private array $memory = [];

    /**
     * @param string        $apiKey   A key of the environment to read.
     * @param float         $timeout  Seconds to wait for the API.
     * @param float         $cacheTtl Seconds during which values are reused.
     * @param object|null   $cache    A PSR-16 cache (`get` and `set` methods).
     * @param callable|null $onError  Called with a ShipSwitchException when a read fails.
     * @param callable|null $transport Sends a POST request, for tests:
     *                                 fn(string $url, array $headers, string $body, float $timeout): array{int, string}
     */
    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 3.0,
        private readonly float $cacheTtl = 30.0,
        private readonly ?object $cache = null,
        ?callable $onError = null,
        ?callable $transport = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('ShipSwitch: `apiKey` is required');
        }
        $base = rtrim($baseUrl, '/') . '/api/v1/client';
        $this->endpoint = $base . '/evaluate';
        $this->errorsEndpoint = $base . '/errors';
        $this->onError = $onError === null ? null : $onError(...);
        $this->transport = ($transport ?? self::defaultTransport())(...);
    }

    /**
     * Whether a boolean flag is on for this user. Returns `$default` when the
     * flag does not exist, is not a boolean, or could not be fetched.
     *
     * @param array<string, scalar|null> $context
     */
    public function isEnabled(string $key, array $context = [], bool $default = false): bool
    {
        return $this->getValue($key, $default, $context);
    }

    /**
     * Value of a flag for this user. Returns `$default` when the flag does
     * not exist, could not be fetched, or holds a value of another type than
     * the default's.
     *
     * @template T
     * @param T $default
     * @param array<string, scalar|null> $context
     * @return T
     */
    public function getValue(string $key, mixed $default, array $context = []): mixed
    {
        $flags = $this->getAllFlags($context);
        if (!array_key_exists($key, $flags)) {
            return $default;
        }
        return self::sameKind($flags[$key], $default) ? $flags[$key] : $default;
    }

    /**
     * Every flag of the environment for this user. Returns the last known
     * values when the API cannot be reached, and an empty array when there
     * are none.
     *
     * @param array<string, scalar|null> $context
     * @return array<string, mixed>
     */
    public function getAllFlags(array $context = []): array
    {
        $context = self::clean($context);
        $cacheKey = self::keyOf($context);
        $cached = $this->read($cacheKey);
        if ($cached !== null && microtime(true) - $cached['at'] < $this->cacheTtl) {
            return $cached['flags'];
        }
        try {
            return $this->load($cacheKey, $context);
        } catch (ShipSwitchException $error) {
            $this->report($error);
            return $cached['flags'] ?? [];
        }
    }

    /**
     * Fetches the flags of a context again, ignoring the cache. Unlike the
     * read methods, it throws a ShipSwitchException on failure, so that the
     * caller knows whether the values are current.
     *
     * @param array<string, scalar|null> $context
     * @return array<string, mixed>
     */
    public function refresh(array $context = []): array
    {
        $context = self::clean($context);
        return $this->load(self::keyOf($context), $context);
    }

    /**
     * Tells ShipSwitch that something failed for this user in the feature
     * behind a flag. While the flag is under automatic rollout, enough of
     * these turn it off before more users are affected; otherwise the report
     * is ignored. Like the read methods, it never throws.
     *
     * @param array<string, scalar|null> $context
     */
    public function reportError(string $key, array $context): void
    {
        try {
            $this->post($this->errorsEndpoint, [
                'flagKey' => $key,
                'context' => (object) self::clean($context),
            ]);
        } catch (ShipSwitchException $error) {
            $this->report($error);
        }
    }

    /** Forgets the values this object remembers. A shared cache is left alone. */
    public function clearCache(): void
    {
        $this->memory = [];
    }

    /**
     * @param array<string, scalar> $context
     * @return array<string, mixed>
     */
    private function load(string $cacheKey, array $context): array
    {
        $body = $this->post($this->endpoint, ['context' => (object) $context]);
        $flags = is_array($body) ? ($body['flags'] ?? null) : null;
        // An empty JSON object and an empty JSON list both decode to [].
        if (!is_array($flags) || ($flags !== [] && array_is_list($flags))) {
            throw new ShipSwitchException(
                ShipSwitchException::INVALID_RESPONSE,
                'ShipSwitch answered without a flag map',
            );
        }
        $entry = ['flags' => $flags, 'at' => microtime(true)];
        $this->memory[$cacheKey] = $entry;
        if ($this->cache !== null) {
            try {
                $this->cache->set($cacheKey, $entry, (int) ceil($this->cacheTtl) + self::STALE_SECONDS);
            } catch (\Throwable) {
                // A cache that is down must not break flag reads.
            }
        }
        return $flags;
    }

    /** @return array{flags: array<string, mixed>, at: float}|null */
    private function read(string $cacheKey): ?array
    {
        if (isset($this->memory[$cacheKey])) {
            return $this->memory[$cacheKey];
        }
        if ($this->cache === null) {
            return null;
        }
        try {
            $entry = $this->cache->get($cacheKey);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($entry) || !is_array($entry['flags'] ?? null) || !is_float($entry['at'] ?? null)) {
            return null;
        }
        return $this->memory[$cacheKey] = $entry;
    }

    /** Sends a request to the API; returns its decoded JSON body, if it has one. */
    private function post(string $url, array $payload): mixed
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => 'shipswitch-php',
        ];
        try {
            [$status, $body] = ($this->transport)(
                $url,
                $headers,
                json_encode($payload, JSON_THROW_ON_ERROR),
                $this->timeout,
            );
        } catch (ShipSwitchException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ShipSwitchException(
                ShipSwitchException::NETWORK,
                'ShipSwitch could not be reached',
                null,
                $error,
            );
        }

        if ($status === 401 || $status === 403) {
            throw new ShipSwitchException(
                ShipSwitchException::UNAUTHORIZED,
                'ShipSwitch refused the API key: check that it is valid and not revoked',
                $status,
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new ShipSwitchException(
                ShipSwitchException::HTTP,
                "ShipSwitch answered with status $status",
                $status,
            );
        }
        if ($status === 204 || $body === '') {
            return null;
        }
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ShipSwitchException(
                ShipSwitchException::INVALID_RESPONSE,
                'ShipSwitch answered with invalid JSON',
                null,
                $error,
            );
        }
    }

    private function report(ShipSwitchException $error): void
    {
        if ($this->onError === null) {
            return;
        }
        try {
            ($this->onError)($error);
        } catch (\Throwable) {
            // A failing error handler must not break flag reads either.
        }
    }

    /** cURL when the extension is there, PHP streams otherwise. */
    private static function defaultTransport(): callable
    {
        if (function_exists('curl_init')) {
            return static function (string $url, array $headers, string $body, float $timeout): array {
                $lines = [];
                foreach ($headers as $name => $value) {
                    $lines[] = "$name: $value";
                }
                $handle = curl_init($url);
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
                    CURLOPT_CONNECTTIMEOUT_MS => (int) round($timeout * 1000),
                ]);
                $answer = curl_exec($handle);
                if ($answer === false) {
                    $timedOut = curl_errno($handle) === CURLE_OPERATION_TIMEDOUT;
                    $message = curl_error($handle);
                    curl_close($handle);
                    throw new ShipSwitchException(
                        $timedOut ? ShipSwitchException::TIMEOUT : ShipSwitchException::NETWORK,
                        $timedOut
                            ? "ShipSwitch did not answer within $timeout s"
                            : "ShipSwitch could not be reached: $message",
                    );
                }
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                curl_close($handle);
                return [$status, (string) $answer];
            };
        }

        return static function (string $url, array $headers, string $body, float $timeout): array {
            $lines = [];
            foreach ($headers as $name => $value) {
                $lines[] = "$name: $value";
            }
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ]]);
            $answer = @file_get_contents($url, false, $context);
            // Set by PHP in the calling scope once the request is answered.
            $responseHeaders = $http_response_header ?? [];
            if ($answer === false || $responseHeaders === []) {
                throw new ShipSwitchException(
                    ShipSwitchException::NETWORK,
                    'ShipSwitch could not be reached',
                );
            }
            preg_match('{^HTTP/\S+\s+(\d{3})}', $responseHeaders[0], $match);
            return [(int) ($match[1] ?? 0), $answer];
        };
    }

    /**
     * Drops attributes without a value, which carry no information.
     *
     * @param array<string, scalar|null> $context
     * @return array<string, scalar>
     */
    private static function clean(array $context): array
    {
        return array_filter($context, static fn ($value) => $value !== null);
    }

    /** Cache key of a context: the same attributes in any order give the same key. */
    private static function keyOf(array $context): string
    {
        ksort($context);
        // Hashed: PSR-16 keys cannot hold characters such as {}()/\@:
        return 'shipswitch.' . hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
    }

    /** Whether a served value can stand in for a default of this shape. */
    private static function sameKind(mixed $value, mixed $reference): bool
    {
        if ($reference === null) {
            return true;
        }
        if ($value === null) {
            return false;
        }
        if (is_bool($reference)) {
            return is_bool($value);
        }
        if (is_int($reference) || is_float($reference)) {
            return is_int($value) || is_float($value);
        }
        if (is_string($reference)) {
            return is_string($value);
        }
        // JSON objects and lists both arrive as PHP arrays.
        return is_array($reference) && is_array($value);
    }
}
