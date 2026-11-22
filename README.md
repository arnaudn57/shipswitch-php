# shipswitch/shipswitch (PHP)

PHP client for [ShipSwitch](https://shipswitch.io) feature flags. No
dependencies; PHP 8.1+. Uses cURL when the extension is there, PHP streams
otherwise.

```bash
composer require shipswitch/shipswitch
```

## Usage

```php
use ShipSwitch\ShipSwitch;

$flags = new ShipSwitch(getenv('SHIPSWITCH_KEY'));

$user = ['userId' => 'user_123', 'plan' => 'premium'];

// A boolean flag. False when the flag is off, unknown or unreachable.
if ($flags->isEnabled('new-checkout', $user)) {
    renderNewCheckout();
}

// Any other flag, with the value to use when it cannot be read.
$limit = $flags->getValue('max-items', 20, $user);

// Every flag of the environment, for this user.
$everything = $flags->getAllFlags($user);
```

A read never throws. When ShipSwitch cannot be reached, it returns the last
value it knew, then the default you gave.

## Share values between requests

A PHP process usually serves one request, so without a cache every request
asks ShipSwitch once per user. Pass any PSR-16 cache to keep values for
`cacheTtl` seconds across requests, and to have a last known value during
an outage:

```php
// Laravel
$flags = new ShipSwitch(config('services.shipswitch.key'), cache: Cache::store());

// Symfony
$flags = new ShipSwitch($key, cache: new Psr16Cache($cachePool));
```

## The user context

`userId` puts a user on the same side of a percentage rollout every time;
without it, a flag opened to less than 100% is off. The other attributes are
what your targeting rules compare. Attributes set to `null` are not sent.

## Options

```php
new ShipSwitch(
    apiKey: $key,
    baseUrl: 'https://shipswitch.io', // your own installation, if any
    timeout: 3.0,     // seconds to wait for the API
    cacheTtl: 30.0,   // seconds during which values are reused
    cache: $psr16,    // optional, see above
    onError: $log,    // called with a ShipSwitchException when a read fails
);
```

`$flags->refresh($context)` fetches again right away and, unlike the reads,
throws a `ShipSwitchException` on failure. Its `reason` is `TIMEOUT`,
`NETWORK`, `UNAUTHORIZED`, `HTTP` or `INVALID_RESPONSE`. Without the cURL
extension, a timeout is reported as `NETWORK`.

## Reporting errors to the autopilot

During an automatic rollout, ShipSwitch turns a flag off when too many of
the users who have it hit errors. Tell it when that happens:

```php
try {
    payWithNewCheckout($order);
} catch (\Throwable $error) {
    $flags->reportError('new-checkout', ['userId' => $user->id]);
    throw $error;
}
```

## Tests

```bash
composer install
composer test
```
