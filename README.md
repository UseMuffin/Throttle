# Throttle

[![Build Status](https://img.shields.io/travis/UseMuffin/Throttle/master.svg?style=flat-square)](https://travis-ci.org/UseMuffin/Throttle)
[![Coverage Status](https://img.shields.io/codecov/c/github/UseMuffin/Throttle.svg?style=flat-square)](https://codecov.io/github/UseMuffin/Throttle)
[![Total Downloads](https://img.shields.io/packagist/dt/muffin/throttle.svg?style=flat-square)](https://packagist.org/packages/muffin/throttle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

(API) Rate limiting requests in CakePHP 3

This plugin allows you to limit the number of requests a client can make to your
app in a given time frame.

## Requirements

- CakePHP 3.0+
- CakePHP cache engine with support for atomic updates

## Installation

```bash
composer require muffin/throttle
```
To make your application load the plugin either run:

```bash
./bin/cake plugin load Muffin/Throttle
```

or add the following line to `config/bootstrap.php`:

```php
Plugin::load('Muffin/Throttle');
```

## Configuration

In your `config/app.php` add a cache config named `throttle` under the `Cache` key
with required config. For e.g.:

```php
'throttle' => [
    'className' => 'Apc',
    'prefix' => 'throttle_'
],
```

**Note:** This plugin will **NOT** work when using the `File` cache engine as it
does not support atomic increment.

### Using the Middleware

**Note:** This requires Cakephp version 3.4 or greater.

Include the middleware in inside of the Application.php:

```php
use Muffin\Throttle\Middleware\ThrottleMiddleware;
```

Add the middleware to the stack and pass your custom configuration:

```php
public function middleware($middleware)
{
    // Various other middlewares for error handling, routing etc. added here.

    $throttleMiddleware = new ThrottleMiddleware([
        'response' => [
            'body' => 'Rate limit exceeded'
        ],
        'interval' => '+1 hour',
        'limit' => 300,
        'identifier' => function (ServerRequestInterface $request) {
            if (null !== $request->getHeaderLine('Authorization')) {
                return str_replace('Bearer ', '', $request->getHeaderLine('Authorization'));
            }
            return $request->clientIp();
        }
    ]);

    $middlewareQueue->add($throttleMiddleware);

    return $middlewareQueue;
}
```

The above example would allow 300 requests/hour/token and would first try to
identify the client by JWT Bearer token before falling back to (Throttle default)
IP address based identification.

### X-headers

By default Throttle will add X-headers with rate limiting information
to all responses:

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
    'reset' => 'X-MyRateLimit-Reset'
]
```

To disable the headers set `headers` key to `false`.

### Customize response object

You may use `type` and `headers` subkeys of the `response` array (as you would do with a `Response` object) if you want to return a different message as the default one:

```php
new ThrottleMiddleware([
    'response' => [
        'body' => json_encode(['error' => 'Rate limit exceeded']),
        'type' => 'json',
        'headers' => [
            'Custom-Header' => 'custom_value'
        ]
    ],
    'limit' => 300
]);
```

### Using the Dispatch Filter

In `bootstrap.php`:

Include the class namespace:

```php
use Cake\Routing\DispatcherFactory;
```

Add a configuration:

```php
DispatcherFactory::add('Muffin/Throttle.Throttle');
```

This will use the defaults, 10 requests per minute for any given IP. You could
easily change that by passing your own configuration:

```php
DispatcherFactory::add('Muffin/Throttle.Throttle', [
    'response' => [
        'body' => 'Rate limit exceeded'
    ],
    'interval' => '+1 hour',
    'limit' => 300,
    'identifier' => function (Request $request) {
        if (null !== $request->getHeaderLine('Authorization')) {
            return str_replace('Bearer ', '', $request->getHeaderLine('Authorization'));
        }
        return $request->clientIp();
    }
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

Copyright (c) 2015-2017, [Use Muffin] and licensed under [The MIT License][mit].

[cakephp]:http://cakephp.org
[composer]:http://getcomposer.org
[mit]:http://www.opensource.org/licenses/mit-license.php
[muffin]:http://usemuffin.com
