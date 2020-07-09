# Throttle

[![Build Status](https://img.shields.io/travis/UseMuffin/Throttle/master.svg?style=flat-square)](https://travis-ci.org/UseMuffin/Throttle)
[![Coverage Status](https://img.shields.io/codecov/c/github/UseMuffin/Throttle.svg?style=flat-square)](https://codecov.io/github/UseMuffin/Throttle)
[![Total Downloads](https://img.shields.io/packagist/dt/muffin/throttle.svg?style=flat-square)](https://packagist.org/packages/muffin/throttle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

(API) Rate limiting requests in CakePHP

This plugin allows you to limit the number of requests a client can make to your
app in a given time frame.

## Requirements

- CakePHP cache engine with support for atomic updates

## Installation

```bash
composer require muffin/throttle
```
To make your application load the plugin either run:

```bash
./bin/cake plugin load Muffin/Throttle
```

or add the following line to `src/Application.php`:

```php
$this->addPlugin('Muffin/Throttle');
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

**Note:** This plugin will **NOT** work when using the `File` cache engine as it
does not support atomic increment.

### Using the Middleware

Include the middleware in inside of the Application.php:

```php
use Muffin\Throttle\Middleware\ThrottleMiddleware;
use Psr\Http\Message\ServerRequestInterface;
```

Add the middleware to the stack and pass your custom configuration:

```php
public function middleware($middleware)
{
    // Various other middlewares for error handling, routing etc. added here.

    $throttleMiddleware = new ThrottleMiddleware([
        'response' => [
            'body' => 'Rate limit exceeded',
        ],
        'interval' => '+1 hour',
        'limit' => 300,
        'identifier' => function (ServerRequestInterface $request) {
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
    'reset' => 'X-MyRateLimit-Reset',
]
```

To disable the headers set `headers` key to `false`.

### Customize requests weight

You may use `requestWeight` configuration key, if you want to account for some requests your way.
For example limiting number of form submits, but allow fetching the resource indefinitely

In this example, we keep limit of 300 requests / minute for every named route, and the same limit for all unnamed routes.
If the request is POST/PUT/PATCH we account that request as 100 standard requests, allowing effective maximum of 3 requests of POST/PUT/PATCH a minute
to each named route / group of unnamed routes

In the implementation you can use into account also session info, if present, allowing for different throttling for different
user groups

```php
new \Muffin\Throttle\Middleware\ThrottleMiddleware([
    'interval' => '+1 minute',
    'limit' => 300,
    'identifier' => function (ServerRequestInterface $request) {
        $identifier = $request->getHeader('HTTP_X_FORWARDED_FOR') ?? $request->getHeader('REMOTE_ADDR');
        if ($request instanceof ServerRequest) {
            // use remote requester IP as identifier
            $identifier = $request->clientIp();

            $parsedRequest = Router::parseRequest($request);
            if (!empty($parsedRequest['_name'])) {
                // if current route is named, apply limits separately to each named route
                $identifier .= $parsedRequest['_name'];
            }
        }
        return $identifier;
    },
    'requestWeight' => function (ServerRequestInterface $request) {
        if ($request instanceof \Cake\Http\ServerRequest) {
            if ($request->is(['post', 'put', 'patch'])) {
                // each named route or all unnamed routes toghether will be limited to
                // 3 POST/PUT/PATCH submits each minute
                return 100;
            }
        }
        // by default every request will decrement the limit by 1
        return 1;
    }
]);
```

### Customize response object

You may use `type` and `headers` subkeys of the `response` array (as you would do with a `Response` object) if you want to return a different message as the default one:

```php
new ThrottleMiddleware([
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
