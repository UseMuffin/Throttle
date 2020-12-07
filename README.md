# Throttle

[![Build Status](https://img.shields.io/github/workflow/status/UseMuffin/Throttle/CI/master?style=flat-square)](https://github.com/UseMuffin/Throttle/actions?query=workflow%3ACI+branch%3Amaster)
[![Coverage Status](https://img.shields.io/codecov/c/github/UseMuffin/Throttle.svg?style=flat-square)](https://codecov.io/github/UseMuffin/Throttle)
[![Total Downloads](https://img.shields.io/packagist/dt/muffin/throttle.svg?style=flat-square)](https://packagist.org/packages/muffin/throttle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

(API) Rate limiting requests in CakePHP

This plugin allows you to limit the number of requests a client can make to your
app in a given time frame.

## Installation

```bash
composer require muffin/throttle
```
To make your application load the plugin either run:

```bash
./bin/cake plugin load Muffin/Throttle
```

## Configuration

In your `config/app.php` add a cache config named `throttle` under the `Cache` key
with required config. For e.g.:

```php
'throttle' => [
    'className' => 'Apcu',
    'prefix' => 'throttle_'
],
```

### Using the Middleware

Add the middleware to the queue and pass your custom configuration:

```php
public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
{
    // Various other middlewares for error handling, routing etc. added here.

    $throttleMiddleware = new \Muffin\Throttle\Middleware\ThrottleMiddleware([
        // Data used to generate response with HTTP code 429 when limit is exceeded.
        'response' => [
            'body' => 'Rate limit exceeded',
        ],
        // Time period as number of seconds
        'period' => 60,
        // Number of requests allowed within the above time period
        'limit' => 100,
        // Client identifier
        'identifier' => function ($request) {
            if (!empty($request->getHeaderLine('Authorization'))) {
                return str_replace('Bearer ', '', $request->getHeaderLine('Authorization'));
            }

            return $request->clientIp();
        }
    ]);

    $middlewareQueue->add($throttleMiddleware);

    return $middlewareQueue;
}
```

The above example would allow 100 requests/minute/token and would first try to
identify the client by JWT Bearer token before falling back to (Throttle default)
IP address based identification.

### Events

The middleware also dispatches following event which effectively allows you to
have multiple rate limits:

#### `Throttle.beforeThrottle`

This is the first event that is triggered before a request is processed by the
middleware. All rate limiting process will be bypassed if this event is stopped.

```php
\Cake\Event\EventManager::instance()->on(
    \Muffin\Throttle\Middleware\ThrottleMiddleware::EVENT_BEFORE_THROTTLE,
    function ($event, $request) {
        if (/* check for something here, most likely using $request */) {
            $event->stopPropogation();
        }
    }
);
```

#### `Throttle.getIdentifier`

Instead of using the `indentifer` config you can also setup a listener for the
`Throttle.getIdentifier` event. The event's callback would receive a request
instance as argument and must return an identifier string.

#### `Throttle.getThrottleInfo`

The `Throttle.getThrottleInfo` event allows you to customize the `period` and `limit`
configs for a request as well as the cache key used to store the rate limiting info.

This allows you to set multiple rate limit as per your app's needs.

Here's an example:

```php
\Cake\Event\EventManager::instance()->on(
    \Muffin\Throttle\Middleware\ThrottleMiddleware::EVENT_GET_THROTTLE_INFO,
    function ($event, $request, \Muffin\Throttle\ValueObject\ThrottleInfo $throttle) {
        // Set a different period for POST request.
        if ($request->is('POST')) {
            // This will change the cache key from default "{identifer}" to "{identifer}.post".
            $throttle->appendToKey('post');
            $throttle->setPeriod(30);
        }

        // Modify limit for logged in user
        $identity = $request->getAttribute('identity');
        if ($identity) {
            $throttle->appendToKey($identity->get('role'));
            $throttle->setLimit(200);
        }
    }
);
```

#### Throtttle.beforeCacheSet

The `Throtttle.beforeCacheSet` event allows you to observe result of middleware configuration and previous
`Throtttle.getIdentifier` and `Throttle.getThrottleInfo` events results.

You can also use this event to modify cached `$rateLimit` and `$ttl` values,
modifying `$throttleInfo` in this event has no effect.

Example:

```php
\Cake\Event\EventManager::instance()->on(
    \Muffin\Throttle\Middleware\ThrottleMiddleware::EVENT_BEFORE_CACHE_SET,
    function ($event, \Muffin\Throttle\ValueObject\RateLimitInfo $rateLimit, int $ttl, \Muffin\Throttle\ValueObject\ThrottleInfo $throttleInfo) {
        \Cake\Log\Log::debug(sprintf("key(%s) remaining(%d) resetTimestamp(%d) ttl(%d)", $throttleInfo->getKey(), $rateLimit->getRemaining(), $rateLimit->getResetTimestamp(), $ttl));
    }
);
```

### X-headers

By default Throttle will add X-headers with rate limiting information to all responses:

```
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 7
X-RateLimit-Reset: 1438434161
```

To customize the header names simply pass (all of them) under `headers` key in
your configuration array:

```php
'headers' => [
    'limit' => 'X-MyRateLimit-Limit',
    'remaining' => 'X-MyRateLimit-Remaining',
    'reset' => 'X-MyRateLimit-Reset',
]
```

To disable the headers set `headers` key to `false`.

### Customize response object

You may use `type` and `headers` subkeys of the `response` array (as you would do
with a `Response` object) if you want to return a different message as the default one:

```php
new \Muffin\Throttle\Middleware\ThrottleMiddleware([
    'response' => [
        'body' => json_encode(['error' => 'Rate limit exceeded']),
        'type' => 'json',
        'headers' => [
            'Custom-Header' => 'custom_value',
        ]
    ],
    'limit' => 300,
]);
```

## Patches & Features

* Fork
* Mod, fix
* Test - this is important, so it's not unintentionally broken
* Commit - do not mess with license, todo, version, etc. (if you do change any, bump them into commits of
their own that I can ignore when I pull)
* Pull request - bonus point for topic branches

To ensure your PRs are considered for upstream, you MUST follow the CakePHP coding standards.

## Bugs & Feedback

http://github.com/usemuffin/throttle/issues

## License

Copyright (c) 2015-Present, [Use Muffin] and licensed under [The MIT License][mit].

[cakephp]:http://cakephp.org
[composer]:http://getcomposer.org
[mit]:http://www.opensource.org/licenses/mit-license.php
[muffin]:http://usemuffin.com
