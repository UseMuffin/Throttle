# Throttle

[![Build Status](https://img.shields.io/travis/UseMuffin/Throttle/master.svg?style=flat-square)](https://travis-ci.org/UseMuffin/Throttle)
[![Coverage](https://img.shields.io/coveralls/UseMuffin/Throttle/master.svg?style=flat-square)](https://coveralls.io/r/UseMuffin/Throttle)
[![Total Downloads](https://img.shields.io/packagist/dt/muffin/throttle.svg?style=flat-square)](https://packagist.org/packages/muffin/throttle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

(API) Rate limiting requests in CakePHP 3

## Requirements

- CakePHP 3.0+
- CakePHP cache engine with support for atomic updates

> Please note that this plugin will **not** work when using the default CakePHP
> File Storage cache engine.

## Installation

```
composer require muffin/throttle:dev-master
```
To make your application load the plugin either run:

```bash
./bin/cake plugin load Muffin/Throttle
```

or add the following line to ``config/bootstrap.php``:

```php
Plugin::load('Muffin/Throttle');
```

## Configuration

In `bootstrap.php`:

Include the class namespace:

```php
use Cake\Routing\DispatcherFactory;
```

Add a configuration:

```php
DispatcherFactory::add('Muffin/Throttle.Throttle');
```

This will use the defaults, 10 requests by minute for any given IP. You could
easily change that by passing your own configuration:

```php
DispatcherFactory::add('Muffin/Throttle.Throttle', [
    'message' => 'Rate limit exceeded',
    'interval' => '+1 hour',
    'limit' => 300,
    'identifier' => function (Request $request) {
        if (null !== $request->header('Authorization')) {
            return str_replace('Bearer ', '', $request->header('Authorization'));
        }
        return $request->clientIp();
    }
]);
```

The above example would allow 300 requests/hour/token and would first try to
identify the client by JWT Bearer token before falling back to
(Throttle default) IP address based identification.

### X-headers

By default Throttle will add X-headers with rate limiting information
to all responses:

```
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 7
X-RateLimit-Reset: 1438434161
```

To customize the header names simply pass (all of them) to your configuration:

```php
DispatcherFactory::add('Muffin/Throttle.Throttle', [
    'headers' => [
        'limit' => 'X-MyRateLimit-Limit',
        'remaining' => 'X-MyRateLimit-Remaining',
        'reset' => 'X-MyRateLimit-Reset'
    ]
]);
```

To disable the headers pass ``false``:

```php
DispatcherFactory::add('Muffin/Throttle.Throttle', [
    'headers' => false
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

Copyright (c) 2015, [Use Muffin] and licensed under [The MIT License][mit].

[cakephp]:http://cakephp.org
[composer]:http://getcomposer.org
[mit]:http://www.opensource.org/licenses/mit-license.php
[muffin]:http://usemuffin.com

