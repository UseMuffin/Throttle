# Throttle

[![Build Status](https://img.shields.io/travis/UseMuffin/Throttle/master.svg?style=flat-square)](https://travis-ci.org/UseMuffin/Throttle)
[![Coverage](https://img.shields.io/coveralls/UseMuffin/Throttle/master.svg?style=flat-square)](https://coveralls.io/r/UseMuffin/Throttle)
[![Total Downloads](https://img.shields.io/packagist/dt/muffin/throttle.svg?style=flat-square)](https://packagist.org/packages/muffin/throttle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

(API) Rate limiting requests in CakePHP 3

## Install

Using [Composer][composer]:

```
composer require muffin/throttle:dev-master
```

You then need to load the plugin. In `boostrap.php`, something like:

```php
\Cake\Core\Plugin::load('Muffin/Throttle');
```

## Usage

In `bootstrap.php`:

```php
DispatcherFactory::add('Muffin/Throttle.Throttle');
```

This will use the defaults, 10 requests by minute for an given IP. You could
easily change that by passing your own configuration:

```php
DispatcherFactory::add('Muffin/Throttle.Throttle', [
    'interval' => '+1 hour',
    'rate' => 300,
    'identifier' => function (Request $request) {
        return str_replace('Bearer ', '', $request->header('Authorization'));
    }
]);
```

The above example would allow 300 requests/hour/token.

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